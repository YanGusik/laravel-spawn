<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\PermissionRegistrar;

use function Async\delay;

class PermissionRegistrarIsolationTest extends AsyncTestCase
{
    private function makeRegistrar(string $class = PermissionRegistrar::class): PermissionRegistrar
    {
        $app = $this->createApp();

        // Minimal config required by PermissionRegistrar
        $app->singleton('config', fn () => new \Illuminate\Config\Repository([
            'permission' => [
                'models' => [
                    'permission' => \Spatie\Permission\Models\Permission::class,
                    'role' => \Spatie\Permission\Models\Role::class,
                ],
                'team_resolver' => \Spatie\Permission\DefaultTeamResolver::class,
                'cache' => [
                    'expiration_time' => 86400,
                    'key' => 'spatie.permission.cache',
                    'store' => 'default',
                ],
                'column_names' => [
                    'role_pivot_key' => 'role_id',
                    'permission_pivot_key' => 'permission_id',
                    'team_foreign_key' => 'team_id',
                ],
                'teams' => false,
            ],
            'cache' => [
                'default' => 'array',
                'stores' => [
                    'array' => ['driver' => 'array'],
                ],
            ],
        ]));

        $cacheManager = new CacheManager($app);
        $app->instance(CacheManager::class, $cacheManager);

        return new $class($cacheManager);
    }

    // ── Stock PermissionRegistrar: prove the bug ──

    public function test_stock_registrar_team_id_leaks_between_coroutines(): void
    {
        $registrar = $this->makeRegistrar();

        $results = $this->runParallel([
            'a' => function () use ($registrar) {
                $registrar->setPermissionsTeamId(1);
                delay(200);
                // After delay, B has overwritten the team ID
                return $registrar->getPermissionsTeamId();
            },
            'b' => function () use ($registrar) {
                delay(50);
                $registrar->setPermissionsTeamId(2);
                return $registrar->getPermissionsTeamId();
            },
        ]);

        $this->assertEquals(2, $results['a'],
            'BUG: request A sees request B\'s team ID');
    }

    public function test_stock_registrar_forget_permissions_affects_all_coroutines(): void
    {
        $registrar = $this->makeRegistrar();

        // Pre-load a fake permissions collection into the registrar
        $reflection = new \ReflectionProperty($registrar, 'permissions');
        $fakePermissions = new Collection(['fake-permission']);
        $reflection->setValue($registrar, $fakePermissions);

        $results = $this->runParallel([
            'a' => function () use ($registrar, $reflection) {
                delay(200);
                // After delay, B has called forgetCachedPermissions
                return $reflection->getValue($registrar);
            },
            'b' => function () use ($registrar) {
                delay(50);
                $registrar->forgetCachedPermissions();
                return 'cleared';
            },
        ]);

        $this->assertNull($results['a'],
            'BUG: request B\'s forgetCachedPermissions cleared A\'s permissions mid-flight');
    }

    // ── AsyncPermissionRegistrar: prove the fix ──

    public function test_async_registrar_team_id_isolated(): void
    {
        $registrar = $this->makeRegistrar(\Spawn\Laravel\Permission\AsyncPermissionRegistrar::class);
        $registrar->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($registrar) {
                $registrar->setPermissionsTeamId(1);
                delay(200);
                return $registrar->getPermissionsTeamId();
            },
            'b' => function () use ($registrar) {
                delay(50);
                $registrar->setPermissionsTeamId(2);
                return $registrar->getPermissionsTeamId();
            },
        ]);

        $this->assertEquals(1, $results['a'], 'A must see its own team ID');
        $this->assertEquals(2, $results['b'], 'B must see its own team ID');
    }

    public function test_async_registrar_team_id_null_by_default(): void
    {
        $registrar = $this->makeRegistrar(\Spawn\Laravel\Permission\AsyncPermissionRegistrar::class);
        $registrar->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($registrar) {
                return $registrar->getPermissionsTeamId();
            },
            'b' => function () use ($registrar) {
                $registrar->setPermissionsTeamId(5);
                return $registrar->getPermissionsTeamId();
            },
        ]);

        $this->assertNull($results['a'], 'Team ID defaults to null per coroutine');
        $this->assertEquals(5, $results['b'], 'B sees its own team ID');
    }

    public function test_async_registrar_clear_permissions_does_not_affect_other_coroutines(): void
    {
        $registrar = $this->makeRegistrar(\Spawn\Laravel\Permission\AsyncPermissionRegistrar::class);
        $registrar->bootCompleted();

        // Pre-load a fake permissions collection
        $reflection = new \ReflectionProperty($registrar, 'permissions');
        $fakePermissions = new Collection(['fake-permission']);
        $reflection->setValue($registrar, $fakePermissions);

        $results = $this->runParallel([
            'a' => function () use ($registrar, $reflection) {
                delay(200);
                // Permissions should still be available (B's clear was per-coroutine)
                return $reflection->getValue($registrar);
            },
            'b' => function () use ($registrar) {
                delay(50);
                $registrar->clearPermissionsCollection();
                return 'cleared';
            },
        ]);

        $this->assertNotNull($results['a'],
            'A\'s permissions must survive B\'s clearPermissionsCollection');
    }

    public function test_async_registrar_before_boot_behaves_as_stock(): void
    {
        $registrar = $this->makeRegistrar(\Spawn\Laravel\Permission\AsyncPermissionRegistrar::class);
        // Note: bootCompleted() NOT called — should behave like stock registrar

        $registrar->setPermissionsTeamId(42);
        $this->assertEquals(42, $registrar->getPermissionsTeamId());

        $registrar->setPermissionsTeamId(null);
        $this->assertNull($registrar->getPermissionsTeamId());
    }

    public function test_async_registrar_falls_back_to_the_team_set_at_boot(): void
    {
        $registrar = $this->makeRegistrar(\Spawn\Laravel\Permission\AsyncPermissionRegistrar::class);
        $registrar->setPermissionsTeamId(7);
        $registrar->bootCompleted();

        $results = $this->runParallel([
            'a' => fn () => $registrar->getPermissionsTeamId(),
            'b' => function () use ($registrar) {
                $registrar->setPermissionsTeamId(null);

                return $registrar->getPermissionsTeamId();
            },
        ]);

        $this->assertSame(7, $results['a'], 'a request that sets none reads the resolver');
        $this->assertNull($results['b'], 'an explicit null clears the team for that request');
    }
}
