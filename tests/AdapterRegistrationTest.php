<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Spawn\Laravel\AsyncServiceProvider;
use Spawn\Laravel\Foundation\AsyncApplication;
use Spawn\Laravel\Foundation\WorkerBootstrap;
use Spawn\Laravel\Permission\AsyncPermissionRegistrar;
use Spawn\Laravel\Tests\Fixtures\AppViewFactory;
use Spawn\Laravel\Tests\Fixtures\AppViewServiceProvider;
use Spawn\Laravel\View\AsyncViewFactory;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;

/**
 * An adapter replaces the service of a package the application uses, and only then (#85, Issue 25):
 * a class present in vendor/ is not a provider the application registered.
 */
class AdapterRegistrationTest extends AsyncTestCase
{
    /**
     * @param  list<class-string>  $providers  registered in this order
     */
    private function worker(array $providers, ?\Closure $beforeBoot = null): AsyncApplication
    {
        $app = new AsyncApplication(sys_get_temp_dir());

        $app->instance('files', new Filesystem());
        $app->instance('env', 'testing');
        $app->instance('config', new Repository([
            'async'      => ['scoped_services' => [], 'db_pool' => ['enabled' => false]],
            'cache'      => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
            'permission' => require dirname(__DIR__).'/vendor/spatie/laravel-permission/config/permission.php',
            'view'       => ['paths' => [], 'compiled' => sys_get_temp_dir()],
        ]));

        Facade::setFacadeApplication($app);
        Facade::clearResolvedInstances();

        foreach ($providers as $provider) {
            $app->register($provider);
        }

        if ($beforeBoot !== null) {
            $beforeBoot($app);
        }

        $app->boot();
        WorkerBootstrap::run($app);

        return $app;
    }

    public function test_a_worker_without_a_view_provider_starts_and_has_no_view(): void
    {
        $app = $this->worker([EventServiceProvider::class, AsyncServiceProvider::class]);

        $this->assertFalse($app->bound('view'));
    }

    public function test_a_worker_with_spatie_installed_but_not_registered_starts(): void
    {
        $app = $this->worker([EventServiceProvider::class, AsyncServiceProvider::class]);

        $this->assertFalse($app->bound(PermissionRegistrar::class));
    }

    /**
     * @return array<string, array{list<class-string>}>
     */
    public static function providerOrders(): array
    {
        return [
            'spatie first' => [[EventServiceProvider::class, CacheServiceProvider::class, PermissionServiceProvider::class, AsyncServiceProvider::class]],
            'spawn first'  => [[EventServiceProvider::class, CacheServiceProvider::class, AsyncServiceProvider::class, PermissionServiceProvider::class]],
        ];
    }

    /**
     * spatie binds its registrar in boot, after every provider has registered.
     *
     * @dataProvider providerOrders
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('providerOrders')]
    public function test_the_permission_registrar_is_the_adapter_whatever_the_provider_order(array $providers): void
    {
        $app = $this->worker($providers);

        $this->assertInstanceOf(AsyncPermissionRegistrar::class, $app->make(PermissionRegistrar::class));
    }

    public function test_an_application_view_provider_registered_after_the_package_gets_the_adapter(): void
    {
        $app = $this->worker([EventServiceProvider::class, AsyncServiceProvider::class, AppViewServiceProvider::class]);

        $this->assertInstanceOf(AsyncViewFactory::class, $app->make('view'));
        $this->assertSame($app, $app->make('view')->shared('app'));
    }

    public function test_a_view_factory_of_the_application_is_left_and_named(): void
    {
        $logged = $this->logged(function () use (&$app): void {
            $app = $this->worker(
                [EventServiceProvider::class, AsyncServiceProvider::class, AppViewServiceProvider::class],
                static fn (AsyncApplication $app) => $app->singleton('view', fn ($app) => new AppViewFactory(
                    $app['view.engine.resolver'], $app['view.finder'], $app['events'],
                )),
            );
        });

        $this->assertInstanceOf(AppViewFactory::class, $app->make('view'));
        $this->assertStringContainsString('view is '.AppViewFactory::class, $logged);
    }

    public function test_the_inertia_factory_is_the_adapter_when_inertia_registers_after_the_package(): void
    {
        $app = $this->worker([EventServiceProvider::class, AsyncServiceProvider::class, \Inertia\ServiceProvider::class]);

        $this->assertInstanceOf(\Spawn\Laravel\Inertia\AsyncResponseFactory::class, $app->make(\Inertia\ResponseFactory::class));
    }

    public function test_a_service_resolved_before_the_package_registered_is_named(): void
    {
        $logged = $this->logged(function (): void {
            $this->worker([
                EventServiceProvider::class,
                CacheServiceProvider::class,
                PermissionServiceProvider::class,
            ], static function (AsyncApplication $app): void {
                $app->make(PermissionRegistrar::class);
                $app->register(AsyncServiceProvider::class);
            });
        });

        $this->assertStringContainsString(PermissionRegistrar::class.' was resolved before', $logged);
    }

    public function test_deferred_services_left_unloaded_when_async_mode_starts_are_named(): void
    {
        $app = new AsyncApplication(sys_get_temp_dir());
        $app->instance('config', new Repository(['async' => ['scoped_services' => []]]));
        $app->instance(\Illuminate\Contracts\Http\Kernel::class, new \stdClass());
        $app->addDeferredServices(['sdk.client' => \Spawn\Laravel\Tests\Fixtures\DeferredSdkServiceProvider::class]);

        $logged = $this->logged(static fn () => $app->enableAsyncMode());

        $this->assertStringContainsString('deferred providers not loaded', $logged);
        $this->assertStringContainsString(\Spawn\Laravel\Tests\Fixtures\DeferredSdkServiceProvider::class, $logged);
    }

    /**
     * Everything error_log() emits while the callback runs.
     */
    private function logged(callable $act): string
    {
        $file     = tempnam(sys_get_temp_dir(), 'adapter-registration');
        $previous = ini_get('error_log');

        ini_set('error_log', $file);

        try {
            $act();
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        $written = (string) file_get_contents($file);
        unlink($file);

        return $written;
    }
}
