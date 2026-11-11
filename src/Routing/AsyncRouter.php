<?php

namespace Spawn\Laravel\Routing;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Spawn\Laravel\Foundation\RequestContext;

/**
 * Coroutine-safe Router.
 *
 * Stores $current and $currentRequest in per-coroutine context
 * instead of shared instance properties.
 */
class AsyncRouter extends Router
{
    private const CTX_CURRENT_ROUTE   = 'router.current';
    private const CTX_CURRENT_REQUEST = 'router.currentRequest';

    private bool $async = false;

    public function bootCompleted(): void
    {
        $this->async = true;

        /* Type-hinted Route injection must follow the coroutine. */
        if ($this->container instanceof \Spawn\Laravel\Foundation\AsyncApplication) {
            $this->container->scopedSingleton(
                \Illuminate\Routing\Route::class,
                fn () => RequestContext::current()->find(self::CTX_CURRENT_ROUTE),
            );
        }
    }

    public function dispatch(Request $request)
    {
        if ($this->async) {
            $ctx = RequestContext::current();
            $ctx->set(self::CTX_CURRENT_REQUEST, $request, replace: true);
        } else {
            $this->currentRequest = $request;
        }

        return $this->dispatchToRoute($request);
    }

    protected function findRoute($request)
    {
        $this->events->dispatch(new \Illuminate\Routing\Events\Routing($request));

        $route = $this->routes->match($request);

        if ($this->async) {
            /* match() binds the request's parameters onto the Route object, and
             * RouteCollection keeps one per definition: give this coroutine its
             * own copy before anything can yield. */
            $route = clone $route;

            RequestContext::current()->set(self::CTX_CURRENT_ROUTE, $route, replace: true);
        } else {
            $this->current = $route;
        }

        $route->setContainer($this->container);

        /* In async mode Route::class comes from the context instead: a container
         * instance would be the shared state we just cloned away. */
        if (! $this->async) {
            $this->container->instance(\Illuminate\Routing\Route::class, $route);
        }

        return $route;
    }

    public function current()
    {
        if ($this->async) {
            return RequestContext::current()->find(self::CTX_CURRENT_ROUTE);
        }

        return $this->current;
    }

    public function getCurrentRequest()
    {
        if ($this->async) {
            return RequestContext::current()->find(self::CTX_CURRENT_REQUEST);
        }

        return $this->currentRequest;
    }
}
