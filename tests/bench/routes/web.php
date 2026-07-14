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

// SSE probe (StreamingE2ETest, TrueAsyncServer only): writes directly to the
// raw HttpResponse and closes it, so TrueAsyncServer::sendResponse() must skip
// the buffered path entirely once isClosed() is true.
$router->get('/stream', function () {
    \Spawn\Laravel\Sse\Sse::start();
    \Spawn\Laravel\Sse\Sse::event(data: 'one', event: 'tick', id: '1');
    \Spawn\Laravel\Sse\Sse::event(data: 'two', event: 'tick', id: '2');
    \Spawn\Laravel\Sse\Sse::end();

    return response()->noContent();
});
