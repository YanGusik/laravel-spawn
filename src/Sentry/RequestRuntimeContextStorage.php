<?php

namespace Spawn\Laravel\Sentry;

use Sentry\State\RuntimeContext;
use Sentry\State\RuntimeContextStorageInterface;
use Spawn\Laravel\Foundation\RequestContext;

/**
 * Sentry's runtime context — its hub, scope, breadcrumbs and transaction — kept per request.
 *
 * A request's coroutines share it: they read the request's context. Outside any request
 * (boot, artisan, a queue job) it is one value for the process, as Sentry's own default.
 *
 * The context the request ended is kept, marked released, until the request's context is
 * freed: a coroutine the request spawned and that outlives it still reports into it rather
 * than into the hub every later request is cloned from, and a second end flushes nothing.
 * What such a coroutine buffers after the end — logs, metrics — is not sent.
 *
 * @internal
 */
final class RequestRuntimeContextStorage implements RuntimeContextStorageInterface
{
    private const KEY = 'sentry.runtime_context';

    private ?RuntimeContext $outsideRequest = null;

    /**
     * Drops the context a previous request released in the context being marked now, so that
     * Sentry starts a fresh one: FrankenPHP may serve the next request in the last one's scope.
     */
    public static function forgetReleased(): void
    {
        if (! RequestContext::inRequest()) {
            return;
        }

        $context = RequestContext::current();

        if ($context->findLocal(self::KEY)?->released === true) {
            $context->unset(self::KEY);
        }
    }

    public function get(): ?RuntimeContext
    {
        if (! RequestContext::inRequest()) {
            return $this->outsideRequest;
        }

        return RequestContext::current()->find(self::KEY)?->context;
    }

    public function set(RuntimeContext $runtimeContext): void
    {
        if (! RequestContext::inRequest()) {
            $this->outsideRequest = $runtimeContext;

            return;
        }

        RequestContext::current()->set(self::KEY, new HeldRuntimeContext($runtimeContext), replace: true);
    }

    public function remove(): ?RuntimeContext
    {
        if (! RequestContext::inRequest()) {
            $context = $this->outsideRequest;
            $this->outsideRequest = null;

            return $context;
        }

        $held = RequestContext::current()->find(self::KEY);

        if ($held === null || $held->released) {
            return null;
        }

        $held->released = true;

        return $held->context;
    }
}
