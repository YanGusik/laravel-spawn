<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\TestCase;
use Spawn\Laravel\Server\TrueAsyncServer;
use Spawn\Laravel\Server\UploadedFiles;

/**
 * Pins the translation of the server extension's uploaded files into a Laravel request.
 *
 * The multipart bodies are parsed by the extension itself through TrueAsync\http_parse_request(),
 * so the objects under test are the ones a real request produces, and no server is started.
 */
class UploadedFilesTest extends TestCase
{
    private const BOUNDARY = '---b';

    /* The extension unlinks the temporary files when the native request is destroyed, so the
     * converted uploads are only readable while it is alive. Under the server that is the
     * handler's scope; here it is the test instance's, and PHPUnit builds one per method. */
    private ?object $nativeRequest = null;

    protected function setUp(): void
    {
        if (!function_exists('TrueAsync\http_parse_request')) {
            $this->markTestSkipped('The true_async_server extension is not loaded.');
        }
    }

    public function test_a_file_reaches_the_request_as_a_laravel_upload(): void
    {
        $request = $this->requestWith([
            ['name' => 'file', 'filename' => 'foo.txt', 'type' => 'text/plain', 'body' => 'hello'],
        ]);

        $file = $request->file('file');

        $this->assertInstanceOf(UploadedFile::class, $file);
        $this->assertTrue($request->hasFile('file'));
        $this->assertSame('foo.txt', $file->getClientOriginalName());
        $this->assertSame('text/plain', $file->getClientMimeType());
        $this->assertSame(UPLOAD_ERR_OK, $file->getError());
        $this->assertTrue($file->isValid());
        $this->assertSame(5, $file->getSize());
        $this->assertSame('hello', file_get_contents($file->getPathname()));
    }

    /**
     * The upload is wrapped where the extension wrote it, so nothing is copied on the way in.
     * A copy would read and write every byte again, and leave a second file nothing unlinks.
     */
    public function test_the_upload_is_not_copied(): void
    {
        $files = $this->convertedFiles([
            ['name' => 'file', 'filename' => 'foo.txt', 'type' => 'text/plain', 'body' => 'hello'],
        ]);

        $this->assertStringStartsWith('trueasync_upload_', basename($files['file']->getPathname()));
    }

    public function test_a_bracketed_field_becomes_a_list(): void
    {
        $request = $this->requestWith([
            ['name' => 'photos[]', 'filename' => 'a.png', 'type' => 'image/png', 'body' => 'AAA'],
            ['name' => 'photos[]', 'filename' => 'b.png', 'type' => 'image/png', 'body' => 'BBB'],
        ]);

        $photos = $request->file('photos');

        $this->assertIsArray($photos);
        $this->assertCount(2, $photos);
        $this->assertSame(['a.png', 'b.png'], array_map(fn ($p) => $p->getClientOriginalName(), $photos));
        $this->assertSame(['AAA', 'BBB'], array_map(fn ($p) => file_get_contents($p->getPathname()), $photos));
    }

    public function test_an_empty_file_field_is_absent(): void
    {
        $request = $this->requestWith([
            ['name' => 'empty', 'filename' => '', 'type' => 'application/octet-stream', 'body' => ''],
        ]);

        $this->assertNull($request->file('empty'));
        $this->assertFalse($request->hasFile('empty'));
    }

    /**
     * A filename with path components is saved under the part after the last separator, as PHP
     * saves it: the upload is valid and its name carries no directory.
     */
    public function test_a_filename_with_path_components_is_saved_under_its_last_part(): void
    {
        $request = $this->requestWith([
            ['name' => 'doc', 'filename' => '../../etc/passwd', 'type' => 'text/plain', 'body' => 'x'],
        ]);

        $file = $request->file('doc');

        $this->assertInstanceOf(UploadedFile::class, $file);
        $this->assertTrue($file->isValid());
        $this->assertSame(UPLOAD_ERR_OK, $file->getError());
        $this->assertSame('passwd', $file->getClientOriginalName());
        $this->assertTrue($request->hasFile('doc'));
    }

    /**
     * A filename the parser refuses arrives as an invalid upload rather than as a fatal error.
     */
    public function test_a_rejected_filename_yields_an_invalid_upload(): void
    {
        $request = $this->requestWith([
            ['name' => 'bad', 'filename' => str_repeat('a', 4097), 'type' => 'text/plain', 'body' => 'x'],
        ]);

        $file = $request->file('bad');

        $this->assertInstanceOf(UploadedFile::class, $file);
        $this->assertFalse($file->isValid());
        $this->assertSame(UPLOAD_ERR_EXTENSION, $file->getError());
        $this->assertFalse($request->hasFile('bad'));
    }

    /**
     * move() renames the extension's temporary file in place, and the request's cleanup then
     * unlinks the path it recorded, so the moved content has to survive that. `store()` takes
     * another route entirely: it streams a copy through the filesystem adapter.
     */
    public function test_a_stored_upload_keeps_its_content(): void
    {
        $request = $this->requestWith([
            ['name' => 'file', 'filename' => 'foo.txt', 'type' => 'text/plain', 'body' => 'hello'],
        ]);

        $directory = sys_get_temp_dir() . '/spawn-upload-test-' . getmypid();
        $moved     = $request->file('file')->move($directory, 'stored.txt');

        try {
            $this->assertSame('hello', file_get_contents($moved->getPathname()));
        } finally {
            @unlink($moved->getPathname());
            @rmdir($directory);
        }
    }

    /**
     * The request the server hands to the kernel carries the upload, instead of failing in the
     * file bag with "An uploaded file must be an array or an instance of UploadedFile."
     */
    public function test_the_server_builds_a_request_that_carries_the_upload(): void
    {
        $native  = $this->parse([
            ['name' => 'file', 'filename' => 'foo.txt', 'type' => 'text/plain', 'body' => 'hello'],
        ]);
        $convert = new \ReflectionMethod(TrueAsyncServer::class, 'convertRequest');

        $request = $convert->invoke(new TrueAsyncServer('', ''), $native);

        $this->assertSame('foo.txt', $request->file('file')->getClientOriginalName());
        $this->assertSame('hello', file_get_contents($request->file('file')->getPathname()));
    }

    /**
     * @param  list<array{name: string, filename: string, type: string, body: string}>  $parts
     */
    private function requestWith(array $parts): Request
    {
        return new Request([], [], [], [], $this->convertedFiles($parts), []);
    }

    /**
     * @param  list<array{name: string, filename: string, type: string, body: string}>  $parts
     * @return array<string, UploadedFile|list<UploadedFile>>
     */
    private function convertedFiles(array $parts): array
    {
        return UploadedFiles::convert($this->parse($parts)->getFiles());
    }

    /**
     * Builds a multipart body, parses it with the extension, and keeps the native request in
     * `$nativeRequest` so its temporary files outlive the call.
     *
     * @param  list<array{name: string, filename: string, type: string, body: string}>  $parts
     */
    private function parse(array $parts): object
    {
        $body = '';

        foreach ($parts as $part) {
            $disposition = 'form-data; name="' . $part['name'] . '"; filename="' . $part['filename'] . '"';

            $body .= '--' . self::BOUNDARY . "\r\n"
                . 'Content-Disposition: ' . $disposition . "\r\n"
                . 'Content-Type: ' . $part['type'] . "\r\n\r\n"
                . $part['body'] . "\r\n";
        }

        $body .= '--' . self::BOUNDARY . "--\r\n";

        $raw = "POST /upload HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . 'Content-Type: multipart/form-data; boundary=' . self::BOUNDARY . "\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n"
            . $body;

        $this->nativeRequest = \TrueAsync\http_parse_request($raw) ?: null;

        $this->assertNotNull($this->nativeRequest, 'The extension refused to parse the multipart body.');

        return $this->nativeRequest;
    }
}
