<?php

/**
 * In-process HTTP end-to-end runner.
 *
 * Boots the bench fixture app inside a real worker thread (`spawn_thread`) and
 * drives it over a loopback socket with concurrent coroutine clients — no
 * external process, no separate server subprocess. `Thread::cancel()` is not
 * implemented in TrueAsync, so teardown uses the DevServer's own SIGTERM handler
 * (which stops the server thread while this process's main flow survives).
 *
 * Exits 0 if all scenarios pass, 1 otherwise. Run directly, or via HttpE2ETest.
 */

use Async\ThreadChannel;
use Illuminate\Contracts\Http\Kernel;
use Spawn\Laravel\Server\DevServer;

use function Async\spawn;
use function Async\spawn_thread;
use function Async\await;
use function Async\delay;

require __DIR__ . '/../../vendor/autoload.php';

$boot = __DIR__ . '/../bench/bootstrap/app.php';
$host = '127.0.0.1';
$port = 8199;

$exitCode = 1;

$main = spawn(static function () use ($boot, $host, $port, &$exitCode) {
    $ready = new ThreadChannel(1);

    spawn_thread(static function () use ($ready, $boot, $host, $port) {
        try {
            $app = require $boot;
            $app->make(Kernel::class)->bootstrap();
            $server = new DevServer($app, $host, $port);
            $server->prepareApp();
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

    // Raw HTTP GET → [int status, string body]. Retries until the socket binds.
    $get = static function (string $path) use ($host, $port): array {
        $fp = false;
        for ($i = 0; $i < 40 && !$fp; $i++) {
            $fp = @stream_socket_client("tcp://{$host}:{$port}", $en, $es, 1);
            if (!$fp) { delay(50); }
        }
        if (!$fp) { return [0, 'CONNECT-FAIL']; }
        fwrite($fp, "GET {$path} HTTP/1.1\r\nHost: {$host}\r\nConnection: close\r\n\r\n");
        $raw = '';
        while (!feof($fp)) { $c = fread($fp, 8192); if ($c === false || $c === '') break; $raw .= $c; }
        fclose($fp);
        $status = preg_match('#^HTTP/\d\.\d (\d+)#', $raw, $m) ? (int) $m[1] : 0;
        $pos = strpos($raw, "\r\n\r\n");
        return [$status, $pos === false ? '' : substr($raw, $pos + 4)];
    };

    $pass = 0; $fail = 0;
    $check = static function (string $name, bool $ok) use (&$pass, &$fail): void {
        echo ($ok ? 'PASS' : 'FAIL') . " — {$name}\n";
        $ok ? $pass++ : $fail++;
    };

    // 1. Basic routing.
    [$st, $body] = $get('/ping');
    $check('routing: GET /ping → 200 "pong"', $st === 200 && trim($body) === 'pong');

    // 2. Request isolation under concurrency (interleaved on an 80ms yield).
    $ca = spawn(static fn () => $get('/iso?id=AAA&ms=80'));
    $cb = spawn(static fn () => $get('/iso?id=BBB&ms=80'));
    [, $ba] = await($ca);
    [, $bb] = await($cb);
    $check('isolation: concurrent /iso keep their own request', str_contains($ba, 'AAA:AAA') && str_contains($bb, 'BBB:BBB'));

    // 3. Error handling: a throwing request must not affect a concurrent one.
    $boom = spawn(static fn () => $get('/boom'));
    $iso  = spawn(static fn () => $get('/iso?id=CCC&ms=80'));
    [$boomStatus] = await($boom);
    [, $isoBody] = await($iso);
    $check('errors: /boom returns 5xx, concurrent /iso unaffected', $boomStatus >= 500 && str_contains($isoBody, 'CCC:CCC'));

    // 4. Worker survives the error and keeps serving.
    [$st2] = $get('/ping');
    $check('resilience: server still serving after an error', $st2 === 200);

    echo "\nE2E: {$pass} passed, {$fail} failed\n";
    $exitCode = $fail === 0 ? 0 : 1;

    // Stop the server thread (Thread::cancel() is unimplemented → SIGTERM).
    posix_kill(posix_getpid(), SIGTERM);
});

await($main);
exit($exitCode);
