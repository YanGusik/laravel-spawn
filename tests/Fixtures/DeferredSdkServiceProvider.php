<?php

namespace Spawn\Laravel\Tests\Fixtures;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

/**
 * A deferred provider of the usual shape: register() adds configuration and a singleton,
 * boot() a listener and a Socialite driver, the way SocialiteProviders' manager does.
 */
final class DeferredSdkServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public const ENDPOINT = 'https://api.example.test';

    public function register(): void
    {
        $this->app['config']->set('sdk.endpoint', self::ENDPOINT);
        $this->app->singleton('sdk.client', fn ($app) => new \ArrayObject(['endpoint' => $app['config']->get('sdk.endpoint')]));
    }

    public function boot(): void
    {
        $this->app['events']->listen('sdk.ping', fn () => 'pong');

        $this->app->make(\Laravel\Socialite\Contracts\Factory::class)
            ->extend('sdk', fn () => new \stdClass());
    }

    public function provides(): array
    {
        return ['sdk.client'];
    }
}
