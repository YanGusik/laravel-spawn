<?php

namespace Spawn\Laravel\Foundation;

use Async\Scope;
use Closure;
use Illuminate\Http\Request;

/**
 * Drives requests through a worker and collects what each one leaves captured.
 *
 * The capture audit can only report objects that exist, and at worker start almost
 * nothing per-request does: Laravel builds `url`, the response factory and every
 * singleton middleware on the first request that needs them. Serving a request is
 * therefore part of the measurement, not a way of using the result — which is why this
 * exists beside {@see AsyncApplication::perRequestCaptures()} rather than inside it.
 *
 * Each URL runs in a scope of its own, the way a served request does, and the findings
 * are collected while that scope is still alive.
 */
final class CaptureSweep
{
    private const TIMEOUT_MS = 30_000;

    /**
     * @param  Closure(Request): mixed  $serve  hands the request to the application and
     *   returns when the response is done, the way a server's request handler does
     */
    public function __construct(
        private readonly AsyncApplication $app,
        private readonly Closure $serve,
    ) {
    }

    /**
     * @param  string[]  $urls  driven in the order given
     * @return array<string, array{alias: string, path: string, captured: string, url: string}>
     *   keyed by alias and path, so a capture found by three URLs is reported once, named
     *   after the first URL that produced it
     */
    public function over(array $urls): array
    {
        $found = [];

        foreach ($urls as $url) {
            foreach ($this->drive($url) as $alias => $captures) {
                foreach ($captures as $path => $captured) {
                    $found["{$alias}->{$path}"] ??= [
                        'alias'    => $alias,
                        'path'     => $path,
                        'captured' => $captured,
                        'url'      => $url,
                    ];
                }
            }
        }

        return $found;
    }

    /**
     * @return array<string, array<string, string>> what this one request left captured
     */
    private function drive(string $url): array
    {
        $captures = [];
        $scope    = Scope::inherit();

        $scope->spawn(function () use ($url, &$captures): void {
            // As DevServer marks its request: the audit has no server to give it a request scope.
            RequestContext::begin();

            $request = Request::create($url, 'GET');

            RequestContext::current()->set(ScopedService::REQUEST, $request);

            ($this->serve)($request);

            // While the scope is alive: the request's own services live in its context,
            // and they are half of what the audit compares against.
            $captures = $this->app->perRequestCaptures();
        });

        $scope->awaitCompletion(\Async\timeout(self::TIMEOUT_MS));
        $scope->dispose();

        return $captures;
    }
}
