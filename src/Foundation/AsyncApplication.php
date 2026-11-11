<?php

namespace Spawn\Laravel\Foundation;

use Closure;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;

use function Async\coroutine_context;
use function Async\root_context;

class AsyncApplication extends Application
{
    /**
     * Context key under which a coroutine counts the per-request factories it is inside.
     */
    private const BUILDING_SCOPED = 'spawn.building-scoped';

    /**
     * Context key under which a request keeps its own terminating callbacks.
     */
    private const TERMINATING = 'spawn.terminating';

    /**
     * True while the async HTTP server is running.
     */
    private bool $asyncMode = false;

    /**
     * User-registered scoped factories: abstract => Closure.
     */
    private array $scopedBindings = [];

    /**
     * Registration transfer callbacks: abstract => Closure(object $fresh, object $bootInstance).
     */
    private array $scopedSeeders = [];

    /**
     * What the container held for each scoped alias when async mode started:
     * abstract => object. A missing entry means the service was never resolved
     * during bootstrap, so it carries no configuration worth transferring.
     */
    private array $scopedPrototypes = [];

    /**
     * Cached config('async.scoped_services') as alias => true hash map.
     * Populated once in enableAsyncMode() to avoid per-resolve config lookups.
     */
    private array $scopedServiceCache = [];

    /**
     * Every alias resolved per coroutine, mapped to the key it is stored under in the
     * context: alias => ScopedService|string. Answering from one map is what keeps the
     * check off the hot path — it is consulted on every resolve the application makes.
     */
    private array $perRequestKeys = [];

    /**
     * How many entries of Laravel's own scoped list have been taken in, so that a list
     * which grows while the worker runs is noticed without walking it every time.
     */
    private int $scopedInstancesSeen = 0;

    /**
     * Guards the config read in perRequestKey() against asking itself the question.
     */
    private bool $readingScopedConfig = false;

    /**
     * Whether the configured list has been read for the last time.
     *
     * Until it is, perRequestKey() retries the read on every alias it does not
     * recognise, because a list that came back empty may only mean the package config
     * had not been merged yet.
     */
    private bool $scopedConfigIsFinal = false;

    /**
     * Aliases the worker deliberately replaced with instance() while serving, outside any
     * request, as a set. Such an object is the answer for everyone, scoping or not.
     */
    private array $replacedInstances = [];

    /**
     * async.diagnostics as read when async mode started: whether a shared service replaced
     * from inside a request is reported.
     */
    private bool $diagnostics = false;

    /**
     * Shared aliases already reported as replaced from inside a request, as a set: one line
     * per alias for the life of the worker, not one per request.
     */
    private array $reportedSharedReplacements = [];

    public function __construct($basePath = null)
    {
        parent::__construct($basePath);

        foreach (ScopedService::cases() as $case) {
            $this->perRequestKeys[$case->value] = $case;
        }
    }

    public function isAsyncModeEnabled(): bool
    {
        return $this->asyncMode;
    }

    /**
     * While serving HTTP the worker is a web app, not a console command — even
     * though it was launched from the CLI. Once async serving is active, let the
     * framework and packages (Debugbar, etc.) detect web context. runningInConsole()
     * is Laravel's own gate for this; overriding it here is honest about the runtime
     * mode and avoids relying on the PHP_SAPI fallback.
     */
    public function runningInConsole(): bool
    {
        if ($this->asyncMode) {
            return false;
        }

        return parent::runningInConsole();
    }

    /**
     * Switch the container over to per-coroutine resolution of scoped services.
     *
     * Call once per worker, after the framework has finished bootstrapping: what
     * the providers configured during boot is captured here, and everything that
     * cached a boot-time instance is invalidated. Calling it earlier means the
     * providers configure objects nobody will ever see again.
     */
    public function enableAsyncMode(): void
    {
        $this->asyncMode = true;

        $config = $this->resolved('config') ? $this->make('config') : null;

        if ($config !== null) {
            $this->cacheConfiguredScopedServices($config->get('async.scoped_services', []));

            // Bootstrap is over, so this is the list. Without the latch an application
            // that scopes nothing — the shipped default — re-reads the config on every
            // resolve that misses, which is most of the hundreds a request makes.
            $this->scopedConfigIsFinal = true;
        }

        $this->captureScopedPrototypes();
        $this->reportPrematureAsyncMode();

        FacadeCache::stopCaching();

        foreach ($this->scopedAliases() as $alias) {
            $this->proxyFacade($alias);
        }

        $this->shareFacadeRoots();

        $this->diagnostics = $config !== null && (bool) $config->get('async.diagnostics', false);

        if ($this->diagnostics) {
            $this->reportScopedServiceRisks();
            $this->reportPerRequestCaptures();
        }
    }

    public function scopedSingleton(string $abstract, Closure $factory): void
    {
        $this->scopedBindings[$this->getAlias($abstract)] = $factory;
        $this->markPerRequest($abstract);
    }

    /**
     * Record the extender even when the service has already been resolved.
     *
     * The container skips that for a resolved service, and for a shared one it is
     * right: decorating the single instance is the whole job, there will be no second
     * resolve. A scoped service is resolved again in every coroutine, so the extender
     * has to be kept — otherwise the decoration reaches the boot-time object and
     * nobody else, silently.
     */
    public function extend($abstract, Closure $closure)
    {
        $alias = $this->getAlias($abstract);

        if (! isset($this->instances[$alias]) || ! $this->isScopedAlias($alias)) {
            parent::extend($abstract, $closure);

            return;
        }

        $this->extenders[$alias][] = $closure;

        // While serving, the boot-time instance is nobody's service any more: it is the
        // template coroutines are seeded from. Decorating it keeps template and
        // coroutine alike, so a seeder still recognises what it is handed.
        $decorated = $closure($this->instances[$alias], $this);

        $this->instances[$alias] = $decorated;

        if ($this->asyncMode) {
            // Both, and in step: the template is what seeders are handed, and a
            // container instance that differs from it reads as a deliberate
            // replacement — which this is not.
            $this->scopedPrototypes[$alias] = $decorated;

            return;
        }

        $this->rebound($alias);
    }

    /**
     * Teach the container how to carry boot-time configuration onto per-coroutine
     * instances of a scoped service.
     *
     * The seeder receives the freshly built instance and the boot-time one, and is
     * expected to copy across registrations only — never per-request state, which is
     * the very thing scoping exists to keep apart.
     *
     * Without a seeder, a scoped service is whatever its factory returns: anything a
     * provider did to the object after construction (extend(), viaRequest(), setters)
     * stays behind on the boot-time instance.
     */
    public function scopedSeeder(string $abstract, Closure $seeder): void
    {
        $this->scopedSeeders[$this->getAlias($abstract)] = $seeder;
    }

    /**
     * Store the configured list under the same aliases resolution uses.
     *
     * config/async.php is written in terms of class names, and half the framework's
     * services answer to a short alias instead — a service listed as SessionManager::class
     * would never match the 'session' the container asks about, and would stay shared
     * with nothing to show for it.
     *
     * @param  string[]  $abstracts
     */
    private function cacheConfiguredScopedServices(array $abstracts): void
    {
        $this->scopedServiceCache = [];

        foreach ($abstracts as $abstract) {
            $this->scopedServiceCache[$this->getAlias($abstract)] = true;
            $this->markPerRequest($abstract);
        }
    }

    /**
     * 'request' is always resolvable: from context, instances, or fallback.
     *
     * This keeps code that checks bound('request') during bootstrap from crashing,
     * before any HTTP request exists — AuthServiceProvider::registerRequestRebindHandler()
     * among it. It also makes rebinding('request', ...) return make('request') rather
     * than null, which UrlGenerator::__construct requires when the exception handler
     * renders an error before the first request is served.
     */
    /**
     * Replace a service with this object for as long as the alias lives.
     *
     * Inside a request, a per-request alias is replaced for that request alone: the object
     * goes into the request's context and nothing shared is touched, so the next request
     * resolves its own again. A tenancy switch or `actingAs` in one request must not hand its
     * object to every request the worker serves afterwards. Rebinding callbacks do not run for
     * such a replacement: they were registered on shared objects, which would then hold this
     * request's object.
     *
     * Outside any request (the worker itself, a test swapping a service for a mock) the
     * object answers everybody, per-request alias or not: the per-request lookup would
     * otherwise ignore the replacement entirely. A shared alias replaced from inside a request
     * is shared too, which is what a shared service is; async.diagnostics reports it once.
     *
     * 'request' is exempt from both: the kernel rebinds it on every request, and that is the
     * scoping working, not somebody overriding it.
     */
    public function instance($abstract, $instance)
    {
        $alias = $this->getAlias($abstract);

        if (! $this->asyncMode || $alias === 'request') {
            return parent::instance($abstract, $instance);
        }

        $key = $this->perRequestKey($alias);
        $context = RequestContext::current();

        if ($context === root_context()) {
            $this->replacedInstances[$alias] = true;

            return parent::instance($abstract, $instance);
        }

        if ($key !== null) {
            $context->set($key, $instance, replace: true);

            return $instance;
        }

        $this->reportSharedReplacement($alias);
        $this->replacedInstances[$alias] = true;

        return parent::instance($abstract, $instance);
    }

    public function bound($abstract): bool
    {
        $alias = $this->getAlias($abstract);

        if ($alias === 'request') {
            return true;
        }

        return parent::bound($abstract) || $this->requestHolds($alias);
    }

    /**
     * Drop the object instance() put in, from the request when called inside one.
     */
    public function forgetInstance($abstract)
    {
        $alias = $this->getAlias($abstract);

        if ($this->asyncMode && $alias !== 'request' && RequestContext::current() !== root_context()) {
            $key = $this->perRequestKey($alias);

            if ($key !== null) {
                RequestContext::current()->unset($key);

                return;
            }
        }

        unset($this->replacedInstances[$alias]);

        parent::forgetInstance($abstract);
    }

    /**
     * Whether the current request holds its own object for a per-request alias.
     */
    private function requestHolds(string $alias): bool
    {
        if (! $this->asyncMode) {
            return false;
        }

        $key = $this->perRequestKey($alias);

        return $key !== null && RequestContext::current()->find($key) !== null;
    }

    private function reportSharedReplacement(string $alias): void
    {
        if (! $this->diagnostics || isset($this->reportedSharedReplacements[$alias])) {
            return;
        }

        $this->reportedSharedReplacements[$alias] = true;

        error_log(
            "[async] instance('{$alias}') was called inside a request, and '{$alias}' is shared: "
            .'every request the worker serves gets this object. List it in async.scoped_services '
            .'to keep a replacement inside the request that made it'
        );
    }

    public function offsetGet($key): mixed
    {
        $alias = $this->getAlias($key);

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

        // Parameters mean a contextual build, which the container answers with a fresh
        // object every time and never caches. Serving it from the context would hand
        // back an object built for somebody else's parameters.
        if ($this->asyncMode && $parameters === []) {
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
            $fromContext = RequestContext::current()->find(ScopedService::REQUEST);
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
        $key = $this->perRequestKey($alias);

        if ($key === null) {
            return null;
        }

        $ctx = RequestContext::current();
        $ctxKey = $key;

        // The request's own object comes first: whatever is there was built for this
        // request or put in by its own instance() call.
        $instance = $ctx->find($ctxKey);

        if ($instance !== null) {
            return $instance;
        }

        // instance() from outside any request names the exact object to use, which outranks
        // scoping — otherwise a test swapping a service for a mock would be answered with the
        // real one.
        if (isset($this->replacedInstances[$alias]) && isset($this->instances[$alias])) {
            return $this->instances[$alias];
        }

        $instance = $this->whileBuildingScoped(function () use ($alias) {
            if (isset($this->scopedBindings[$alias])) {
                $built = ($this->scopedBindings[$alias])($this);
            } else {
                $bindings = $this->getBindings();
                if (isset($bindings[$alias])) {
                    $concrete = $bindings[$alias]['concrete'];
                    // Same call shape as Container::build(), which hands the factory the
                    // parameter overrides as a second argument.
                    $built = $concrete instanceof \Closure ? $concrete($this, []) : $this->build($concrete);
                } elseif (class_exists($alias)) {
                    // A class the container was never told about — #[Scoped] is discovered
                    // on first resolve, so it arrives here with no binding behind it.
                    // Falling through would let the container keep it as a singleton.
                    $built = $this->build($alias);
                } else {
                    // No factory registered (e.g. 'request' is stored in instances[], not
                    // bindings[]). null says so to the caller below.
                    return null;
                }
            }

            // extend() registrations live outside the factory, so building from the
            // concrete alone would silently drop them for scoped services only. They run
            // before seeding, because a decorating extender replaces the object and the
            // registrations have to land on whatever the coroutine will actually use.
            foreach ($this->getExtenders($alias) as $extender) {
                $built = $extender($built, $this);
            }

            return $built;
        });

        if ($instance === null) {
            // Fall through to parent::resolve, which handles instances[] correctly.
            return null;
        }

        $this->seedScopedInstance($alias, $instance);

        // A sibling coroutine of the same scope may have got here first while this one
        // was suspended inside the factory. Its instance is already the one the context
        // hands out, so this one is dropped rather than fought over.
        $winner = $ctx->find($ctxKey);

        if ($winner !== null) {
            return $winner;
        }

        // The instance goes into the context before the callbacks run, the way the
        // container publishes to $instances before firing them: a callback is free to
        // resolve the same alias again, and it has to reach this object rather than
        // build a second one and collide on the context key.
        //
        // Except at the root, where find() would reach it from every request that ever
        // follows — the sharing this whole mechanism exists to prevent. A resolve from
        // there gets its object and nobody inherits it.
        if ($ctx !== root_context()) {
            $ctx->set($ctxKey, $instance);
        }

        // Adapters registered via afterResolving() (e.g. registerSessionAdapter) have to
        // fire here too: parent::resolve() is bypassed for scoped services, and it is the
        // only other place that fires them.
        $this->fireResolvingCallbacks($alias, $instance);

        return $instance;
    }

    /**
     * In async mode an object is never kept in step with the current request.
     *
     * refresh() exists for an object that outlives the request it was built for; a
     * coroutine has none. What it leaves behind is real damage: the subscription goes
     * into the shared container, so it survives its coroutine, is invoked on every
     * later request, and holds its target — a guard, and the user inside it — alive
     * forever. Upstream registers one for every guard it builds.
     *
     * rebinding() is refused on the same grounds, but only while a per-request factory
     * is running: see rebinding() below.
     */
    public function refresh($abstract, $target, $method)
    {
        if (! $this->asyncMode) {
            return parent::refresh($abstract, $target, $method);
        }

        return $this->currentInstanceOf($abstract);
    }

    /**
     * Drop a rebinding subscription made from inside a per-request factory.
     *
     * Everywhere else it is kept: a subscription registered by bootstrap is registered
     * once, by code that runs once. One registered while a per-request service is built
     * is registered again by every request, and the list belongs to the container, so it
     * grows for as long as the worker lives. Laravel's own url extender registers one on
     * every resolve of a per-request generator, which is how this was found.
     *
     * Nothing is lost by refusing it. A subscription keeps a long-lived object in step
     * with the current request, and an object built for one request is built with that
     * request already in hand.
     */
    public function rebinding($abstract, Closure $callback)
    {
        if (! $this->buildingScoped()) {
            return parent::rebinding($abstract, $callback);
        }

        return $this->currentInstanceOf($abstract);
    }

    /**
     * What refresh() and rebinding() answer with: the instance, and only when there is
     * one, because callers do use the return value.
     */
    private function currentInstanceOf(string $abstract): mixed
    {
        return $this->bound($abstract) ? $this->make($abstract) : null;
    }

    /**
     * Whether a per-request factory is running above this call, in this coroutine.
     *
     * The depth is kept in coroutine_context(), which belongs to one coroutine and is
     * inherited by nobody, unlike the request's context, which every coroutine of the
     * request shares. Either a property of the container or the request's context would
     * let one coroutine answer this question for another the moment a factory suspends
     * mid-build. Only the build path pays for it: a service already in the context never
     * gets here.
     */
    private function buildingScoped(): bool
    {
        return ((int) (coroutine_context()->findLocal(self::BUILDING_SCOPED) ?? 0)) > 0;
    }

    /**
     * Run $build with buildingScoped() true for this coroutine.
     */
    private function whileBuildingScoped(callable $build): mixed
    {
        $context = coroutine_context();
        $depth   = (int) ($context->findLocal(self::BUILDING_SCOPED) ?? 0);

        $context->set(self::BUILDING_SCOPED, $depth + 1, replace: true);

        try {
            return $build();
        } finally {
            if ($depth === 0) {
                $context->unset(self::BUILDING_SCOPED);
            } else {
                $context->set(self::BUILDING_SCOPED, $depth, replace: true);
            }
        }
    }

    /**
     * Register a callback to run when the request that registered it finishes.
     *
     * Upstream keeps one list on the container and never empties it. Under a worker that
     * means a callback registered while serving runs again at the end of every later
     * request — N times on the Nth — and the list grows for as long as the process lives,
     * holding whatever its closures captured. terminating() is public API and controllers
     * and middleware call it once per request, so the growth is the normal case.
     *
     * Outside a request the list is still the container's: an artisan command has no
     * request to attach a callback to.
     */
    public function terminating($callback)
    {
        $callbacks = $this->requestCallbacks();

        if ($callbacks === null) {
            return parent::terminating($callback);
        }

        $callbacks->add($callback);

        return $this;
    }

    /**
     * Run the process-wide terminating callbacks, then this request's own.
     *
     * The process-wide ones were registered by bootstrap, once, and upstream runs them
     * at the end of every request the same way — in php-fpm the process is one request.
     */
    public function terminate()
    {
        parent::terminate();

        $callbacks = $this->requestCallbacks();

        if ($callbacks === null) {
            return;
        }

        while (($queued = $callbacks->drain()) !== []) {
            foreach ($queued as $callback) {
                $this->call($callback);
            }
        }

        RequestContext::current()->unset(self::TERMINATING);
    }

    /**
     * The terminating list of the request being served, or null when there is no request.
     */
    private function requestCallbacks(): ?PerRequestCallbacks
    {
        if (! $this->asyncMode) {
            return null;
        }

        $context = RequestContext::current();

        if ($context === root_context()) {
            return null;
        }

        $callbacks = $context->find(self::TERMINATING);

        if (! $callbacks instanceof PerRequestCallbacks) {
            $callbacks = new PerRequestCallbacks();

            $context->set(self::TERMINATING, $callbacks);
        }

        return $callbacks;
    }

    /**
     * The instance bootstrap left behind for a per-request alias, or null if it never
     * resolved one.
     *
     * A per-request factory that has to keep boot-time configuration clones it: the
     * object bootstrap configured is the only place that configuration exists.
     */
    public function scopedPrototype(string $abstract): ?object
    {
        return $this->scopedPrototypes[$this->getAlias($abstract)] ?? null;
    }

    /**
     * Hand the boot-time configuration of a scoped service to the instance this
     * coroutine has just built, if the service registered a way to transfer it.
     */
    private function seedScopedInstance(string $alias, object $instance): void
    {
        $seeder    = $this->scopedSeeders[$alias] ?? null;
        $prototype = $this->scopedPrototypes[$alias] ?? null;

        if ($seeder === null || $prototype === null) {
            return;
        }

        $seeder($instance, $prototype);
    }

    /**
     * Remember what bootstrap left in the container for every scoped alias.
     *
     * Only instances that are already resolved are taken. Resolving one here would
     * happen with async mode switched on, which stores it in the root context — where
     * find() reaches it from every coroutine and the sharing we are undoing comes back.
     */
    private function captureScopedPrototypes(): void
    {
        foreach ($this->scopedAliases() as $alias) {
            // Never a second time: extend() keeps the template up to date while serving,
            // and re-reading the container instance would undo that.
            if (isset($this->instances[$alias]) && ! isset($this->scopedPrototypes[$alias])) {
                $this->scopedPrototypes[$alias] = $this->instances[$alias];
            }
        }
    }

    /**
     * Report async mode being switched on with providers registered but not booted.
     *
     * Unconditional, unlike the diagnostics below: every boot-time registration made
     * from that point on is lost, the loss is silent, and by the time anything shows
     * it looks like the registration was never written. The HTTP kernel stands in for
     * "this is a real application": a bare container assembled by a test has no
     * providers of its own to lose.
     */
    private function reportPrematureAsyncMode(): void
    {
        if ($this->isBooted() || ! $this->bound(HttpKernel::class)) {
            return;
        }

        error_log(
            '[async] async mode was enabled before the application booted: providers configure '
            .'objects no coroutine inherits, so their boot-time registrations are lost'
        );
    }

    /**
     * Warn about configuration that bootstrap made and no coroutine will inherit.
     *
     * The failure is invisible at runtime: the service resolves, behaves like a freshly
     * constructed one, and fails much later wherever the lost registration was supposed
     * to be used.
     */
    private function reportScopedServiceRisks(): void
    {
        foreach (array_keys($this->scopedPrototypes) as $alias) {
            // The package's own scoped services are built by factories that configure
            // them in full; warning about those would be crying wolf on every start.
            if (ScopedService::tryFrom($alias) !== null) {
                continue;
            }

            if (isset($this->scopedSeeders[$alias]) || isset($this->scopedBindings[$alias])) {
                continue;
            }

            error_log(
                "[async] scoped service '{$alias}' was configured during bootstrap but has no "
                .'scopedSeeder()/scopedSingleton(); coroutines will get an unconfigured instance'
            );
        }
    }

    /**
     * Shared services that hold an object belonging to a single request.
     *
     * This is the pattern behind half the isolation bugs found so far: a singleton takes
     * a per-request service in its constructor, keeps it in a property, and answers every
     * later request through the first request's object. StartSession was found this way
     * only after two concurrent logins left with the same session cookie.
     *
     * Only objects that exist can be found in anything, so the answer is about the moment
     * it is asked. At worker start that means whatever bootstrap resolved; during a
     * request it means that request's own services as well, and those are the ones a
     * lazily built singleton captures. Asked before either, the answer is empty and says
     * nothing about the application.
     *
     * @return array<string, array<string, string>> shared alias => (path to the captured
     *   object => the per-request alias it serves)
     */
    public function perRequestCaptures(): array
    {
        $identity = $this->perRequestIdentity();

        if ($identity === []) {
            return [];
        }

        $audit = PerRequestCaptureAudit::against($identity, [$this]);
        $found = [];

        foreach ($this->instances as $alias => $instance) {
            if (! is_object($instance) || $instance === $this || $audit->isPerRequest($instance)) {
                continue;
            }

            $captures = $audit->capturesIn($instance);

            if ($captures !== []) {
                $found[$alias] = $captures;
            }
        }

        return $found;
    }

    /**
     * Every object that currently stands for a per-request alias.
     *
     * A per-request instance lives in the request's context and never in the container,
     * so $instances says nothing about it; bootstrap's own instance is the exception,
     * and it is kept as the scoped prototype. Both are listed, because a singleton built
     * at boot captured the first and one built during a request captures the second.
     *
     * @return array<string, object[]> alias => the objects serving it
     */
    private function perRequestIdentity(): array
    {
        $context  = RequestContext::current();
        $identity = [];

        foreach ($this->scopedAliases() as $alias) {
            $objects = [];
            $live    = $context->find($this->perRequestKeys[$alias]);

            if (is_object($live)) {
                $objects[] = $live;
            }

            if (isset($this->scopedPrototypes[$alias])) {
                $objects[] = $this->scopedPrototypes[$alias];
            }

            if ($objects !== []) {
                $identity[$alias] = $objects;
            }
        }

        return $identity;
    }

    private function reportPerRequestCaptures(): void
    {
        foreach ($this->perRequestCaptures() as $alias => $captures) {
            foreach ($captures as $path => $captured) {
                error_log(
                    "[async] shared service '{$alias}' holds the per-request '{$captured}' in ->{$path}; "
                    .'every later request is served through the first one'
                );
            }
        }
    }

    /**
     * Whether this alias gets a fresh instance per coroutine.
     *
     * Fills the config-declared half of the answer on first use, because extend() can
     * run long before async mode is switched on and the cache is built.
     */
    private function isScopedAlias(string $alias): bool
    {
        return $this->perRequestKey($alias) !== null;
    }

    /**
     * The context key this alias is stored under, or null if it is not per-request.
     *
     * One hash lookup answers both questions, because this runs on every resolve the
     * application makes — the check used to cost more than the work it guarded.
     *
     * The map is kept current rather than snapshotted: Laravel appends to its own
     * scoped list while running (a deferred provider calling scoped(), the #[Scoped]
     * attribute discovered on first resolve), and a service that becomes per-request
     * after startup must not stay shared.
     */
    private function perRequestKey(string $alias): ScopedService|string|null
    {
        if (count($this->scopedInstances) !== $this->scopedInstancesSeen) {
            $this->absorbScopedInstances();
        }

        if (isset($this->perRequestKeys[$alias])) {
            return $this->perRequestKeys[$alias];
        }

        // Reading the config resolves a service, which asks this question again, so the
        // guard is on re-entry. An empty answer is retried rather than latched, because
        // it may only mean the package config has not been merged yet — until
        // enableAsyncMode() declares the list final, after which retrying would cost a
        // config read on every resolve that is not per-request.
        if (! $this->scopedConfigIsFinal
            && $this->scopedServiceCache === []
            && ! $this->readingScopedConfig
            && $this->resolved('config')) {
            $this->readingScopedConfig = true;

            try {
                $this->cacheConfiguredScopedServices($this->make('config')->get('async.scoped_services', []));
            } finally {
                $this->readingScopedConfig = false;
            }

            return $this->perRequestKeys[$alias] ?? null;
        }

        return null;
    }

    /**
     * Take in whatever Laravel has added to its own scoped list since the last look.
     */
    private function absorbScopedInstances(): void
    {
        $this->scopedInstancesSeen = count($this->scopedInstances);

        foreach ($this->scopedInstances as $scoped) {
            // scoped() also accepts a closure, whose return types name the services;
            // those are left out rather than guessed at, and stay shared.
            if (is_string($scoped)) {
                $this->markPerRequest($scoped);
            }
        }
    }

    /**
     * Declare an alias per-request, under the context key it will be stored with.
     */
    private function markPerRequest(string $abstract): void
    {
        $alias = $this->getAlias($abstract);

        if (isset($this->perRequestKeys[$alias])) {
            return;
        }

        $this->perRequestKeys[$alias] = ScopedService::tryFrom($alias) ?? $alias;

        // Laravel adds to its own scoped list while the worker runs — a deferred provider
        // calling scoped(), the #[Scoped] attribute found on first resolve — and a facade
        // of such an alias has usually cached an instance by then.
        if ($this->asyncMode) {
            $this->proxyFacade($alias);
        }
    }

    /**
     * Make the facade of a per-request alias ask the container on every call.
     *
     * A facade keeps the instance it resolved in a static array shared by the whole
     * process, so the first coroutine to touch one decides what every later request
     * gets. The entry is replaced with a proxy that resolves per coroutine instead,
     * which needs no list of facade names.
     *
     * The proxy lives in the facade cache and nowhere else. A proxy returned from
     * offsetGet() would also reach the constructors that take $app['redirect'] and
     * $app['cookie'] as typed parameters, and it satisfies neither type.
     */
    private function proxyFacade(string $alias): void
    {
        FacadeCache::put($alias, new ScopedServiceProxy(fn () => $this->make($alias)));
    }

    /**
     * Let the container own the facade roots nobody registered.
     *
     * With the facade cache off a facade asks the container on every call, which is what
     * makes a per-request alias answer per coroutine. A root the container does not know
     * is rebuilt on every call as well, and whatever was set on it is lost with the
     * previous object: Laravel registers neither `Illuminate\Http\Client\Factory` nor
     * `Illuminate\Process\Factory`, yet both keep their global middleware, their fakes and
     * their stray-call guard on the instance. Registering them makes the container answer
     * what caching used to answer, one root per worker, and lifetime is decided there for
     * everything else already.
     *
     * The registration is lazy, so a root nobody uses is never built, and a root that
     * cannot be built still fails at first use rather than at start-up. Per-request
     * aliases, roots the container already knows and keys that are not class names are
     * left alone; a key like `view` belongs to a provider that is simply absent, and
     * inventing a binding for it would answer `bound()` for code that asks whether the
     * provider is there.
     */
    private function shareFacadeRoots(): void
    {
        foreach (FacadeRoots::accessors() as $accessor) {
            $alias = $this->getAlias($accessor);

            if ($this->bound($alias) || $this->isScopedAlias($alias) || ! class_exists($accessor)) {
                continue;
            }

            $this->singleton($accessor);
        }
    }

    /**
     * Put back the per-request facade entries something removed after start-up.
     *
     * `Illuminate\Foundation\Http\Kernel::sendRequestThroughRouter()` calls
     * `Request::clearResolvedInstance()` before the pipeline runs, so from that point the
     * request facade has no per-coroutine entry and the next coroutine to touch it caches
     * its own request for every other coroutine to read. What makes that harmless is
     * FacadeCache::stopCaching(): with the cache off, a facade whose entry is gone asks
     * the container on every call and the container answers per coroutine. Restoring the
     * entry is the fast path on top — one array read instead of a resolve — and the
     * earliest place to do it is the first middleware, which is why
     * {@see \Spawn\Laravel\Http\Middleware\RestorePerRequestFacades} exists.
     *
     * Entries already in place are left alone, so this costs one hash lookup per
     * per-request alias and no allocation in the normal case.
     */
    public function restorePerRequestFacades(): void
    {
        if (! $this->asyncMode) {
            return;
        }

        foreach ($this->scopedAliases() as $alias) {
            if (! FacadeCache::holdsProxy($alias)) {
                $this->proxyFacade($alias);
            }
        }
    }

    /**
     * @return string[] every alias this container treats as scoped
     */
    private function scopedAliases(): array
    {
        $this->absorbScopedInstances();

        return array_keys($this->perRequestKeys);
    }
}
