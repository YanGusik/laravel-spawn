<?php

namespace Spawn\Laravel\Tests;

use DebugBar\DataCollector\MessagesCollector;
use Fruitcake\LaravelDebugbar\LaravelDebugbar;

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
}
