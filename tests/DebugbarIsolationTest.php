<?php

namespace Spawn\Laravel\Tests;

use DebugBar\DataCollector\MessagesCollector;
use Fruitcake\LaravelDebugbar\LaravelDebugbar;
use Spawn\Laravel\Debugbar\Collectors\AsyncExceptionsCollector;
use Spawn\Laravel\Debugbar\Collectors\AsyncMessagesCollector;
use Spawn\Laravel\Debugbar\Collectors\AsyncTimeDataCollector;

use function Async\delay;

class DebugbarIsolationTest extends AsyncTestCase
{
    private function makeDebugbar(): LaravelDebugbar
    {
        $app = $this->createApp();
        $app->singleton('config', fn () => new \Illuminate\Config\Repository([
            'debugbar' => ['enabled' => true, 'collectors' => []],
        ]));

        $debugbar = new LaravelDebugbar($app, new \Illuminate\Http\Request());
        $debugbar->addCollector(new MessagesCollector());

        return $debugbar;
    }

    // ── Stock singleton: prove the bug ──

    public function test_stock_debugbar_messages_leak_between_coroutines(): void
    {
        $debugbar = $this->makeDebugbar();

        $results = $this->runParallel([
            'a' => function () use ($debugbar) {
                $debugbar->getCollector('messages')->addMessage('from-a');
                delay(200);
                return array_column($debugbar->getCollector('messages')->getMessages(), 'message');
            },
            'b' => function () use ($debugbar) {
                delay(50);
                $debugbar->getCollector('messages')->addMessage('from-b');
                return array_column($debugbar->getCollector('messages')->getMessages(), 'message');
            },
        ]);

        // BUG: B sees A's message in the shared collector
        $this->assertContains('from-a', $results['b'],
            'BUG: coroutine B sees coroutine A\'s debug messages');
    }

    // ── scopedSingleton: prove the fix ──

    public function test_scoped_debugbar_messages_isolated(): void
    {
        $app = $this->createApp();
        $app->singleton('config', fn () => new \Illuminate\Config\Repository([
            'debugbar' => ['enabled' => true, 'collectors' => []],
        ]));
        $app->bind('request', fn () => new \Illuminate\Http\Request());

        $app->scopedSingleton(LaravelDebugbar::class, function ($app) {
            $debugbar = new LaravelDebugbar($app, $app->make('request'));
            $debugbar->addCollector(new MessagesCollector());
            return $debugbar;
        });

        $results = $this->runParallel([
            'a' => function () use ($app) {
                $debugbar = $app->make(LaravelDebugbar::class);
                $debugbar->getCollector('messages')->addMessage('from-a');
                delay(200);
                return array_column($debugbar->getCollector('messages')->getMessages(), 'message');
            },
            'b' => function () use ($app) {
                delay(50);
                $debugbar = $app->make(LaravelDebugbar::class);
                $debugbar->getCollector('messages')->addMessage('from-b');
                return array_column($debugbar->getCollector('messages')->getMessages(), 'message');
            },
        ]);

        $this->assertContains('from-a', $results['a'], 'A sees its own message');
        $this->assertNotContains('from-b', $results['a'], 'A must NOT see B\'s message');
        $this->assertContains('from-b', $results['b'], 'B sees its own message');
        $this->assertNotContains('from-a', $results['b'], 'B must NOT see A\'s message');
    }

    // ── Context-backed collectors: ONE shared instance, per-coroutine data ──

    public function test_async_messages_collector_single_instance_isolated(): void
    {
        $collector = new AsyncMessagesCollector(); // one shared instance

        $results = $this->runParallel([
            'a' => function () use ($collector) {
                $collector->addMessage('from-a');
                delay(200);
                return array_column($collector->getMessages(), 'message');
            },
            'b' => function () use ($collector) {
                delay(50);
                $collector->addMessage('from-b');
                return array_column($collector->getMessages(), 'message');
            },
        ]);

        $this->assertSame(['from-a'], $results['a'], 'A sees only its own message');
        $this->assertSame(['from-b'], $results['b'], 'B sees only its own message');
    }

    public function test_async_time_collector_single_instance_isolated(): void
    {
        $collector = new AsyncTimeDataCollector();

        $results = $this->runParallel([
            'a' => function () use ($collector) {
                $collector->addMeasure('m-a', 0.0, 1.0);
                delay(200);
                return array_column($collector->getMeasures(), 'label');
            },
            'b' => function () use ($collector) {
                delay(50);
                $collector->addMeasure('m-b', 0.0, 1.0);
                return array_column($collector->getMeasures(), 'label');
            },
        ]);

        $this->assertSame(['m-a'], $results['a'], 'A sees only its own measure');
        $this->assertSame(['m-b'], $results['b'], 'B sees only its own measure');
    }

    public function test_async_exceptions_collector_single_instance_isolated(): void
    {
        $collector = new AsyncExceptionsCollector();

        $results = $this->runParallel([
            'a' => function () use ($collector) {
                $collector->addThrowable(new \RuntimeException('exc-a'));
                delay(200);
                return array_map(fn (\Throwable $e) => $e->getMessage(), $collector->getExceptions());
            },
            'b' => function () use ($collector) {
                delay(50);
                $collector->addThrowable(new \RuntimeException('exc-b'));
                return array_map(fn (\Throwable $e) => $e->getMessage(), $collector->getExceptions());
            },
        ]);

        $this->assertSame(['exc-a'], $results['a'], 'A sees only its own exception');
        $this->assertSame(['exc-b'], $results['b'], 'B sees only its own exception');
    }
}
