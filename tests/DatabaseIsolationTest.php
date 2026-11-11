<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Support\Facades\Facade;
use Spawn\Laravel\AsyncServiceProvider;
use Spawn\Laravel\Session\AsyncDatabaseSessionHandler;

use function Async\delay;
class DatabaseIsolationTest extends AsyncTestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    public function test_database_manager_is_singleton_across_coroutines(): void
    {
        $app = $this->createApp();
        $app->singleton('db', fn() => new \stdClass());

        $results = $this->runParallel([
            'a' => fn() => $app->make('db'),
            'b' => fn() => $app->make('db'),
            'c' => fn() => $app->make('db'),
        ]);

        // All coroutines must share the same DatabaseManager singleton.
        // Physical connection isolation is handled by PDO Pool at C level.
        $this->assertSame($results['a'], $results['b'], 'DatabaseManager must be a singleton shared across coroutines');
        $this->assertSame($results['b'], $results['c']);
    }

    public function test_db_transactions_manager_is_singleton_across_coroutines(): void
    {
        $app = $this->createApp();
        $app->singleton('db.transactions', fn() => new \stdClass());

        $results = $this->runParallel([
            'a' => fn() => $app->make('db.transactions'),
            'b' => fn() => $app->make('db.transactions'),
        ]);

        $this->assertSame($results['a'], $results['b']);
    }

    public function test_custom_db_service_can_be_scoped_via_scopedSingleton(): void
    {
        $app = $this->createApp();
        // If a user explicitly needs per-coroutine DB isolation, they can use scopedSingleton.
        $app->scopedSingleton('db.custom', fn() => new \stdClass());

        $results = $this->runParallel([
            'a' => function () use ($app) {
                $instance = $app->make('db.custom');
                delay(100);
                return [$instance, $app->make('db.custom')];
            },
            'b' => function () use ($app) {
                $instance = $app->make('db.custom');
                delay(100);
                return [$instance, $app->make('db.custom')];
            },
        ]);

        [$a1, $a2] = $results['a'];
        [$b1, $b2] = $results['b'];

        // within coroutine — same instance
        $this->assertSame($a1, $a2);
        $this->assertSame($b1, $b2);

        // across coroutines — different instances
        $this->assertNotSame($a1, $b1);
    }

    /**
     * Verifies that session handlers in different coroutines share the same
     * Connection object (from the shared DatabaseManager singleton), not
     * create a new DB connection per coroutine.
     */
    public function test_session_handler_reuses_db_connection_across_coroutines(): void
    {
        $app = $this->createApp();

        $app->instance('config', new \Illuminate\Config\Repository([
            'async'   => ['scoped_services' => [], 'db_pool' => ['enabled' => false, 'min' => 2, 'max' => 10]],
            'app'     => ['locale' => 'en', 'fallback_locale' => 'en'],
            'session' => [
                'driver'     => 'database',
                'table'      => 'sessions',
                'lifetime'   => 120,
                'connection' => null,
                'encrypt'    => false,
                'serialization' => 'php',
                'cookie'     => 'laravel_session',
            ],
        ]));

        // Single shared Connection mock — DatabaseManager always returns this same instance
        $sharedConnection = $this->createMock(\Illuminate\Database\ConnectionInterface::class);
        $connectionCallCount = 0;

        $db = $this->createMock(\Illuminate\Database\DatabaseManager::class);
        $db->method('connection')->willReturnCallback(function () use ($sharedConnection, &$connectionCallCount) {
            $connectionCallCount++;
            return $sharedConnection;
        });
        $app->instance('db', $db);

        Facade::setFacadeApplication($app);

        foreach ([
            \Illuminate\Session\SessionServiceProvider::class,
            AsyncServiceProvider::class,
        ] as $provider) {
            $app->register($provider);
        }
        $app->boot();

        // Resolve session handlers across parallel coroutines
        $results = $this->runParallel([
            'a' => function () use ($app) {
                $handler = $app->make('session')->driver('database')->getHandler();
                $this->assertInstanceOf(AsyncDatabaseSessionHandler::class, $handler);
                // Access the connection via reflection
                $ref = new \ReflectionProperty(\Illuminate\Session\DatabaseSessionHandler::class, 'connection');
                return spl_object_id($ref->getValue($handler));
            },
            'b' => function () use ($app) {
                $handler = $app->make('session')->driver('database')->getHandler();
                $this->assertInstanceOf(AsyncDatabaseSessionHandler::class, $handler);
                $ref = new \ReflectionProperty(\Illuminate\Session\DatabaseSessionHandler::class, 'connection');
                return spl_object_id($ref->getValue($handler));
            },
        ]);

        // Both coroutines must get the same Connection object (same spl_object_id)
        $this->assertSame(
            $results['a'],
            $results['b'],
            'Session handlers in different coroutines must share the same DB Connection object. ' .
            'If this fails, each coroutine is creating a new database connection for sessions.'
        );
    }
}
