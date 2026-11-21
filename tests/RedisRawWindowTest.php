<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\RedisManager;
use Spawn\Laravel\Redis\AsyncPhpRedisConnector;
use stdClass;
use Throwable;

use function Async\suspend;

/**
 * `withoutSerializationOrCompression()` must leave the serializer of every other coroutine
 * alone.
 *
 * Laravel's rate limiter calls it on every throttled request so that its counters are stored
 * as plain integers: it switches the client's serializer and compression off, runs the
 * callback and switches them back. In pool mode the client is the pool's template, whose
 * options every pooled connection takes on acquire, so while the callback waits on Redis every
 * coroutine of the worker reads and writes without them — cached arrays come back as raw
 * bytes, as `false`, or as a TypeError from `RedisStore`.
 */
class RedisRawWindowTest extends AsyncTestCase
{
    private RedisManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('redis') || ! method_exists(\Redis::class, 'getPool')) {
            $this->markTestSkipped('phpredis is not the TrueAsync build (no Redis::getPool()).');
        }

        $probe = new \Redis();

        try {
            if (! @$probe->connect('127.0.0.1', 6379, 0.5)) {
                $this->markTestSkipped('No Redis server at 127.0.0.1:6379.');
            }
        } catch (Throwable) {
            $this->markTestSkipped('No Redis server at 127.0.0.1:6379.');
        }

        $this->manager = new RedisManager(new Container(), 'phpredis', [
            'options' => ['prefix' => 'spawn_raw_window_', 'serializer' => \Redis::SERIALIZER_PHP],
            'default' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 13],
            'plain' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 13, 'serializer' => \Redis::SERIALIZER_NONE],
        ]);
        $this->manager->extend('phpredis', fn () => new AsyncPhpRedisConnector([
            'enabled' => true, 'min' => 0, 'max' => 8, 'mux' => 0,
        ]));
    }

    protected function tearDown(): void
    {
        if (isset($this->manager)) {
            $this->manager->connection()->flushdb();
            unset($this->manager);
        }

        // The manager and its connections hold each other; see RedisPoolTest::tearDown().
        gc_collect_cycles();

        parent::tearDown();
    }

    public function test_a_sibling_keeps_its_serializer_while_another_coroutine_is_raw(): void
    {
        /** @var PhpRedisConnection $connection */
        $connection = $this->manager->connection();
        $connection->set('entity', ['id' => 1]);

        $gate = new stdClass();
        $gate->inside = false;
        $gate->done = false;

        $results = $this->runParallel([
            'raw' => fn () => $connection->withoutSerializationOrCompression(function () use ($connection, $gate) {
                $connection->get('counter');
                $gate->inside = true;
                $this->until(fn () => $gate->done);
            }),
            'sibling' => function () use ($connection, $gate) {
                $this->until(fn () => $gate->inside);

                try {
                    return [$connection->serialized(), $connection->get('entity')];
                } finally {
                    $gate->done = true;
                }
            },
        ]);

        $this->assertSame([true, ['id' => 1]], $results['sibling']);
    }

    public function test_inside_the_window_values_are_raw(): void
    {
        /** @var PhpRedisConnection $connection */
        $connection = $this->manager->connection();

        $inside = $connection->withoutSerializationOrCompression(function () use ($connection) {
            $connection->set('counter', 5);
            suspend();

            return [$connection->serialized(), $connection->incrby('counter', 1), $connection->get('counter')];
        });

        $this->assertSame([false, 6, '6'], $inside);
        $this->assertTrue($connection->serialized(), 'the window closes when the callback returns');
    }

    public function test_a_connection_without_a_serializer_runs_the_callback_as_it_is(): void
    {
        /** @var PhpRedisConnection $connection */
        $connection = $this->manager->connection('plain');
        $client = $connection->client();

        $inside = $connection->withoutSerializationOrCompression(fn () => $connection->client());

        $this->assertSame($client, $inside);
    }

    /**
     * The shape of the report in #85: a throttled request next to a cached read, many times
     * over.
     */
    public function test_rate_limited_requests_beside_cached_reads_get_their_values(): void
    {
        $repository = new Repository(new RedisStore($this->manager, 'cache_', 'default'));
        $limiter = new RateLimiter($repository);
        $repository->put('entity', ['id' => 1], 60);

        $coroutines = [];

        for ($i = 0; $i < 10; $i++) {
            $coroutines["limiter$i"] = function () use ($limiter, $i) {
                for ($j = 0; $j < 10; $j++) {
                    $limiter->hit("throttle:$i:$j", 60);
                    $limiter->attempts("throttle:$i:$j");
                }

                return $limiter->attempts("throttle:$i:0");
            };
            $coroutines["reader$i"] = function () use ($repository) {
                $wrong = [];

                for ($j = 0; $j < 10; $j++) {
                    $value = @$repository->get('entity');

                    if ($value !== ['id' => 1]) {
                        $wrong[] = get_debug_type($value);
                    }
                }

                return $wrong;
            };
        }

        $results = $this->runParallel($coroutines);

        for ($i = 0; $i < 10; $i++) {
            $this->assertSame([], $results["reader$i"], 'a cached array must read back as the array');
            $this->assertSame(1, $results["limiter$i"], 'a limiter counter must count');
        }
    }

    /**
     * Yield until the condition holds. runParallel() caps the whole thing at five seconds, so
     * a condition that never becomes true fails the test instead of hanging the suite.
     */
    private function until(callable $condition): void
    {
        while (! $condition()) {
            suspend();
        }
    }
}
