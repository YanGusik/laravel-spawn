<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Events\Dispatcher;
use Spawn\Laravel\Events\AsyncDispatcher;

use function Async\delay;

class EventDeferIsolationTest extends AsyncTestCase
{
    // ── Stock Dispatcher: prove the bug ──

    public function test_stock_dispatcher_defer_captures_other_coroutines_events(): void
    {
        $app = $this->createApp();
        $dispatcher = new Dispatcher($app);

        $firedAt = [];

        $dispatcher->listen('test.event', function ($data) use (&$firedAt) {
            $firedAt[$data] = hrtime(true);
        });

        $results = $this->runParallel([
            'a' => function () use ($dispatcher) {
                return $dispatcher->defer(function () use ($dispatcher) {
                    $dispatcher->dispatch('test.event', ['from-a']);
                    delay(200);
                    return 'a-done';
                });
            },
            'b' => function () use ($dispatcher) {
                delay(50);
                // B dispatches while A is inside defer().
                // B expects its event to fire NOW, not deferred.
                $beforeDispatch = hrtime(true);
                $dispatcher->dispatch('test.event', ['from-b']);
                return $beforeDispatch;
            },
        ]);

        // BUG: B's event was captured by A's defer and fired LATER (after A's delay),
        // not at the time B dispatched it.
        $bDispatchTime = $results['b'];
        $bFiredTime = $firedAt['from-b'] ?? 0;
        $delayMs = ($bFiredTime - $bDispatchTime) / 1_000_000;

        $this->assertGreaterThan(100, $delayMs,
            'BUG: B\'s event was deferred by A — fired ' . round($delayMs) . 'ms after dispatch instead of immediately');
    }

    // ── AsyncDispatcher: prove the fix ──

    public function test_async_dispatcher_defer_isolated(): void
    {
        $app = $this->createApp();
        $dispatcher = new AsyncDispatcher($app);
        $dispatcher->bootCompleted();

        $firedAt = [];

        $dispatcher->listen('test.event', function ($data) use (&$firedAt) {
            $firedAt[$data] = hrtime(true);
        });

        $results = $this->runParallel([
            'a' => function () use ($dispatcher) {
                return $dispatcher->defer(function () use ($dispatcher) {
                    $dispatcher->dispatch('test.event', ['from-a']);
                    delay(200);
                    return 'a-done';
                });
            },
            'b' => function () use ($dispatcher) {
                delay(50);
                $beforeDispatch = hrtime(true);
                $dispatcher->dispatch('test.event', ['from-b']);
                return $beforeDispatch;
            },
        ]);

        // B's event fires immediately (not deferred by A)
        $bDispatchTime = $results['b'];
        $bFiredTime = $firedAt['from-b'] ?? 0;
        $delayMs = ($bFiredTime - $bDispatchTime) / 1_000_000;

        $this->assertLessThan(50, $delayMs,
            'B\'s event must fire immediately, not deferred — took ' . round($delayMs) . 'ms');
        $this->assertArrayHasKey('from-a', $firedAt, 'A\'s deferred event must also fire');
    }

    public function test_async_dispatcher_before_boot_behaves_as_stock(): void
    {
        $app = $this->createApp();
        $dispatcher = new AsyncDispatcher($app);

        $fired = false;
        $dispatcher->listen('test', function () use (&$fired) { $fired = true; });
        $dispatcher->dispatch('test');

        $this->assertTrue($fired);
    }
}
