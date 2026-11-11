<?php

namespace Spawn\Laravel\Foundation;

use Async\Context;

use function Async\current_context;
use function Async\request_context;

/**
 * The context that per-request state belongs to, for every adapter in this package.
 *
 * The request scope is the one the server assigns per request and every coroutine of
 * that request inherits, so a nested `Scope::inherit()` inside a handler reads the same
 * session, the same config overlay and the same Blade sections as the handler that
 * spawned it. The scope's own context would give that coroutine a second set of
 * everything, and a context reaches its parents but never its children, so what the
 * nested coroutine writes would never reach the response.
 *
 * Server extensions are what assign a request scope. There is none under DevServer,
 * under FrankenPHP, under artisan or inside PHPUnit. A server without one marks the request's
 * own scope with begin(), and every coroutine below it answers with that scope's context, as
 * under a request scope. With neither, the scope's own context is the answer.
 */
final class RequestContext
{
    /** Holds a WeakReference to the context of the scope begin() marked: the context itself would be a cycle. */
    private const BOUNDARY = 'spawn.request.boundary';

    public static function current(): Context
    {
        return request_context() ?? self::marked() ?? current_context();
    }

    /**
     * Mark the current scope as the request's own, for a server the extension gives no request
     * scope. Called first thing in the coroutine that serves the request.
     */
    public static function begin(): void
    {
        $context = current_context();

        $context->set(self::BOUNDARY, \WeakReference::create($context), replace: true);
    }

    /**
     * Whether a request is being served: one with a request scope, or one begin() marked.
     * Outside one, per-request state may be inherited from the scopes above.
     */
    public static function inRequest(): bool
    {
        return request_context() !== null || self::marked() !== null;
    }

    private static function marked(): ?Context
    {
        $boundary = current_context()->find(self::BOUNDARY);

        return $boundary instanceof \WeakReference ? $boundary->get() : null;
    }
}
