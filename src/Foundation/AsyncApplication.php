<?php

namespace TrueAsync\Laravel\Foundation;

use Closure;
use Illuminate\Foundation\Application;

use function Async\coroutine_context;

class AsyncApplication extends Application
{
    /**
     * Laravel services that are always request-scoped.
     */
    private const LARAVEL_SCOPED = [
        'session',
        'auth',
        'auth.driver',
        'cookie',
        // NOTE: 'db' cannot be scoped here because DatabaseServiceProvider::boot() sets
        // Model::setConnectionResolver($app['db']) as a static property. A scoped DatabaseManager
        // tied to a specific coroutine context gets GC'd after the coroutine finishes, leaving
        // Model::$resolver pointing to a destroyed object → segfault.
        // Physical connection isolation is handled by PDO Pool at the C level instead.
        // Known limitation: Connection::$transactions counter is shared across coroutines.
        // Workaround: use db.transaction() which goes through DatabaseTransactionsManager.
    ];

    /**
     * Scoped services that are safe to proxy via offsetGet (used by Facades).
     * Services that get passed to typed PHP parameters must NOT be here,
     * because ScopedServiceProxy does not extend/implement their types.
     *
     * 'cookie' is excluded: AuthManager passes $app['cookie'] to setCookieJar(QueueingFactory).
     * 'auth.driver' is excluded: guards are passed to typed parameters in some middleware.
     */
    private const FACADE_PROXIED = [
        'auth',
        'session',
    ];

    /**
     * True while the async HTTP server is running.
     */
    private bool $asyncMode = false;

    /**
     * User-registered scoped factories: abstract => Closure.
     */
    private array $scopedBindings = [];

    /**
     * Register a scoped singleton — one instance per coroutine context.
     */
    public function enableAsyncMode(): void
    {
        $this->asyncMode = true;
    }

    public function scopedSingleton(string $abstract, Closure $factory): void
    {
        $this->scopedBindings[$abstract] = $factory;
    }

    public function offsetGet($key): mixed
    {
        if ($this->asyncMode) {
            $alias = $this->getAlias($key);

            if (in_array($alias, self::FACADE_PROXIED, true)) {
                return new ScopedServiceProxy(fn() => $this->resolveScoped($alias));
            }
        }

        return parent::offsetGet($key);
    }

    protected function resolve($abstract, $parameters = [], $raiseEvents = true)
    {
        if (!$this->asyncMode) {
            return parent::resolve($abstract, $parameters, $raiseEvents);
        }

        $alias = $this->getAlias($abstract);

        // Request is always resolved from coroutine context
        if ($alias === 'request') {
            $request = coroutine_context()->find('laravel.request');

            if ($request !== null) {
                return $request;
            }
        }

        if ($this->isScopedService($alias)) {
            return $this->resolveScoped($alias);
        }

        return parent::resolve($abstract, $parameters, $raiseEvents);
    }

    private function isScopedService(string $alias): bool
    {
        if (in_array($alias, self::LARAVEL_SCOPED, true)) {
            return true;
        }

        if (isset($this->scopedBindings[$alias])) {
            return true;
        }

        if ($this->resolved('config')) {
            $scoped = parent::resolve('config')->get('async.scoped_services', []);
            return in_array($alias, $scoped, true);
        }

        return false;
    }

    private function resolveScoped(string $alias): mixed
    {
        $ctx = coroutine_context();
        $key = 'laravel.scoped.' . $alias;

        $instance = $ctx->find($key);

        if ($instance !== null) {
            return $instance;
        }

        if (isset($this->scopedBindings[$alias])) {
            $instance = ($this->scopedBindings[$alias])($this);
        } else {
            $bindings = $this->getBindings();
            if (isset($bindings[$alias])) {
                $concrete = $bindings[$alias]['concrete'];
                $instance = $concrete instanceof \Closure ? $concrete($this) : $this->build($concrete);
            } else {
                $instance = $this->build($alias);
            }
        }

        $ctx->set($key, $instance);

        return $instance;
    }
}
