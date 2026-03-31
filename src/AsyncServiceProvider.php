<?php

namespace Spawn\Laravel;

use Illuminate\Support\ServiceProvider;
use Spawn\Laravel\Console\FrankenServeCommand;
use Spawn\Laravel\Console\ServeCommand;

class AsyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerConfigAdapter();
        $this->registerEventDispatcherAdapter();
        $this->registerTranslatorAdapter();
        $this->registerSessionAdapter();
        $this->registerPermissionAdapter();
        $this->registerInertiaAdapter();
        $this->registerSocialiteAdapter();
        $this->registerDebugbarAdapter();
        $this->registerTelescopeAdapter();

        $this->mergeConfigFrom(__DIR__ . '/../config/async.php', 'async');

        $this->commands([
            ServeCommand::class,
            FrankenServeCommand::class,
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
            }
        }, prepend: true);
    }

    private function registerTranslatorAdapter(): void
    {
        $this->app->singleton('translator', function ($app) {
            $loader = $app['translation.loader'];
            $locale = $app->getLocale();

            $trans = new \Spawn\Laravel\Translation\AsyncTranslator($loader, $locale);
            $trans->setFallback($app->getFallbackLocale());

            return $trans;
        });
    }
}
