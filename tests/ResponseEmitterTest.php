<?php

namespace Spawn\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use Spawn\Laravel\Server\ResponseEmitter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What DevServer writes to the client socket for each kind of response.
 */
class ResponseEmitterTest extends TestCase
{
    private function emitted(Response $response): string
    {
        $socket = fopen('php://memory', 'w+');
        ResponseEmitter::emit($socket, $response);
        rewind($socket);

        return stream_get_contents($socket);
    }

    /**
     * @return array{0: string, 1: string}  head and body
     */
    private function split(string $raw): array
    {
        return explode("\r\n\r\n", $raw, 2);
    }

    public function test_a_response_with_content_carries_its_length(): void
    {
        [$head, $body] = $this->split($this->emitted(new Response('hello')));

        $this->assertSame('hello', $body);
        $this->assertStringContainsString("Content-Length: 5\r\n", $head);
    }

    public function test_a_streamed_response_carries_what_its_callback_writes(): void
    {
        $response = new StreamedResponse(function () {
            echo 'one,';
            flush();
            echo 'two';
        });

        [$head, $body] = $this->split($this->emitted($response));

        $this->assertSame('one,two', $body);
        $this->assertStringNotContainsString('Content-Length: 0', $head);
    }

    public function test_a_file_response_carries_the_file(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'emit');
        file_put_contents($file, str_repeat('x', 100000));

        try {
            [, $body] = $this->split($this->emitted(new BinaryFileResponse($file)));
        } finally {
            unlink($file);
        }

        $this->assertSame(100000, strlen($body));
    }
}
