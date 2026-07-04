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
