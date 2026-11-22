<?php

namespace Spawn\Laravel\Tests;

use Spawn\Laravel\Foundation\AsyncApplication;
use Spawn\Laravel\Foundation\WorkerBootstrap;
use Spawn\Laravel\Tests\Fixtures\DeferredSdkServiceProvider;
use Spawn\Laravel\Tests\Fixtures\EventLoadedServiceProvider;

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

    public function test_a_provider_registered_inside_a_request_is_named(): void
    {
        $app = $this->workerWithDeferredSdk();

        $file = tempnam(sys_get_temp_dir(), 'deferred-provider');
        $previous = ini_get('error_log');
        ini_set('error_log', $file);

        try {
            $this->inRequest(function () use ($app): void {
                \Spawn\Laravel\Foundation\RequestContext::begin();
                $app->register(EventLoadedServiceProvider::class);
            });
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        $logged = (string) file_get_contents($file);
        unlink($file);

        $this->assertStringContainsString(EventLoadedServiceProvider::class.' was registered while a request', $logged);
        $this->assertStringContainsString('async.boot_providers', $logged);
    }

    public function test_a_boot_provider_is_registered_at_worker_start(): void
    {
        $app = $this->bootedApp([]);
        $app['config']->set('async.boot_providers', [EventLoadedServiceProvider::class]);

        WorkerBootstrap::run($app);

        $this->assertTrue($this->inRequest(fn () => $app['config']->get('event_loaded.ready')));
    }

    public function test_an_instance_the_application_put_in_for_a_deferred_service_is_kept(): void
    {
        $app = $this->bootedApp([]);
        $app->addDeferredServices(['sdk.client' => DeferredSdkServiceProvider::class]);
        $mine = new \ArrayObject(['endpoint' => 'mine']);
        $app->instance('sdk.client', $mine);

        WorkerBootstrap::run($app);

        // Laravel loads a deferred provider only for a service that has no instance yet.
        $this->assertSame($mine, $this->inRequest(fn () => $app->make('sdk.client')));
    }
}
