<?php

namespace Spawn\Laravel\Tests;

use function Async\delay;
class DatabaseIsolationTest extends AsyncTestCase
{
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
}
