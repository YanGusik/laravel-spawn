<?php

/**
 * In-process streaming end-to-end runner for TrueAsyncServer (SSE + gRPC).
 *
 * DevServer (used by HttpE2ETest) is a plain raw-socket HTTP/1.1 server and
 * has no SSE/gRPC support at all — those live only in TrueAsyncServer, which
 * wraps the real TrueAsync\HttpServer extension. So unlike run.php this boots
 * a genuine HttpServer in a worker thread and drives it with real clients:
 * a raw socket for the SSE stream, curl over HTTP/2 prior-knowledge for the
 * unary gRPC call (protobuf framing hand-built — no .proto compilation needed
 * to prove readMessage()/writeMessage() round-trip).
 *
 * Exits 0 if all scenarios pass, 1 otherwise. Run directly, or via StreamingE2ETest.
 */

use Async\ThreadChannel;
use Bench\Grpc\EchoHandler;
use Spawn\Laravel\Server\TrueAsyncServer;

use function Async\spawn;
use function Async\spawn_thread;
use function Async\await;
use function Async\delay;

require __DIR__ . '/../../vendor/autoload.php';

$autoload  = __DIR__ . '/../../vendor/autoload.php';
$bootstrap = __DIR__ . '/../bench/bootstrap/app.php';
$host = '127.0.0.1';
$port = 8299;

$exitCode = 1;

$main = spawn(static function () use ($autoload, $bootstrap, $host, $port, &$exitCode) {
    $ready = new ThreadChannel(1);

    spawn_thread(static function () use ($ready, $autoload, $bootstrap, $host, $port) {
        try {
            // Each TrueAsync worker thread starts with its own fresh engine
            // state — classes loaded in the parent thread are not visible here.
            // (run.php's DevServer fixture gets this for free because its
            // bootstrap/app.php itself starts with a require of autoload.php.)
            require $autoload;

            // TrueAsyncServer::start() calls buildConfig(), which reads
            // config()/app() in the CALLING thread (mirroring how the real
            // `async:serve` artisan command already runs inside a fully
            // booted console app). The per-worker bootloader closure below
            // boots its own, separate app instance for actually serving
            // requests — this one is only so buildConfig() has something to read.
            $app = require $bootstrap;
            $app->make(\Illuminate\Contracts\Http\Kernel::class)->bootstrap();

            $options = [
                'listeners' => [['host' => $host, 'port' => $port, 'tls' => false, 'protocol' => 'auto']],
                'workers'   => 1,
                'grpc_handlers' => [
                    '/test.Echo/Echo' => [EchoHandler::class, 'echo'],
                ],
            ];

            $server = new TrueAsyncServer($autoload, $bootstrap, $options);
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

    // Raw HTTP GET, connection kept open until the server closes it. Retries
    // until the socket binds (worker thread boot is async relative to us).
    $get = static function (string $path) use ($host, $port): string {
        $fp = false;
        for ($i = 0; $i < 40 && !$fp; $i++) {
            $fp = @stream_socket_client("tcp://{$host}:{$port}", $en, $es, 1);
            if (!$fp) { delay(50); }
        }
        if (!$fp) { return 'CONNECT-FAIL'; }
        fwrite($fp, "GET {$path} HTTP/1.1\r\nHost: {$host}\r\nConnection: close\r\n\r\n");
        $raw = '';
        while (!feof($fp)) { $c = fread($fp, 8192); if ($c === false || $c === '') break; $raw .= $c; }
        fclose($fp);
        return $raw;
    };

    // 1. SSE: the /stream route writes two events directly to the raw
    // HttpResponse and closes it. If TrueAsyncServer::sendResponse() did not
    // skip an already-closed response, the buffered Illuminate response
    // (204 No Content) would clobber or duplicate the stream.
    $sse = $get('/stream');
    $check(
        'sse: headers announce an event stream',
        str_contains($sse, 'content-type: text/event-stream') || str_contains($sse, 'Content-Type: text/event-stream')
    );
    $check('sse: both events arrived in order with their ids', (function () use ($sse): bool {
        $firstTick  = strpos($sse, "id: 1\nevent: tick\ndata: one");
        $secondTick = strpos($sse, "id: 2\nevent: tick\ndata: two");

        return $firstTick !== false && $secondTick !== false && $firstTick < $secondTick;
    })());

    // 2. gRPC: a real HTTP/2 unary call, framed by hand (1-byte compressed
    // flag + 4-byte big-endian length + payload) — no protoc step needed to
    // prove that grpc_handlers dispatch and readMessage()/writeMessage() work.
    $payload = 'hello-grpc-payload';
    $frame = pack('C', 0) . pack('N', strlen($payload)) . $payload;

    $ch = curl_init("http://{$host}:{$port}/test.Echo/Echo");
    curl_setopt_array($ch, [
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $frame,
        CURLOPT_HTTPHEADER => ['content-type: application/grpc', 'te: trailers'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    $grpcResponse = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    $check('grpc: request succeeded (curl: ' . ($curlErr ?: 'none') . ')', $grpcResponse !== false);
    $check('grpc: response is HTTP/2 200 application/grpc', $grpcResponse !== false
        && str_contains($grpcResponse, 'HTTP/2 200')
        && str_contains($grpcResponse, 'content-type: application/grpc'));
    $check('grpc: echoed payload round-tripped', $grpcResponse !== false && str_contains($grpcResponse, $payload));
    $check('grpc: trailer reports grpc-status 0 (OK)', $grpcResponse !== false && str_contains($grpcResponse, 'grpc-status: 0'));

    // 3. Unregistered gRPC method: must fail as UNIMPLEMENTED, not crash the worker.
    $ch = curl_init("http://{$host}:{$port}/no.Such/Method");
    curl_setopt_array($ch, [
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $frame,
        CURLOPT_HTTPHEADER => ['content-type: application/grpc', 'te: trailers'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    $unimplemented = curl_exec($ch);
    curl_close($ch);
    $check('grpc: unmapped path reports UNIMPLEMENTED (12)', $unimplemented !== false && str_contains($unimplemented, 'grpc-status: 12'));

    // 4. The worker must still serve ordinary buffered routes after streaming.
    $ping = $get('/ping');
    $check('resilience: ordinary route still works after streaming', str_contains($ping, 'pong'));

    echo "\nE2E: {$pass} passed, {$fail} failed\n";
    $exitCode = $fail === 0 ? 0 : 1;

    // TrueAsyncServer now listens for SIGINT/SIGTERM itself and calls
    // HttpServer::stop(), so this terminates start()'s block cleanly — no
    // SIGKILL needed. exit() inside a coroutine only ends that coroutine, not
    // the process (the scheduler keeps running) — $exitCode is read after
    // await($main) below, at the top level, same as run.php does it.
    posix_kill(posix_getpid(), SIGTERM);
});

await($main);
exit($exitCode);
