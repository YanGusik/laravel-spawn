<?php

namespace TrueAsync\Laravel\Tests;

use function Async\delay;

class ScopedServiceIsolationTest extends AsyncTestCase
{
    public function test_scoped_singleton_creates_new_instance_per_coroutine(): void
    {
        $app = $this->createApp();
        $app->scopedSingleton('my.service', fn() => new \stdClass());

        $results = $this->runParallel([
            'a' => function () use ($app) {
                $instance = $app->make('my.service');
                delay(200);
                // re-resolve in same coroutine — must be same instance (cached in context)
                $same = $app->make('my.service');
                return [$instance, $same];
            },
            'b' => function () use ($app) {
                $instance = $app->make('my.service');
                delay(200);
                return [$instance, $app->make('my.service')];
            },
        ]);

        [$a1, $a2] = $results['a'];
        [$b1, $b2] = $results['b'];

        // within the same coroutine — same instance (scoped singleton)
        $this->assertSame($a1, $a2, 're-resolve in same coroutine must return cached instance');
        $this->assertSame($b1, $b2);

        // across coroutines — different instances
        $this->assertNotSame($a1, $b1, 'different coroutines must get different instances');
    }

    public function test_regular_singleton_is_shared_across_coroutines(): void
    {
        $app = $this->createApp();
        $app->singleton('global.service', fn() => new \stdClass());

        $results = $this->runParallel([
            'a' => fn() => $app->make('global.service'),
            'b' => fn() => $app->make('global.service'),
        ]);

        $this->assertSame(
            $results['a'],
            $results['b'],
            'regular singleton must be the same object across coroutines'
        );
    }

    public function test_laravel_session_is_isolated_per_coroutine(): void
    {
        $app = $this->createApp();
        // bind a simple factory so resolveScoped can create a fresh instance
        $app->bind('session', fn() => new \stdClass());

        $results = $this->runParallel([
            'a' => function () use ($app) {
                delay(200);
                return $app->make('session');
            },
            'b' => function () use ($app) {
                delay(200);
                return $app->make('session');
            },
        ]);

        $this->assertNotSame($results['a'], $results['b']);
    }

    public function test_laravel_auth_is_isolated_per_coroutine(): void
    {
        $app = $this->createApp();
        $app->bind('auth', fn() => new \stdClass());

        $results = $this->runParallel([
            'a' => fn() => $app->make('auth'),
            'b' => fn() => $app->make('auth'),
        ]);

        $this->assertNotSame($results['a'], $results['b']);
    }
}
