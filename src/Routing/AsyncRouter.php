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

            $this->becomeCurrent($route);
        } else {
            $this->current = $route;
        }

        $route->setContainer($this->container);

        /* In async mode Route::class comes from the context instead, put there above: a
         * shared container instance would be the shared state we just cloned away. */
        if (! $this->async) {
            $this->container->instance(\Illuminate\Routing\Route::class, $route);
        }

        return $route;
    }

    /**
     * Run the named route for the request being served, on its own copy of the route.
     *
     * Upstream reads the request from a property async mode leaves empty. An exception thrown
     * before the router dispatched anything, by a global middleware, still has the request
     * the container holds. The copy is bound because the collection keeps one Route per
     * definition and binding writes the request's parameters onto it. Unlike upstream, which
     * leaves the current route as it was, the copy becomes the current route as in
     * findRoute(): a 404 answered with a fallback route has no current route of its own, and
     * the scoped Route a type-hint receives would be null. A name with no route, or no request
     * at all, fails as upstream does.
     */
    public function respondWithRoute($name)
    {
        if (! $this->async) {
            return parent::respondWithRoute($name);
        }

        $request = $this->getCurrentRequest()
            ?? ($this->container->bound('request') ? $this->container->make('request') : null);
        $route = $this->routes->getByName($name);

        if ($route !== null) {
            $route = clone $route;
            $this->becomeCurrent($route);
        }

        return $this->runRoute($request, $route->bind($request));
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

    /**
     * Make this coroutine's copy of a route the request's current one.
     */
    private function becomeCurrent(\Illuminate\Routing\Route $route): void
    {
        RequestContext::current()->set(self::CTX_CURRENT_ROUTE, $route, replace: true);

        /* As the stock router does, so that a second dispatch in the request also replaces
         * the Route a type-hint receives: inside a request instance() keeps it there. */
        if ($this->container instanceof \Spawn\Laravel\Foundation\AsyncApplication
            && RequestContext::current() !== \Async\root_context()) {
            $this->container->instance(\Illuminate\Routing\Route::class, $route);
        }
    }
}
