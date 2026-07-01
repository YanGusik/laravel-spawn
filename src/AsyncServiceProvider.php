<?php

namespace Spawn\Laravel;

use Illuminate\Support\ServiceProvider;
use Inertia\Ssr\SsrState;
use Spawn\Laravel\Console\FrankenServeCommand;
use Spawn\Laravel\Console\DevServeCommand;
use Spawn\Laravel\Console\TrueAsyncServeCommand;

class AsyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerConfigAdapter();
        $this->registerEventDispatcherAdapter();
        $this->registerRouterAdapter();
        $this->registerTranslatorAdapter();
        $this->registerSessionAdapter();
        $this->registerDatabaseAdapter();
        $this->registerPermissionAdapter();
        $this->registerInertiaAdapter();
        $this->registerSocialiteAdapter();
        $this->registerDebugbarAdapter();
        $this->registerViewAdapter();
        $this->registerTelescopeAdapter();

        $this->mergeConfigFrom(__DIR__ . '/../config/async.php', 'async');

        $this->commands([
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

    private function registerDebugbarAdapter(): void
    {
        if (! class_exists(\Fruitcake\LaravelDebugbar\LaravelDebugbar::class)) {
            return;
        }

        // Debugbar collectors accumulate per-request data (queries, events, etc.).
        // scopedSingleton gives each coroutine its own debugbar instance.
        if ($this->app instanceof \Spawn\Laravel\Foundation\AsyncApplication) {
            $this->app->scopedSingleton(
                \Fruitcake\LaravelDebugbar\LaravelDebugbar::class,
                fn ($app) => new \Fruitcake\LaravelDebugbar\LaravelDebugbar($app, $app['request']),
            );
        }
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

        if ($this->app->resolved(\Illuminate\Contracts\Http\Kernel::class)) {
            $this->app->forgetInstance(\Illuminate\Contracts\Http\Kernel::class);
        }
    }

    private function registerViewAdapter(): void
    {
        // Replace the stock View Factory with our async-safe version.
        // ViewServiceProvider is never deferred, so a simple singleton() rebinding
        // works — AsyncServiceProvider always registers after ViewServiceProvider
        // in the provider list, so we overwrite cleanly.
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
