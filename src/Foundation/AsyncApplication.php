<?php

namespace Spawn\Laravel\Foundation;

use Closure;
use Illuminate\Foundation\Application;

use function Async\current_context;

class AsyncApplication extends Application
{
    /**
     * Scoped services that are safe to proxy via offsetGet (used by Facades).
     * Services that get passed to typed PHP parameters must NOT be here,
     * because ScopedServiceProxy does not extend/implement their types.
     *
     * 'cookie' is excluded: AuthManager passes $app['cookie'] to setCookieJar(QueueingFactory).
     * 'auth.driver' is excluded: guards are passed to typed parameters in some middleware.
     */
    private const FACADE_PROXIED_MAP = [
        'auth'    => true,
        'session' => true,
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
     * Cached config('async.scoped_services') as alias => true hash map.
     * Populated once in enableAsyncMode() to avoid per-resolve config lookups.
     */
    private array $scopedServiceCache = [];

    public function enableAsyncMode(): void
    {
        $this->asyncMode = true;

        if ($this->resolved('config')) {
            $scoped = $this->make('config')->get('async.scoped_services', []);
            $this->scopedServiceCache = array_flip($scoped);
        }
    }

    public function scopedSingleton(string $abstract, Closure $factory): void
    {
        $this->scopedBindings[$abstract] = $factory;
    }

    public function offsetGet($key): mixed
    {
        if ($this->asyncMode) {
            $alias = $this->getAlias($key);

            if (isset(self::FACADE_PROXIED_MAP[$alias])) {
                return new ScopedServiceProxy(fn() => $this->tryResolveScoped($alias));
            }
        }

        return parent::offsetGet($key);
    }

    protected function resolve($abstract, $parameters = [], $raiseEvents = true)
    {
        if ($this->asyncMode) {
            $alias = $this->getAlias($abstract);
            $instance = $this->tryResolveScoped($alias);

            if ($instance !== null) {
                return $instance;
            }
        }

        return parent::resolve($abstract, $parameters, $raiseEvents);
    }

    /**
     * Resolve a scoped service from the current context, or return null
     * if the alias is not a scoped service.
     */
    private function tryResolveScoped(string $alias): mixed
    {
        $key = ScopedService::tryFrom($alias);

        if ($key === null && !isset($this->scopedBindings[$alias]) && !isset($this->scopedServiceCache[$alias])) {
            return null;
        }

        $ctx = current_context();
        $ctxKey = $key ?? $alias;

        $instance = $ctx->find($ctxKey);

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

        // Fire resolving/afterResolving callbacks so that adapters registered via
        // afterResolving() (e.g. registerSessionAdapter) work for scoped services too.
        // Without this, parent::resolve() is bypassed and callbacks never fire.
        $this->fireResolvingCallbacks($alias, $instance);

        $ctx->set($ctxKey, $instance);

        return $instance;
    }
}
