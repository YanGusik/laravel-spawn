<?php

/**
 * Request-conversion end-to-end runner (TrueAsyncServer only).
 *
 * TrueAsyncServer builds the Laravel request from a TrueAsync\HttpRequest, which is final
 * with a private constructor: the conversion cannot be fed a request in a unit test, only a
 * genuine TrueAsync\HttpServer can. This boots one in a worker thread — the same shape as
 * run_request_scope.php — sends raw HTTP/1.1 requests to /echo-request and checks what the
 * Laravel request was built from: the client address, the query string as sent, the cookies
 * as PHP decodes them, the host and port, and HTTPS on a TLS listener.
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
$tlsPort = 8403;
$client = '127.0.0.2';

// A self-signed pair for the TLS listener; the client does not verify it.
$certDir = sys_get_temp_dir() . '/spawn-e2e-conversion-' . getmypid();
@mkdir($certDir, 0700, true);
$cert = "{$certDir}/cert.pem";
$key  = "{$certDir}/key.pem";
exec(sprintf(
    'openssl req -x509 -newkey rsa:2048 -sha256 -days 1 -nodes -subj "/CN=localhost" -keyout %s -out %s 2>/dev/null',
    escapeshellarg($key),
    escapeshellarg($cert)
), $_, $rc);
if ($rc !== 0) {
    fwrite(STDERR, "openssl could not make a certificate\n");
    exit(1);
}
register_shutdown_function(static function () use ($certDir, $cert, $key): void {
    @unlink($cert); @unlink($key); @rmdir($certDir);
});

$exitCode = 1;

$main = spawn(static function () use ($autoload, $bootstrap, $host, $port, $tlsPort, $client, $cert, $key, &$exitCode) {
    $ready = new ThreadChannel(1);

    spawn_thread(static function () use ($ready, $autoload, $bootstrap, $host, $port, $tlsPort, $cert, $key) {
        try {
            require $autoload;

            $app = require $bootstrap;
            $app->make(\Illuminate\Contracts\Http\Kernel::class)->bootstrap();

            $server = new TrueAsyncServer($autoload, $bootstrap, [
                'listeners' => [
                    ['host' => $host, 'port' => $port, 'tls' => false, 'protocol' => 'auto'],
                    ['host' => $host, 'port' => $tlsPort, 'tls' => true, 'protocol' => 'auto'],
                ],
                'workers'   => 1,
                'tls_cert'  => $cert,
                'tls_key'   => $key,
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
     * and the local port the client connected from. $tls sends it to the TLS listener.
     *
     * @return array{0: array|null, 1: int}
     */
    $get = static function (string $target, array $headers = [], ?string $hostHeader = null, bool $tls = false) use ($host, $port, $tlsPort, $client): array {
        // The client binds a loopback address other than the server's, so an address the
        // server made up (127.0.0.1) cannot pass for the real one.
        $context = stream_context_create([
            'socket' => ['bindto' => "{$client}:0"],
            'ssl'    => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $target_port = $tls ? $tlsPort : $port;
        $fp = false;
        for ($i = 0; $i < 40 && !$fp; $i++) {
            $fp = @stream_socket_client(($tls ? 'ssl' : 'tcp') . "://{$host}:{$target_port}", $en, $es, 1, STREAM_CLIENT_CONNECT, $context);
            if (!$fp) { delay(50); }
        }
        if (!$fp) { return [null, 0]; }
        $local = stream_socket_get_name($fp, false);
        $localPort = (int) substr($local, strrpos($local, ':') + 1);
        $lines = array_merge(['Host: ' . ($hostHeader ?? "{$host}:{$target_port}"), 'Connection: close'], $headers);
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

    [$seen] = $get('/echo-request', [], '[::1]:8000');

    $check(
        'host: an IPv6 Host header splits into its name and port',
        ($seen['server_name'] ?? null) === '[::1]' && ($seen['server_port'] ?? null) === 8000,
        $seen
    );

    [$seen] = $get('/echo-request');

    $check(
        'https: a plaintext listener leaves HTTPS unset',
        is_array($seen) && $seen['https'] === null && $seen['secure'] === false,
        $seen
    );

    // A server older than true-async/server#444 cannot tell TLS from plaintext; the two TLS
    // checks wait for an image that ships it rather than fail on one that does not.
    $serverKnowsTls = method_exists(\TrueAsync\HttpRequest::class, 'isSecure');

    if ($serverKnowsTls) {
        [$seen] = $get('/echo-request', [], null, true);

        $check(
            'https: a TLS listener sets HTTPS, and isSecure() and url() follow',
            ($seen['https'] ?? null) === 'on' && ($seen['secure'] ?? null) === true
                && ($seen['url'] ?? null) === "https://{$host}:{$tlsPort}/x",
            $seen
        );
    }

    // X-Forwarded-Proto is the proxy's word; without TrustProxies it changes nothing.
    [$seen] = $get('/echo-request', ['X-Forwarded-Proto: https']);

    $check('https: a forged X-Forwarded-Proto does not make a request secure', ($seen['secure'] ?? null) === false, $seen);

    if ($serverKnowsTls) {
        [$seen] = $get('/echo-request', [], 'example.test', true);

        $check(
            'https: a Host without a port defaults SERVER_PORT to 443 on TLS',
            ($seen['server_port'] ?? null) === 443 && ($seen['url'] ?? null) === 'https://example.test/x',
            $seen
        );
    } else {
        echo "SKIP — https: TLS checks, the server has no HttpRequest::isSecure() (true-async/server#444)\n";
    }

    echo "\nE2E: {$pass} passed, {$fail} failed\n";
    $exitCode = $fail === 0 ? 0 : 1;

    posix_kill(posix_getpid(), SIGTERM);
});

await($main);
exit($exitCode);
