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

    public function isAsyncModeEnabled(): bool
    {
        return $this->asyncMode;
    }

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

    /**
     * In async mode 'request' is always resolvable (from context, instances, or fallback).
     * Returning true here makes rebinding('request', ...) call make('request') instead of
     * returning null — which prevents a TypeError in UrlGenerator::__construct when the
     * exception handler renders an error before the first HTTP request is processed.
     */
    /**
     * 'request' is always resolvable: from context, instances, or fallback.
     * This prevents crashes when code checks bound('request') during bootstrap
     * (before any HTTP request arrives) — e.g. AuthServiceProvider::registerRequestRebindHandler().
     */
    public function bound($abstract): bool
    {
        if ($this->getAlias($abstract) === 'request') {
            return true;
        }

        return parent::bound($abstract);
    }

    public function offsetGet($key): mixed
    {
        $alias = $this->getAlias($key);

        if ($this->asyncMode && isset(self::FACADE_PROXIED_MAP[$alias])) {
            return new ScopedServiceProxy(fn() => $this->tryResolveScoped($alias));
        }

        // 'request' is always safe to resolve — even during bootstrap when no
        // HTTP request exists yet. Without this, any code that touches $app['request']
        // before the first onRequest() call crashes with "Class request does not exist".
        //
        // Resolution priority:
        //   1. context  — per-coroutine request (async mode, during request handling)
        //   2. instances — set by Kernel::sendRequestThroughRouter()
        //   3. fallback — empty Request so bootstrap/error handler can proceed
        if ($alias === 'request') {
            return $this->resolveRequest();
        }

        return parent::offsetGet($key);
    }

    protected function resolve($abstract, $parameters = [], $raiseEvents = true)
    {
        $alias = $this->getAlias($abstract);

        // 'request' must never fall through to build('request') — it's a string
        // alias, not a class name, so ReflectionClass would throw.
        if ($alias === 'request') {
            return $this->resolveRequest();
        }

        if ($this->asyncMode) {
            $instance = $this->tryResolveScoped($alias);

            if ($instance !== null) {
                return $instance;
            }
        }

        return parent::resolve($abstract, $parameters, $raiseEvents);
    }

    /**
     * Resolve the current request from context, instances, or fallback.
     */
    private function resolveRequest(): \Illuminate\Http\Request
    {
        if ($this->asyncMode) {
            $fromContext = current_context()->find(ScopedService::REQUEST);
            if ($fromContext !== null) {
                return $fromContext;
            }
        }

        return $this->instances['request']
            ?? \Illuminate\Http\Request::createFromGlobals();
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
                // No factory registered (e.g. 'request' is stored in instances[], not bindings[]).
                // Fall through to parent::resolve which handles instances[] correctly.
                return null;
            }
        }

        // Fire only the alias-specific afterResolving callbacks so adapters registered via
        // afterResolving('session', ...) work for scoped services.
        //
        // We intentionally do NOT call fireResolvingCallbacks() here because that also
        // fires globalAfterResolvingCallbacks — third-party callbacks that may access
        // services not yet available (e.g. 'request') and crash the worker on boot.
        $aliasKey = $this->getAlias($alias);
        foreach ($this->afterResolvingCallbacks[$aliasKey] ?? [] as $cb) {
            $cb($instance, $this);
        }

        // Fire resolving/afterResolving callbacks so that adapters registered via
        // afterResolving() (e.g. registerSessionAdapter) work for scoped services too.
        // Without this, parent::resolve() is bypassed and callbacks never fire.
        $this->fireResolvingCallbacks($alias, $instance);

        $ctx->set($ctxKey, $instance);

        return $instance;
    }
}
