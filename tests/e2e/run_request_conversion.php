<?php

/**
 * Request-conversion end-to-end runner (TrueAsyncServer only).
 *
 * TrueAsyncServer builds the Laravel request from a TrueAsync\HttpRequest, which is final
 * with a private constructor: the conversion cannot be fed a request in a unit test, only a
 * genuine TrueAsync\HttpServer can. This boots one in a worker thread — the same shape as
 * run_request_scope.php — sends raw HTTP/1.1 requests to /echo-request and checks what the
 * Laravel request was built from: the client address, the query string as sent, the cookies
 * as PHP decodes them, the host and port.
 *
 * Exits 0 if all scenarios pass, 1 otherwise. Run directly, or via RequestConversionE2ETest.
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
$port = 8402;
$client = '127.0.0.2';

$exitCode = 1;

$main = spawn(static function () use ($autoload, $bootstrap, $host, $port, $client, &$exitCode) {
    $ready = new ThreadChannel(1);

    spawn_thread(static function () use ($ready, $autoload, $bootstrap, $host, $port) {
        try {
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
    $check = static function (string $name, bool $ok, mixed $seen = null) use (&$pass, &$fail): void {
        echo ($ok ? 'PASS' : 'FAIL') . " — {$name}" . ($ok ? '' : ' (seen: ' . json_encode($seen) . ')') . "\n";
        $ok ? $pass++ : $fail++;
    };

    /**
     * Send one GET with the given target and extra header lines; answer the decoded JSON body
     * and the local port the client connected from.
     *
     * @return array{0: array|null, 1: int}
     */
    $get = static function (string $target, array $headers = [], ?string $hostHeader = null) use ($host, $port, $client): array {
        // The client binds a loopback address other than the server's, so an address the
        // server made up (127.0.0.1) cannot pass for the real one.
        $context = stream_context_create(['socket' => ['bindto' => "{$client}:0"]]);
        $fp = false;
        for ($i = 0; $i < 40 && !$fp; $i++) {
            $fp = @stream_socket_client("tcp://{$host}:{$port}", $en, $es, 1, STREAM_CLIENT_CONNECT, $context);
            if (!$fp) { delay(50); }
        }
        if (!$fp) { return [null, 0]; }
        $local = stream_socket_get_name($fp, false);
        $localPort = (int) substr($local, strrpos($local, ':') + 1);
        $lines = array_merge(['Host: ' . ($hostHeader ?? "{$host}:{$port}"), 'Connection: close'], $headers);
        fwrite($fp, "GET {$target} HTTP/1.1\r\n" . implode("\r\n", $lines) . "\r\n\r\n");
        $raw = '';
        while (!feof($fp)) { $c = fread($fp, 8192); if ($c === false || $c === '') break; $raw .= $c; }
        fclose($fp);
        $pos = strpos($raw, "\r\n\r\n");
        return [$pos === false ? null : json_decode(substr($raw, $pos + 4), true), $localPort];
    };

    [$seen, $localPort] = $get('/echo-request', ['X-Forwarded-For: 203.0.113.9']);

    $check('remote address: the route answered', is_array($seen), $seen);
    $check('remote address: REMOTE_ADDR is the client socket', ($seen['remote_addr'] ?? null) === $client, $seen);
    $check('remote address: REMOTE_PORT is the client port', (int) ($seen['remote_port'] ?? 0) === $localPort, $seen);
    $check('remote address: a forged X-Forwarded-For does not change ip()', ($seen['ip'] ?? null) === $client, $seen);

    // A signed URL hashes the query string as sent: re-encoding it from the parsed array
    // turns %20 into +, ids[] into ids[0], ?flag into flag= and a.b into a_b.
    $raw = 'ids[]=1&ids[]=2&name=a%20b~c&a.b=1&flag';
    [$seen] = $get('/echo-request?' . $raw);

    $check('query string: QUERY_STRING is the one the client sent', ($seen['query_string'] ?? null) === $raw, $seen);

    [$seen] = $get('/echo-request');

    $check('query string: a request without one has an empty QUERY_STRING', ($seen['query_string'] ?? null) === '', $seen);

    [$seen] = $get('/echo-request', ['Cookie: name=John%20Doe;sum=1+1']);

    $check(
        'cookies: values are decoded as PHP decodes them',
        ($seen['cookies'] ?? null) === ['name' => 'John Doe', 'sum' => '1+1'],
        $seen
    );

    echo "\nE2E: {$pass} passed, {$fail} failed\n";
    $exitCode = $fail === 0 ? 0 : 1;

    posix_kill(posix_getpid(), SIGTERM);
});

await($main);
exit($exitCode);
