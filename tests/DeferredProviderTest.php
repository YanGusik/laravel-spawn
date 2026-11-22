<?php

namespace Spawn\Laravel\Tests;

use Spawn\Laravel\Foundation\AsyncApplication;
use Spawn\Laravel\Foundation\WorkerBootstrap;
use Spawn\Laravel\Tests\Fixtures\DeferredSdkServiceProvider;

/**
 * A deferred provider first loaded inside a request (#85, Issue 19). What it registers is
 * worker configuration, not that request's state: the next request has to find it too.
 */
class DeferredProviderTest extends AsyncTestCase
{
    use BootsAsyncApplication;

    private function workerWithDeferredSdk(): AsyncApplication
    {
        $app = $this->bootedApp([]);
        $app->addDeferredServices(['sdk.client' => DeferredSdkServiceProvider::class]);

        WorkerBootstrap::run($app);

        return $app;
    }

    public function test_configuration_a_deferred_provider_sets_reaches_the_next_request(): void
    {
        $app = $this->workerWithDeferredSdk();

        $this->inRequest(fn () => $app->make('sdk.client'));

        $this->assertSame(
            DeferredSdkServiceProvider::ENDPOINT,
            $this->inRequest(fn () => $app['config']->get('sdk.endpoint'))
        );
    }

    public function test_a_listener_a_deferred_provider_registers_reaches_the_next_request(): void
    {
        $app = $this->workerWithDeferredSdk();

        $this->inRequest(fn () => $app->make('sdk.client'));

        $this->assertSame(['pong'], $this->inRequest(fn () => $app['events']->dispatch('sdk.ping')));
    }

    public function test_a_socialite_driver_a_deferred_provider_adds_reaches_the_next_request(): void
    {
        if (! class_exists(\Laravel\Socialite\SocialiteManager::class)) {
            $this->markTestSkipped('laravel/socialite is not installed');
        }

        $app = $this->workerWithDeferredSdk();

        $this->inRequest(fn () => $app->make('sdk.client'));

        $driver = $this->inRequest(
            fn () => $app->make(\Laravel\Socialite\Contracts\Factory::class)->driver('sdk')
        );

        $this->assertInstanceOf(\stdClass::class, $driver);
    }
}
