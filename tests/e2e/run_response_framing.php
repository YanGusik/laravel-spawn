<?php

/**
 * In-process response-framing end-to-end runner (TrueAsyncServer only).
 *
 * Everything here is about what leaves the socket: the length the response announces
 * against the bytes it carries, and whether a body that Symfony writes to standard
 * output arrives at all. DevServer cannot show either — it emits through
 * ResponseEmitter, which counts the body itself — so the checks run against a genuine
 * TrueAsync\HttpServer in a worker thread, the same shape as run_request_scope.php.
 *
 * Exits 0 if all scenarios pass, 1 otherwise. Run directly, or via ResponseFramingE2ETest.
 */

use Async\ThreadChannel;
use Spawn\Laravel\Server\TrueAsyncServer;

use function Async\spawn;
use function Async\spawn_thread;
use function Async\await;
use function Async\delay;

require __DIR__ . '/../../vendor/autoload.php';

$autoload  = __DIR__ . '/../../vendor/autoload.php';
$bootstrap = __DIR__ . '/../bench/bootstrap/app.php';
$host = '127.0.0.1';
$port = 8499;

$exitCode = 1;

$main = spawn(static function () use ($autoload, $bootstrap, $host, $port, &$exitCode) {
    $ready = new ThreadChannel(1);

    spawn_thread(static function () use ($ready, $autoload, $bootstrap, $host, $port) {
        try {
            // A fresh engine per worker thread: nothing loaded in the parent is visible.
            require $autoload;

            $app = require $bootstrap;
            $app->make(\Illuminate\Contracts\Http\Kernel::class)->bootstrap();

            $server = new TrueAsyncServer($autoload, $bootstrap, [
                'listeners' => [['host' => $host, 'port' => $port, 'tls' => false, 'protocol' => 'auto']],
                'workers'   => 1,
            ]);
            $ready->send('ok');
            $server->start();
        } catch (\Throwable $e) {
            $ready->send('ERR ' . $e::class . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        }
    });

    $signal = $ready->recv();
    if ($signal !== 'ok') {
        fwrite(STDERR, "server boot failed: {$signal}\n");
        return;
    }

    $pass = 0; $fail = 0;
    $check = static function (string $name, bool $ok) use (&$pass, &$fail): void {
        echo ($ok ? 'PASS' : 'FAIL') . " — {$name}\n";
        $ok ? $pass++ : $fail++;
    };

    /**
     * One request, read to EOF, taken apart the way a client sees it.
     *
     * @return array{status:int,headers:array<string,string>,body:string,framed:string}
     *         framed is 'chunked' or 'length'; body is what the framing yields, so a
     *         chunked answer is compared by its decoded bytes rather than its wire form.
     */
    $request = static function (string $method, string $path) use ($host, $port): array {
        $fp = false;
        for ($i = 0; $i < 40 && !$fp; $i++) {
            $fp = @stream_socket_client("tcp://{$host}:{$port}", $en, $es, 1);
            if (!$fp) { delay(50); }
        }

        if (!$fp) { return ['status' => 0, 'headers' => [], 'body' => 'CONNECT-FAIL', 'framed' => 'none']; }

        fwrite($fp, "{$method} {$path} HTTP/1.1\r\nHost: {$host}\r\nConnection: close\r\n\r\n");
        $raw = '';
        while (!feof($fp)) { $c = fread($fp, 8192); if ($c === false || $c === '') break; $raw .= $c; }
        fclose($fp);

        $split = strpos($raw, "\r\n\r\n");
        if ($split === false) { return ['status' => 0, 'headers' => [], 'body' => '', 'framed' => 'none']; }

        $head = substr($raw, 0, $split);
        $body = substr($raw, $split + 4);

        $headers = [];
        foreach (array_slice(explode("\r\n", $head), 1) as $line) {
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $headers[strtolower(substr($line, 0, $colon))] = trim(substr($line, $colon + 1));
            }
        }

        $framed = 'length';
        if (str_contains(strtolower($headers['transfer-encoding'] ?? ''), 'chunked')) {
            $framed  = 'chunked';
            $decoded = '';
            $at      = 0;
            while (true) {
                $eol = strpos($body, "\r\n", $at);
                if ($eol === false) { break; }
                $size = hexdec(explode(';', substr($body, $at, $eol - $at))[0]);
                if ($size === 0) { break; }
                $decoded .= substr($body, $eol + 2, $size);
                $at = $eol + 2 + $size + 2;
            }

            $body = $decoded;
        }

        return [
            'status'  => preg_match('#^HTTP/\d\.\d (\d+)#', $raw, $m) ? (int) $m[1] : 0,
            'headers' => $headers,
            'body'    => $body,
            'framed'  => $framed,
        ];
    };

    // A wrong Content-Length from the application must not become the framing of the
    // answer: the server writes five bytes, so five is what it has to announce.
    $stale = $request('GET', '/framing/stale-length');
    $check(
        'framing: an application Content-Length does not survive into the response ('
            . ($stale['headers']['content-length'] ?? 'absent') . ' for ' . strlen($stale['body']) . ' bytes)',
        $stale['status'] === 200
            && $stale['body'] === 'SHORT'
            && ($stale['headers']['content-length'] ?? null) === '5'
    );

    // HEAD is the exception. The body is dropped on purpose, and Laravel's number is
    // then the only description of what a GET would have returned.
    $head = $request('HEAD', '/framing/stale-length');
    $check(
        'framing: HEAD keeps the length the application set',
        $head['status'] === 200 && ($head['headers']['content-length'] ?? null) === '999999' && $head['body'] === ''
    );

    // getContent() answers false for a download, and the file has to arrive anyway.
    $download = $request('GET', '/framing/download');
    $check(
        'framing: a download carries the file (' . strlen($download['body']) . ' bytes)',
        $download['status'] === 200
            && strlen($download['body']) === 300000
            && $download['body'] === str_repeat('A', 300000)
    );

    $check(
        'framing: and keeps the disposition the application asked for',
        str_contains($download['headers']['content-disposition'] ?? '', 'probe.bin')
    );

    $stream = $request('GET', '/framing/stream');
    $check(
        'framing: a streamed body arrives whole, framed as chunked (' . strlen($stream['body']) . ' bytes, '
            . $stream['framed'] . ')',
        $stream['status'] === 200 && $stream['framed'] === 'chunked' && strlen($stream['body']) === 300000
    );

    // Nothing had been forwarded when the callback threw, so the request is still
    // answerable — as a failure, not as a short 200.
    $threw = $request('GET', '/framing/stream-throws');
    $check(
        'framing: a body callback that fails before the first chunk answers 500 (' . $threw['status'] . ')',
        $threw['status'] === 500
    );

    // PHP runs the forwarding handler on a discard too, handing it the dropped bytes.
    $discard = $request('GET', '/framing/discard');
    $check(
        'framing: bytes dropped by ob_clean() do not reach the client (' . $discard['body'] . ')',
        $discard['body'] === 'KEPT'
    );

    // What an exception says is for the log, not the client: a SQL error or a path in a 500
    // body is a disclosure. app.debug is off in the bench application.
    $early = $request('GET', '/framing/stream-fails-early');
    $check(
        'framing: a 500 the server answers itself does not carry the exception message (' . $early['body'] . ')',
        $early['status'] === 500 && !str_contains($early['body'], 'hunter2') && trim($early['body']) === 'Internal Server Error'
    );

    // flush() alone reaches no output handler; a full buffer has to leave before the wait ends.
    $fp = @stream_socket_client("tcp://{$host}:{$port}", $en, $es, 1);
    $firstByteAfter = null;
    if ($fp) {
        $sentAt = microtime(true);
        fwrite($fp, "GET /framing/flush-then-wait HTTP/1.1\r\nHost: {$host}\r\nConnection: close\r\n\r\n");
        $raw = '';
        while (!feof($fp)) {
            $c = fread($fp, 8192);
            if ($c === false || $c === '') { break; }
            $raw .= $c;
            $split = strpos($raw, "\r\n\r\n");
            if ($split !== false && str_contains(substr($raw, $split), 'FFFF')) {
                $firstByteAfter = microtime(true) - $sentAt;
                break;
            }
        }
        fclose($fp);
    }
    $check(
        'streaming: a full buffer of a stream reaches the client before the callback returns ('
            . ($firstByteAfter === null ? 'never' : round($firstByteAfter * 1000) . ' ms') . ')',
        $firstByteAfter !== null && $firstByteAfter < 1.0
    );

    $popOnce = $request('GET', '/framing/pop-once');
    $check(
        'streaming: a body that pops the forwarding buffer once still arrives (' . strlen($popOnce['body']) . ' bytes)',
        $popOnce['status'] === 200 && $popOnce['body'] === 'BODY-AFTER-POP'
    );

    $popThenBuffer = $request('GET', '/framing/pop-then-buffer');
    $check(
        'streaming: and so does one that pops it and opens its own buffer (' . strlen($popThenBuffer['body']) . ' bytes)',
        $popThenBuffer['status'] === 200 && $popThenBuffer['body'] === 'OWNTAIL'
    );

    // Nothing can put a buffer back once all are gone: the request fails rather than answering
    // an empty 200 and writing the body to the worker's standard output.
    $popAll = $request('GET', '/framing/pop-all');
    $check(
        'streaming: a body that pops every buffer answers 500 (' . $popAll['status'] . ')',
        $popAll['status'] === 500
    );

    // Chunked framing ends with a zero-length chunk; a body cut short must not get one.
    $rawOf = static function (string $path) use ($host, $port): string {
        $fp = @stream_socket_client("tcp://{$host}:{$port}", $en, $es, 1);
        if (!$fp) { return ''; }
        fwrite($fp, "GET {$path} HTTP/1.1\r\nHost: {$host}\r\nConnection: close\r\n\r\n");
        $raw = '';
        while (!feof($fp)) { $c = fread($fp, 8192); if ($c === false || $c === '') break; $raw .= $c; }
        fclose($fp);

        return $raw;
    };

    foreach (['/framing/stream-throws-late', '/framing/pop-all-late'] as $path) {
        $raw = $rawOf($path);
        $check(
            "streaming: {$path} fails after its first chunk and the client sees the body cut short",
            str_contains($raw, 'LLLL') && !str_ends_with($raw, "0\r\n\r\n")
        );
    }

    // A client that hangs up stops an endless event stream: connection_aborted() stays 0 under
    // the server, so the failed write has to end the generator.
    $counter = sys_get_temp_dir() . '/spawn-framing-sse-ticks-' . getmypid();
    @unlink($counter);
    $fp = @stream_socket_client("tcp://{$host}:{$port}", $en, $es, 1);
    if ($fp) {
        fwrite($fp, "GET /framing/sse-forever HTTP/1.1\r\nHost: {$host}\r\n\r\n");
        $raw = '';
        while (!feof($fp) && !str_contains($raw, 'tick 1')) {
            $c = fread($fp, 8192);
            if ($c === false || $c === '') { break; }
            $raw .= $c;
        }
        fclose($fp);
    }
    delay(300);
    $ticksThen = (int) @file_get_contents($counter);
    delay(300);
    $ticksLater = (int) @file_get_contents($counter);
    $check(
        "streaming: an event stream stops once its client hangs up ({$ticksThen} then {$ticksLater} ticks)",
        $ticksThen > 0 && $ticksThen === $ticksLater
    );
    @unlink($counter);

    // The worker is still serving after all of that, which a failed stream can cost.
    $after = $request('GET', '/ping');
    $check('framing: the worker still answers afterwards', $after['status'] === 200 && trim($after['body']) === 'pong');

    echo "\nE2E: {$pass} passed, {$fail} failed\n";
    $exitCode = $fail === 0 ? 0 : 1;

    posix_kill(posix_getpid(), SIGTERM);
});

await($main);
exit($exitCode);
