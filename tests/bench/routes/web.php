<?php

/** @var Illuminate\Routing\Router $router */

$router->get('/ping', fn() => response('pong'));

$router->get('/json', fn() => response()->json([
    'status' => 'ok',
    'time' => microtime(true),
    'memory' => memory_get_usage(true),
]));

$router->get('/scoped', function (Illuminate\Http\Request $request) {
    return response()->json([
        'method' => $request->method(),
        'path' => $request->path(),
        'query' => $request->query(),
    ]);
});

// ── e2e probes ──

// Request isolation: re-resolve the request across an I/O yield. Under correct
// per-coroutine scoping, `before` and `after` are always this request's own id.
$router->get('/iso', function (Illuminate\Http\Request $request) {
    $before = (string) $request->query('id', '?');
    \Async\delay((int) $request->query('ms', 40));
    $after = (string) app('request')->query('id', '?');
    return response("{$before}:{$after}");
});

// Error handling: the handler throws — the worker must survive (kernel returns
// a 5xx) and concurrent requests must be unaffected.
$router->get('/boom', function () {
    throw new \RuntimeException('boom');
});

// Request-scope probe (RequestScopeE2ETest, TrueAsyncServer only): a handler that
// spawns a coroutine of its own, as anything doing work in parallel does. The nested
// coroutine resolves the scoped service FIRST — that is the case that tells the two
// candidate contexts apart, because a context reaches its parents but never its
// children. Under request_context() both coroutines share this request's redirector;
// under current_context() the nested one gets a second redirector of its own, whose
// flashed data the response never sees.
//
// `request_scope` reports whether this process even has request scopes: they are
// assigned by the server extension, so under DevServer or in a unit test the answer
// is null and the probe proves nothing.
$router->get('/request-scope', function () {
    $inner = null;
    $token = uniqid('probe', true);

    $nested = \Async\Scope::inherit();
    $nested->spawn(function () use (&$inner, $token) {
        $inner = spl_object_id(app('redirect'));

        // The adapters keep their per-request state in the context the container uses,
        // so a write here reaches the handler that spawned this coroutine. Under the
        // scope's own context it would not: a context reaches its parents, never its
        // children.
        config(['spawn.probe' => $token]);
        app()->setLocale('zz');
    });
    $nested->awaitCompletion(\Async\timeout(2000));

    $outer = spl_object_id(app('redirect'));

    return response()->json([
        'request_scope' => \Async\request_context() !== null,
        'shared'        => $inner === $outer,
        'config_shared' => config('spawn.probe') === $token,
        'locale_shared' => app()->getLocale() === 'zz',
        'id'            => $outer,
    ]);
});

// Render-load probe (RenderLoadE2ETest, TrueAsyncServer only): one request writes its
// token into every path a page collects state in, and the render suspends in the middle
// of a section — a composer on load.partials.aside yields, which is where a real render
// hands the coroutine over. A stand whose only suspension is before the render proves
// nothing about Blade: the renders run atomically and shared state looks isolated.
//
// The terminating callback is checked as well as the response, because it runs after the
// body is built and reads the log context, which nothing in the body would show.
app('view')->composer('load.partials.aside', fn () => \Async\suspend());

$router->get('/render-load', function (Illuminate\Http\Request $request) {
    $token = (string) $request->query('token', 'none');
    $log   = dirname(__DIR__) . '/storage/render-load.log';

    Illuminate\Support\Facades\Context::add('token', $token);
    Illuminate\Support\Facades\Cookie::queue('probe', $token, 0, null, null, false, false);

    app()->terminating(function () use ($token, $log) {
        $seen = Illuminate\Support\Facades\Context::get('token');

        // No LOCK_EX: five coroutines waiting on flock stop this build of the runtime
        // (ASYNC_KNOWN_ISSUES.md §5). One short append is atomic on Linux without it.
        file_put_contents($log, $token . ':' . $seen . "\n", FILE_APPEND);
    });

    return response()->view('load.page', [
        'token' => $token,
        'url'   => url()->full(),
    ]);
});

// SSE probe (StreamingE2ETest, TrueAsyncServer only): writes directly to the
// raw HttpResponse and closes it, so TrueAsyncServer::sendResponse() must skip
// the buffered path entirely once isEnded() is true.
$router->get('/stream', function () {
    \Spawn\Laravel\Sse\Sse::start();
    \Spawn\Laravel\Sse\Sse::event(data: 'one', event: 'tick', id: '1');
    \Spawn\Laravel\Sse\Sse::event(data: 'two', event: 'tick', id: '2');
    \Spawn\Laravel\Sse\Sse::end();

    return response()->noContent();
});

// Metrics probe (ServerMetricsE2ETest, TrueAsyncServer only): the package publishes no
// endpoint, so the application declares one. Both shapes of the same counters, so the
// test can read a number without parsing the exposition format.
$router->get('/metrics', function () {
    return response(
        app(\Spawn\Laravel\Server\ServerMetrics::class)->toPrometheus(),
        200,
        ['Content-Type' => 'text/plain; version=0.0.4'],
    );
});

$router->get('/metrics.json', function () {
    $metrics = trueasync_metrics();

    return response()->json([
        'available' => $metrics->isAvailable(),
        'totals'    => $metrics->totals(),
        'workers'   => $metrics->workers(),
        'latency'   => $metrics->latency(),
    ]);
});

// Same probe for a server started with statistics off: a counter read throws rather than
// answering zero, while the timings answer, because they do not depend on that setting.
$router->get('/metrics-availability', function () {
    $metrics = trueasync_metrics();
    $thrown  = null;

    try {
        $metrics->totals();
    } catch (\Throwable $e) {
        $thrown = $e::class;
    }

    return response()->json([
        'available' => $metrics->isAvailable(),
        'thrown'    => $thrown,
        'latency'   => $metrics->latency(),
    ]);
});

// ── Response framing probes (ResponseFramingE2ETest, TrueAsyncServer only) ──
//
// What leaves the socket is decided by TrueAsyncServer::sendResponse(), and by nothing
// DevServer shares: it emits through ResponseEmitter, which counts the body itself. Only
// a genuine TrueAsync\HttpServer can show a Content-Length that disagrees with the bytes,
// or a body that never left at all.

// A Content-Length the application got wrong. The server emits a handler-set value
// verbatim, so copying this one through would put 999999 on the wire above five bytes.
$router->get('/framing/stale-length', fn () => response('SHORT', 200, [
    'Content-Length' => '999999',
    'Content-Type'   => 'text/plain',
]));

// The file of a download is written to standard output by Symfony, and its size is
// announced in a header Symfony sets: getContent() answers false for it.
$router->get('/framing/download', function () {
    $path = sys_get_temp_dir() . '/spawn-framing-probe.bin';

    if (!is_file($path) || filesize($path) !== 300000) {
        file_put_contents($path, str_repeat('A', 300000));
    }

    return response()->download($path, 'probe.bin');
});

// The same for a stream, except the bytes come from a callback that echoes in bursts
// larger than the forwarding buffer.
$router->get('/framing/stream', fn () => response()->stream(function () {
    for ($i = 0; $i < 3; $i++) {
        echo str_repeat('B', 100000);
    }
}, 200, ['Content-Type' => 'text/plain']));

// A body callback that fails before anything has been forwarded. Nothing is committed
// yet, so the request can still be answered as the failure it is.
$router->get('/framing/stream-throws', fn () => response()->stream(function () {
    echo 'partial';

    throw new \RuntimeException('body callback failed');
}, 200, ['Content-Type' => 'text/plain']));

// PHP runs an output handler on a discard as well, handing it the bytes it was asked
// to drop. They must not reach the client.
$router->get('/framing/discard', fn () => response()->stream(function () {
    echo 'DISCARDED';
    ob_clean();
    echo 'KEPT';
}, 200, ['Content-Type' => 'text/plain']));
