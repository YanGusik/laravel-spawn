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
    private function worker(array $providers): AsyncApplication
    {
        $app = new AsyncApplication(sys_get_temp_dir());

        $app->instance('files', new Filesystem());
        $app->instance('config', new Repository([
            'async'      => ['scoped_services' => [], 'db_pool' => ['enabled' => false]],
            'cache'      => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
            'permission' => require dirname(__DIR__).'/vendor/spatie/laravel-permission/config/permission.php',
        ]));

        Facade::setFacadeApplication($app);
        Facade::clearResolvedInstances();

        foreach ($providers as $provider) {
            $app->register($provider);
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
}
