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

        $this->registerPermissionAdapter();
        $this->registerInertiaAdapter();
        $this->registerTranslatorAdapter();
        $this->registerSocialiteAdapter();
        $this->registerEventDispatcherAdapter();
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
            $dispatcher = new \Spawn\Laravel\Events\AsyncDispatcher($app);
            return $dispatcher;
        });
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
