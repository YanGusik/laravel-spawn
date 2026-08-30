<?php

namespace Spawn\Laravel;

use Illuminate\Support\ServiceProvider;
use Inertia\Ssr\SsrState;
use Spawn\Laravel\Console\AuditCapturesCommand;
use Spawn\Laravel\Console\FrankenServeCommand;
use Spawn\Laravel\Console\DevServeCommand;
use Spawn\Laravel\Console\TrueAsyncServeCommand;
use Spawn\Laravel\Server\ServerMetrics;

class AsyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerConfigAdapter();
        $this->registerEventDispatcherAdapter();
        $this->registerRouterAdapter();
        $this->registerUrlGeneratorAdapter();
        $this->registerTranslatorAdapter();
        $this->registerSessionAdapter();
        $this->registerAuthAdapter();
        $this->registerDatabaseAdapter();
        $this->registerPermissionAdapter();
        $this->registerInertiaAdapter();
        $this->registerSocialiteAdapter();
        $this->registerDebugbarAdapter();
        $this->registerViewAdapter();
        $this->registerViteAdapter();
        $this->registerLogContextAdapter();
        $this->registerLogAdapter();
        $this->registerTelescopeAdapter();

        // One object per worker; the server it reports for is thread-local to that worker.
        $this->app->singleton(ServerMetrics::class);

        $this->mergeConfigFrom(__DIR__ . '/../config/async.php', 'async');

        $this->commands([
            AuditCapturesCommand::class,
            DevServeCommand::class,
            FrankenServeCommand::class,
            TrueAsyncServeCommand::class,
        ]);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/async.php' => config_path('async.php'),
        ], 'async-config');

        $prepend = static function ($kernel): void {
            if (method_exists($kernel, 'prependMiddleware')) {
                $kernel->prependMiddleware(\Spawn\Laravel\Http\Middleware\RestorePerRequestFacades::class);
            }
        };

        // afterResolving rather than a resolve here: an artisan process has no reason to
        // build the HTTP kernel, and the servers build it on their first request.
        $this->app->afterResolving(\Illuminate\Contracts\Http\Kernel::class, $prepend);

        // The callback fires on a build, and the container answers a resolved singleton
        // without building one. register() forgets a kernel resolved before this provider,
        // but a provider registered after it can resolve one in its own register(), which
        // is over before any boot() runs. prependMiddleware() ignores a repeat, so a kernel
        // both paths reach gets the middleware once.
        if ($this->app->resolved(\Illuminate\Contracts\Http\Kernel::class)) {
            $prepend($this->app->make(\Illuminate\Contracts\Http\Kernel::class));
        }
    }

    private function registerDebugbarAdapter(): void
    {
        if (! class_exists(\Fruitcake\LaravelDebugbar\LaravelDebugbar::class)) {
            return;
        }

        // Serve Debugbar from a context-aware subclass: one shared instance,
        // per-coroutine request state. extend() runs at resolve time, so it wins
        // regardless of provider registration order.
        $this->app->extend(
            \Fruitcake\LaravelDebugbar\LaravelDebugbar::class,
            fn ($debugbar, $app) => new \Spawn\Laravel\Debugbar\AsyncDebugbar($app, $app['request']),
        );
    }

    private function registerAuthAdapter(): void
    {
        // Bootstrap has to configure our subclass, not the stock manager: an
        // application registers its guards on the object (Auth::extend(),
        // Auth::viaRequest()), and only the subclass can tell a coroutine what
        // those registrations were.
        $this->app->singleton('auth', fn ($app) => new \Spawn\Laravel\Auth\AsyncAuthManager($app));

        if (! $this->app instanceof \Spawn\Laravel\Foundation\AsyncApplication) {
            return;
        }

        $this->app->scopedSeeder('auth', function (object $coroutineManager, object $bootManager): void {
            if ($coroutineManager instanceof \Illuminate\Auth\AuthManager
                && $bootManager instanceof \Illuminate\Auth\AuthManager) {
                \Spawn\Laravel\Auth\AsyncAuthManager::seedInto($coroutineManager, $bootManager);
            }
        });
    }

    private function registerPermissionAdapter(): void
    {
        if (! class_exists(\Spatie\Permission\PermissionRegistrar::class)) {
            return;
        }

        $this->app->singleton(
            \Spatie\Permission\PermissionRegistrar::class,
            \Spawn\Laravel\Permission\AsyncPermissionRegistrar::class,
        );
    }

    private function registerInertiaAdapter(): void
    {
        if (! class_exists(\Inertia\ResponseFactory::class)) {
            return;
        }

        $this->app->scopedSingleton(
            SsrState::class,
            fn ($app) => new SsrState(),
        );

        $this->app->singleton(
            \Inertia\ResponseFactory::class,
            \Spawn\Laravel\Inertia\AsyncResponseFactory::class,
        );
    }

    private function registerConfigAdapter(): void
    {
        // Replace the config repository with our async-safe version.
        // Must happen in register() before other providers read config.
        $original = $this->app['config'];
        $async = new \Spawn\Laravel\Config\AsyncConfig($original->all());
        $this->app->instance('config', $async);
    }

    private function registerSocialiteAdapter(): void
    {
        if (! class_exists(\Laravel\Socialite\SocialiteManager::class)) {
            return;
        }

        // SocialiteManager caches drivers with stale request state.
        // Scoped singleton gives each coroutine a fresh manager.
        if ($this->app instanceof \Spawn\Laravel\Foundation\AsyncApplication) {
            $this->app->scopedSingleton(
                \Laravel\Socialite\Contracts\Factory::class,
                fn ($app) => new \Laravel\Socialite\SocialiteManager($app),
            );

            // Socialite::extend('gitlab', ...) in a provider's boot() is the documented
            // way to add a provider it does not ship with.
            $this->app->scopedSeeder(
                \Laravel\Socialite\Contracts\Factory::class,
                \Spawn\Laravel\Foundation\ManagerRegistrations::seed(...),
            );
        }
    }

    private function registerEventDispatcherAdapter(): void
    {
        $this->app->singleton('events', function ($app) {
            return new \Spawn\Laravel\Events\AsyncDispatcher($app);
        });

        // DatabaseServiceProvider::boot() calls Model::setEventDispatcher($app['events'])
        // which runs before our register() binds AsyncDispatcher — but since we're now
        // in register(), the binding is set first. However, if 'events' was already
        // resolved and cached by something else, Model may still get the old instance.
        // We fix this by updating Model after all providers have booted.
        $this->app->booted(function ($app) {
            if (class_exists(\Illuminate\Database\Eloquent\Model::class)) {
                \Illuminate\Database\Eloquent\Model::setEventDispatcher($app->make('events'));
            }
        });
    }


    private function registerDatabaseAdapter(): void
    {
        // Replace the stock Connection subclasses with async-safe versions that
        // store the $transactions counter in coroutine_context() instead of on the
        // shared Connection instance.
        //
        // PDO Pool gives each coroutine its own physical PDO connection, but
        // DatabaseManager caches one Connection object per connection name. Without
        // this fix, concurrent beginTransaction() calls see each other's counter:
        // coroutine B increments an already-1 counter → createSavepoint() on a PDO
        // that never received BEGIN → wrong behavior on all drivers.
        //
        // Connection::resolverFor() is the official Laravel extension point;
        // ConnectionFactory::createConnection() checks it before its switch/match.
        \Illuminate\Database\Connection::resolverFor(
            'mysql',
            fn($pdo, $db, $prefix, $config) => new \Spawn\Laravel\Database\AsyncMySqlConnection($pdo, $db, $prefix, $config),
        );

        \Illuminate\Database\Connection::resolverFor(
            'mariadb',
            fn($pdo, $db, $prefix, $config) => new \Spawn\Laravel\Database\AsyncMariaDbConnection($pdo, $db, $prefix, $config),
        );

        \Illuminate\Database\Connection::resolverFor(
            'pgsql',
            fn($pdo, $db, $prefix, $config) => new \Spawn\Laravel\Database\AsyncPgsqlConnection($pdo, $db, $prefix, $config),
        );

        \Illuminate\Database\Connection::resolverFor(
            'sqlite',
            fn($pdo, $db, $prefix, $config) => new \Spawn\Laravel\Database\AsyncSqliteConnection($pdo, $db, $prefix, $config),
        );

        \Illuminate\Database\Connection::resolverFor(
            'sqlsrv',
            fn($pdo, $db, $prefix, $config) => new \Spawn\Laravel\Database\AsyncSqlServerConnection($pdo, $db, $prefix, $config),
        );
    }

    private function registerSessionAdapter(): void
    {
        // Replace the database session handler with an async-safe version that uses
        // upsert instead of INSERT + catch + UPDATE.
        //
        // In async environments the response is sent before terminate() runs.
        // If the client immediately retries with the same cookie, two coroutines can
        // race to INSERT the same session ID → duplicate key warnings in stock handler.
        // Upsert is atomic, so no race is possible regardless of concurrency.
        $this->app->afterResolving('session', function ($manager) {
            $manager->extend('database', function ($app) {
                $table    = $app['config']['session.table'];
                $lifetime = $app['config']['session.lifetime'];
                $conn     = $app['db']->connection($app['config']['session.connection'] ?? null);

                return new \Spawn\Laravel\Session\AsyncDatabaseSessionHandler($conn, $table, $lifetime, $app);
            });
        });

        if (! $this->app instanceof \Spawn\Laravel\Foundation\AsyncApplication) {
            return;
        }

        // StartSession is a singleton that takes the manager in its constructor, so the
        // first coroutine through the pipeline would decide whose session every later
        // request reads and writes — including which session cookie it leaves with.
        $this->app->scoped(
            \Illuminate\Session\Middleware\StartSession::class,
            fn ($app) => new \Illuminate\Session\Middleware\StartSession(
                $app->make(\Illuminate\Session\SessionManager::class),
                fn () => $app->make(\Illuminate\Contracts\Cache\Factory::class),
            ),
        );

        // A custom session handler is registered on the manager during boot
        // (Session::extend('mongo', ...)), so a coroutine building its own manager
        // would report the driver as unsupported.
        $this->app->scopedSeeder('session', \Spawn\Laravel\Foundation\ManagerRegistrations::seed(...));
    }

    private function registerTelescopeAdapter(): void
    {
        if (! \Composer\InstalledVersions::isInstalled('laravel/telescope')) {
            return;
        }

        if (class_exists(\Laravel\Telescope\Telescope::class, autoload: false)) {
            return;
        }

        spl_autoload_register(function (string $class): void {
            if ($class === 'Laravel\\Telescope\\Telescope') {
                require __DIR__ . '/../overrides/Telescope.php';
                // Enable per-coroutine recording immediately so that
                // Telescope::start() (called during boot) knows to skip
                // the handlingApprovedRequest() check that requires $app['request'].
                \Laravel\Telescope\Telescope::enableAsyncRecording();
            }
        }, prepend: true);
    }

    private function registerRouterAdapter(): void
    {
        $this->app->singleton('router', function ($app) {
            return new \Spawn\Laravel\Routing\AsyncRouter($app['events'], $app);
        });

        // The kernel holds the router it was built with, so one resolved before this
        // provider ran is holding the stock one. Forgetting it is also what lets the
        // afterResolving() hook in boot() fire at all: a callback registered for an
        // already-resolved singleton never runs, and public/index.php resolves the kernel
        // before bootstrapping. RestorePerRequestFacades depends on that.
        if ($this->app->resolved(\Illuminate\Contracts\Http\Kernel::class)) {
            $this->app->forgetInstance(\Illuminate\Contracts\Http\Kernel::class);
        }
    }

    private function registerUrlGeneratorAdapter(): void
    {
        if (! $this->app instanceof \Spawn\Laravel\Foundation\AsyncApplication) {
            return;
        }

        // The stock generator is one object kept in step with the current request through
        // rebinding('request'), so under concurrency every request overwrites the request,
        // the cached root and the cached scheme that all the others are reading. Built per
        // request there is nothing to keep in step: it is constructed with the request it
        // belongs to, and no rebinding moves it onto another.
        $this->app->scopedSingleton('url', function ($app) {
            $routes = $app['router']->getRoutes();

            // The stock factory publishes the collection so that rebinding('routes') has
            // something to rebind. It is the router's own object and never changes.
            if (! $app->bound('routes')) {
                $app->instance('routes', $routes);
            }

            $prototype = $app->scopedPrototype('url');

            // A provider's boot() configures the generator through setters —
            // URL::forceScheme('https'), forceRootUrl(), defaults(), formatHostUsing() —
            // and all of it is instance state a fresh object would not have. Behind a TLS
            // proxy the loss is silent and total: every route() and asset() comes out
            // http. setRequest() is what the framework itself calls to move a generator
            // onto a request, and it clears exactly the two cached strings that are
            // request state.
            if ($prototype instanceof \Illuminate\Routing\UrlGenerator) {
                $url = clone $prototype;
                $url->setRoutes($routes);
                $url->setRequest($app->make('request'));

                return $url;
            }

            return new \Illuminate\Routing\UrlGenerator(
                $routes,
                $app->make('request'),
                $app->make('config')->get('app.asset_url'),
            );
        });

        // The response factory takes the redirector in its constructor, so a shared one
        // flashes through the redirector of whichever request built it — the same defect
        // one level up. It carries no state of its own, so a fresh one per request costs
        // an allocation.
        $this->app->scopedSingleton(
            \Illuminate\Contracts\Routing\ResponseFactory::class,
            fn ($app) => new \Illuminate\Routing\ResponseFactory(
                $app[\Illuminate\Contracts\View\Factory::class],
                $app['redirect'],
            ),
        );
    }

    private function registerViewAdapter(): void
    {
        // Replace the stock View Factory with our async-safe version.
        // ViewServiceProvider is never deferred, so a simple singleton() rebinding
        // works — AsyncServiceProvider always registers after ViewServiceProvider
        // in the provider list, so we overwrite cleanly.
        //
        // One factory per worker, deliberately: templates receive it as $__env,
        // Component::$factory caches it in a static, and MailManager and Markdown keep it
        // in their constructors. What is per-request is the state it writes — see
        // AsyncViewFactory.
        $this->app->singleton('view', function ($app) {
            $factory = new \Spawn\Laravel\View\AsyncViewFactory(
                $app['view.engine.resolver'],
                $app['view.finder'],
                $app['events'],
            );

            $factory->setContainer($app);
            $factory->share('app', $app);

            return $factory;
        });
    }

    private function registerLogContextAdapter(): void
    {
        if (! $this->app instanceof \Spawn\Laravel\Foundation\AsyncApplication) {
            return;
        }

        // ContextServiceProvider registers the repository with Laravel's own scoped(),
        // so the container already gives every request one of its own. What a request
        // does not get is what bootstrap put there — a deployment id, a worker name —
        // because a fresh repository starts empty. Copying it in makes the boot-time
        // context the baseline every request starts from, which is what it is under
        // php-fpm, where bootstrap runs inside the request.
        $this->app->scopedSeeder(
            \Illuminate\Log\Context\Repository::class,
            fn ($fresh, $prototype) => $fresh->add($prototype->all())->addHidden($prototype->allHidden()),
        );
    }

    /**
     * One LogManager per worker, whose channels tag each request's lines with that request's
     * context alone.
     *
     * LogServiceProvider is registered from Application::__construct and is not deferred, so
     * a plain singleton() here replaces its binding for good. The channels stay memoised —
     * a per-request manager would open the log file once per request — and only the context
     * moves, which is the half a request writes.
     */
    private function registerLogAdapter(): void
    {
        if (! $this->app instanceof \Spawn\Laravel\Foundation\AsyncApplication) {
            return;
        }

        $this->app->singleton('log', fn ($app) => new \Spawn\Laravel\Log\AsyncLogManager($app));
    }

    private function registerViteAdapter(): void
    {
        if (! $this->app instanceof \Spawn\Laravel\Foundation\AsyncApplication) {
            return;
        }

        // Vite is one object holding two pieces of request state. The CSP nonce, which a
        // middleware sets per request: shared, a page goes out carrying another request's
        // nonce, and under CSP the browser blocks every script on it — a blank page and
        // nothing in the log. And the preloaded assets, appended while rendering and read
        // back by AddLinkHeadersForPreloadedAssets: shared, the Link: rel=preload header
        // grows on every response for ever, including JSON that rendered nothing.
        //
        // Everything else on the object is configuration, set once at boot through
        // useBuildDirectory(), useHotFile() and the tag attribute resolvers. Cloning the
        // boot-time object carries all of it; flush() empties the assets, and the nonce
        // is whatever boot left, which is normally none.
        $this->app->scopedSingleton(\Illuminate\Foundation\Vite::class, function ($app) {
            $prototype = $app->scopedPrototype(\Illuminate\Foundation\Vite::class);

            if (! $prototype instanceof \Illuminate\Foundation\Vite) {
                return new \Illuminate\Foundation\Vite();
            }

            $vite = clone $prototype;
            $vite->flush();

            return $vite;
        });
    }

    private function registerTranslatorAdapter(): void
    {
        // TranslationServiceProvider is a deferred provider. The naive fix of
        // calling loadDeferredProvider() + singleton() only works when the deferred
        // map is already populated — but ProviderRepository registers eager providers
        // BEFORE calling addDeferredServices(), so the deferred map is empty during
        // our register() and loadDeferredProvider() silently does nothing. Later,
        // addDeferredServices() fills the map, and the first make('translator') call
        // triggers the deferred load which overwrites our singleton().
        //
        // Solution: use extend() instead of singleton(). Extenders are stored in a
        // separate array from bindings — they survive any singleton() rebinding by
        // the deferred provider and wrap whatever factory it registers.
        $this->app->extend('translator', function ($translator, $app) {
            $loader = $app['translation.loader'];
            $locale = $app->getLocale();

            $trans = new \Spawn\Laravel\Translation\AsyncTranslator($loader, $locale);
            $trans->setFallback($app->getFallbackLocale());

            return $trans;
        });
    }
}
