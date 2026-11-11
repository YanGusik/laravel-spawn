<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Spawn\Laravel\View\AsyncViewFactory;
use function Async\delay;

class ViewIsolationTest extends AsyncTestCase
{
    private function makeFactory(string $class = Factory::class): Factory
    {
        $app = $this->createApp();
        $resolver = new EngineResolver();
        $finder = new FileViewFinder(new Filesystem(), []);
        $dispatcher = new Dispatcher($app);

        $factory = new $class($resolver, $finder, $dispatcher);
        // Boot-time shared data (before async mode)
        $factory->share('app', $app);

        return $factory;
    }

    // ── Stock Factory: prove the bug ──

    public function test_stock_factory_share_errors_race(): void
    {
        $factory = $this->makeFactory();

        $errorsA = new ViewErrorBag();
        $errorsA->put('default', new MessageBag(['name' => 'required']));

        $errorsB = new ViewErrorBag();
        $errorsB->put('default', new MessageBag(['email' => 'required']));

        $results = $this->runParallel([
            'a' => function () use ($factory, $errorsA) {
                $factory->share('errors', $errorsA);
                delay(200);
                return $factory->getShared()['errors'];
            },
            'b' => function () use ($factory, $errorsB) {
                delay(50);
                $factory->share('errors', $errorsB);
                return $factory->getShared()['errors'];
            },
        ]);

        $this->assertSame($errorsB, $results['a'],
            'BUG: request A sees request B\'s validation errors');
    }

    // ── AsyncViewFactory: prove the fix ──

    public function test_async_factory_share_errors_isolated(): void
    {
        $factory = $this->makeFactory(AsyncViewFactory::class);
        $factory->bootCompleted(); // server started — switch to per-coroutine mode

        $errorsA = new ViewErrorBag();
        $errorsA->put('default', new MessageBag(['name' => 'required']));

        $errorsB = new ViewErrorBag();
        $errorsB->put('default', new MessageBag(['email' => 'required']));

        $results = $this->runParallel([
            'a' => function () use ($factory, $errorsA) {
                $factory->share('errors', $errorsA);
                delay(200);
                return $factory->getShared()['errors'];
            },
            'b' => function () use ($factory, $errorsB) {
                delay(50);
                $factory->share('errors', $errorsB);
                return $factory->getShared()['errors'];
            },
        ]);

        $this->assertSame($errorsA, $results['a'], 'A must see its own errors');
        $this->assertSame($errorsB, $results['b'], 'B must see its own errors');
    }

    public function test_async_factory_boot_data_preserved(): void
    {
        $factory = $this->makeFactory(AsyncViewFactory::class);
        $factory->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($factory) {
                $factory->share('user', 'Alice');
                return $factory->getShared();
            },
            'b' => function () use ($factory) {
                $factory->share('theme', 'dark');
                return $factory->getShared();
            },
        ]);

        // Both see boot-time 'app'
        $this->assertArrayHasKey('app', $results['a']);
        $this->assertArrayHasKey('app', $results['b']);

        // A sees its own data, not B's
        $this->assertEquals('Alice', $results['a']['user']);
        $this->assertArrayNotHasKey('theme', $results['a']);

        // B sees its own data, not A's
        $this->assertEquals('dark', $results['b']['theme']);
        $this->assertArrayNotHasKey('user', $results['b']);
    }

    public function test_shared_reads_the_requests_value_the_boot_value_and_the_default(): void
    {
        $factory = $this->makeFactory(AsyncViewFactory::class);
        $factory->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($factory) {
                $factory->share('title', 'A');

                return [$factory->shared('title'), $factory->shared('app') !== null, $factory->shared('none', 'd')];
            },
            'b' => fn () => $factory->shared('title'),
        ]);

        $this->assertSame(['A', true, 'd'], $results['a']);
        $this->assertNull($results['b'], 'another request does not see A\'s value');
    }
}
