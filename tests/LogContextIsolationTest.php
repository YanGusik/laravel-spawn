<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Log\LogServiceProvider;
use Monolog\Handler\TestHandler;
use Spawn\Laravel\Foundation\AsyncApplication;

use function Async\await;
use function Async\spawn;

/**
 * A request's log context tags that request's lines and no other's.
 *
 * `LogManager` memoises one `Logger` per channel for the life of the worker and
 * `Logger::$context` is a property of it, so upstream's `Log::withContext(['request_id' => …])`
 * — the shape of every request-id middleware — tags every other request's lines too. The
 * channels stay memoised here, because a per-request manager would open the log file once per
 * request; the context moves into the request's own context instead.
 *
 * What a worker shares stays shared: `Log::shareContext()` is how bootstrap adds a deployment
 * id to every line of every request, and the last two cases pin that half.
 */
class LogContextIsolationTest extends AsyncTestCase
{
    use BootsAsyncApplication;

    private const PROBE_CHANNEL = [
        'default'  => 'probe',
        'channels' => ['probe' => ['driver' => 'monolog', 'handler' => TestHandler::class]],
    ];

    public function test_a_sibling_request_gets_none_of_the_tags(): void
    {
        $app = $this->worker();

        $this->runParallel([
            'a' => function () use ($app) {
                $app->make('log')->withContext(['request_id' => 'A']);
                $app->make('log')->info('a');
            },
            'b' => function () use ($app) {
                $app->make('log')->info('b');
            },
        ]);

        $this->assertSame(['request_id' => 'A'], $this->contextOf($app, 'a'));
        $this->assertSame([], $this->contextOf($app, 'b'));
    }

    public function test_a_later_request_gets_none_of_the_tags(): void
    {
        $app = $this->worker();

        $this->inRequest(function () use ($app) {
            $app->make('log')->withContext(['request_id' => 'A']);
            $app->make('log')->info('a');
        });

        $this->inRequest(function () use ($app) {
            $app->make('log')->info('b');
        });

        $this->assertSame([], $this->contextOf($app, 'b'));
    }

    /**
     * A coroutine spawned by a request is part of that request, so it writes the same tags —
     * unlike the Eloquent windows, which belong to one coroutine each.
     */
    public function test_a_coroutine_of_the_request_writes_the_same_tags(): void
    {
        $app = $this->worker();

        $this->inRequest(function () use ($app) {
            $app->make('log')->withContext(['request_id' => 'A']);

            await(spawn(function () use ($app) {
                $app->make('log')->info('child');
            }));
        });

        $this->assertSame(['request_id' => 'A'], $this->contextOf($app, 'child'));
    }

    public function test_withoutContext_drops_this_requests_tags_and_leaves_the_shared_ones(): void
    {
        $app = $this->worker();
        $app->make('log')->shareContext(['deployment' => '42']);

        $this->inRequest(function () use ($app) {
            $app->make('log')->withContext(['request_id' => 'A']);
            $app->make('log')->withoutContext();
            $app->make('log')->info('a');
        });

        $this->assertSame(['deployment' => '42'], $this->contextOf($app, 'a'));
    }

    public function test_what_the_worker_shares_reaches_every_request(): void
    {
        $app = $this->worker();
        $app->make('log')->shareContext(['deployment' => '42']);

        $this->runParallel([
            'a' => function () use ($app) {
                $app->make('log')->withContext(['request_id' => 'A']);
                $app->make('log')->info('a');
            },
            'b' => function () use ($app) {
                $app->make('log')->info('b');
            },
        ]);

        $this->assertSame(['deployment' => '42', 'request_id' => 'A'], $this->contextOf($app, 'a'));
        $this->assertSame(['deployment' => '42'], $this->contextOf($app, 'b'));
    }

    /**
     * The channel is one object for the worker, which is what keeps a log file open once
     * rather than once per request. Isolation that came from rebuilding it would cost that.
     */
    public function test_the_channel_is_still_one_object_for_the_worker(): void
    {
        $app = $this->worker();

        $channels = $this->runParallel([
            'a' => fn () => $app->make('log')->channel('probe'),
            'b' => fn () => $app->make('log')->channel('probe'),
        ]);

        $this->assertSame($channels['a'], $channels['b']);
    }

    /**
     * The context Monolog recorded on the line carrying the given message.
     *
     * @return array<string, mixed>
     */
    private function contextOf(AsyncApplication $app, string $message): array
    {
        $handler = $app->make('log')->channel('probe')->getLogger()->getHandlers()[0];

        foreach (array_reverse($handler->getRecords()) as $record) {
            if ($record->message === $message) {
                return $record->context;
            }
        }

        return ['no line said' => $message];
    }

    private function worker(): AsyncApplication
    {
        $app = $this->bootedApp([LogServiceProvider::class]);
        $app->make('config')->set('logging', self::PROBE_CHANNEL);
        $app->enableAsyncMode();

        return $app;
    }
}
