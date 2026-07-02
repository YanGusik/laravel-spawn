<?php

namespace Spawn\Laravel\Debugbar;

use Fruitcake\LaravelDebugbar\LaravelDebugbar;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;

/**
 * Per-request Debugbar lifecycle for the async servers.
 *
 * The worker boots once and serves many requests, so Debugbar must be reset
 * and (re)booted per request with the current request — mirroring how Laravel
 * Octane resets it on RequestReceived. Booting lazily here (not at worker
 * bootstrap) also avoids Debugbar's request-dependent boot logic running before
 * a real request exists (e.g. IpUtils::isPrivateIp() on a null client IP).
 */
class ResetDebugbar
{
    public static function handle(Application $app, Request $request): void
    {
        if (! class_exists(LaravelDebugbar::class) || ! $app->resolved(LaravelDebugbar::class)) {
            return;
        }

        $debugbar = $app->make(LaravelDebugbar::class);
        $debugbar->setRequest($request);
        $debugbar->reset();

        if ($debugbar->isEnabled() && ! $debugbar->requestIsExcluded($request)) {
            $debugbar->boot();
        }
    }
}
