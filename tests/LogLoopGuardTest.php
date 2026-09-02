<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Log\LogServiceProvider;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Spawn\Laravel\Foundation\AsyncApplication;
use Spawn\Laravel\Tests\Fixtures\ReloggingTestHandler;
use Spawn\Laravel\Tests\Fixtures\SuspendingTestHandler;

use function Async\await_all;
use function Async\spawn;

/**
 * Concurrent writers lose no record to the guard against a logging loop, and a real loop is
 * still stopped with one warning.
 *
 * Monolog stops a handler that logs through its own logger by counting entry depth, per
 * `Fiber` where there is one and on the `Logger` object where `Fiber::getCurrent()` is null,
 * which it is inside a TrueAsync coroutine. A write that suspends leaves the count raised for
 * every other coroutine writing through the one memoised channel, so eight concurrent writers
 * read as a loop eight deep: two records of 320 reach the handler and the third is the
 * loop warning. `AsyncLogger` counts the depth in the coroutine's own context instead.
 */
class LogLoopGuardTest extends AsyncTestCase
{
    use BootsAsyncApplication;

    private const WRITERS = 8;

    private const RECORDS_PER_WRITER = 40;

    public function test_concurrent_writers_lose_no_record(): void
    {
        $app = $this->worker();

        $writers = [];

        for ($writer = 0; $writer < self::WRITERS; $writer++) {
            $writers[$writer] = function () use ($app, $writer) {
                for ($record = 0; $record < self::RECORDS_PER_WRITER; $record++) {
                    $app->make('log')->info("writer {$writer} record {$record}");
                }
            };
        }

        $this->runParallel($writers);

        $this->assertCount(self::WRITERS * self::RECORDS_PER_WRITER, $this->handler($app)->getRecords());
        $this->assertSame([], $this->loopWarnings($app));
    }

    /**
     * The count is the coroutine's, not the request's: a request that fans out to coroutines
     * writing at the same time is as far from a loop as two requests are.
     */
    public function test_coroutines_of_one_request_count_separately(): void
    {
        $app = $this->worker();

        $this->inRequest(function () use ($app) {
            $children = [];

            for ($child = 0; $child < self::WRITERS; $child++) {
                $children[] = spawn(fn () => $app->make('log')->info("child {$child}"));
            }

            await_all($children);
        });

        $this->assertCount(self::WRITERS, $this->handler($app)->getRecords());
        $this->assertSame([], $this->loopWarnings($app));
    }

    /**
     * A handler that logs through its own logger is the loop Monolog's detection exists for.
     * The bound is Monolog's: the record, one re-entry, then the warning in place of the
     * second, and nothing past it.
     */
    public function test_a_handler_that_logs_through_its_logger_is_stopped_with_one_warning(): void
    {
        $app = $this->worker(ReloggingTestHandler::class);
        $this->handler($app)->relog = fn () => $app->make('log')->info('again');

        $this->inRequest(fn () => $app->make('log')->info('first'));

        $this->assertSame(['first', 'again'], array_slice($this->messages($app), 0, 2));
        $this->assertCount(1, $this->loopWarnings($app));
        $this->assertCount(3, $this->messages($app));
    }

    /**
     * The guard leaves the count where it found it, warning or not: the line after two loops
     * is written like any other. Monolog's own count returns from its warning and from a
     * dropped record without the decrement, so each loop leaves it higher for the life of
     * the logger, and the channel goes quiet.
     */
    public function test_a_line_after_two_loops_is_written(): void
    {
        $app = $this->worker(ReloggingTestHandler::class);
        $handler = $this->handler($app);
        $handler->relog = fn () => $app->make('log')->info('again');

        $this->inRequest(function () use ($app, $handler) {
            $app->make('log')->info('first');
            $app->make('log')->info('first');
            $handler->relog = null;
            $app->make('log')->info('after');
        });

        $messages = $this->messages($app);

        $this->assertSame('after', end($messages));
    }

    /**
     * A log listener that logs is the same loop one layer up, where Monolog's count never
     * sees it: the event fires after Monolog has returned, so with Monolog's count alone the
     * recursion is unbounded. The guard wraps the event with the write and bounds it alike.
     */
    public function test_a_listener_that_logs_on_every_line_is_stopped_with_one_warning(): void
    {
        $app = $this->worker();
        $app->make('log')->listen(fn () => $app->make('log')->info('again'));

        $this->inRequest(fn () => $app->make('log')->info('first'));

        $this->assertSame(['first', 'again'], array_slice($this->messages($app), 0, 2));
        $this->assertCount(1, $this->loopWarnings($app));
        $this->assertCount(3, $this->messages($app));
    }

    /**
     * @return array<int, string>  every recorded message, in the order the handler saw them
     */
    private function messages(AsyncApplication $app): array
    {
        return array_map(fn ($record) => $record->message, $this->handler($app)->getRecords());
    }

    /**
     * @return array<int, string>  the messages of the warnings the guard wrote
     */
    private function loopWarnings(AsyncApplication $app): array
    {
        $warnings = [];

        foreach ($this->handler($app)->getRecords() as $record) {
            if ($record->level === Level::Warning) {
                $warnings[] = $record->message;
            }
        }

        return $warnings;
    }

    private function handler(AsyncApplication $app): TestHandler
    {
        return $app->make('log')->channel('probe')->getLogger()->getHandlers()[0];
    }

    /**
     * @param  class-string<TestHandler>  $handler  what the one channel writes into
     */
    private function worker(string $handler = SuspendingTestHandler::class): AsyncApplication
    {
        $app = $this->bootedApp([LogServiceProvider::class]);
        $app->make('config')->set('logging', [
            'default'  => 'probe',
            'channels' => ['probe' => ['driver' => 'monolog', 'handler' => $handler]],
        ]);
        $app->enableAsyncMode();

        return $app;
    }
}
