<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Config\Repository;
use Illuminate\Redis\Connectors\PhpRedisConnector;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Facade;
use Spawn\Laravel\Redis\AsyncPhpRedisConnector;
use Spawn\Laravel\Redis\RedisPool;

class RedisPoolTest extends AsyncTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('redis')) {
            $this->markTestSkipped('The redis extension is not loaded.');
        }

        if (! method_exists(\Redis::class, 'getPool')) {
            $this->markTestSkipped('phpredis is not the TrueAsync build (no Redis::getPool()).');
        }
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();

        // The manager and its connections hold each other, so a pooled client outlives this
        // test unless the cycle is collected here. Freeing it in the destructor phase is what
        // the extension expects; an image carrying true_async older than the fix for
        // true-async/php-async#274 segfaults at shutdown instead, after the suite has already
        // reported every test as passed.
        gc_collect_cycles();

        parent::tearDown();
    }

    /** Build an app with a Redis manager and the given pool config. */
    private function appWithRedis(array $poolConfig): \Spawn\Laravel\Foundation\AsyncApplication
    {
        $app = $this->createApp();

        $app->instance('config', new Repository([
            'async' => ['redis_pool' => $poolConfig],
            'database' => ['redis' => []],
        ]));

        $app->singleton('redis', fn ($app) => new RedisManager($app, 'phpredis', [
            'default' => [
                'host' => '127.0.0.1',
                'port' => 6379,
                'database' => 0,
            ],
        ]));

        return $app;
    }

    public function test_pooled_client_is_a_template_not_a_live_connection(): void
    {
        $app = $this->appWithRedis(['enabled' => true, 'min' => 0, 'max' => 4, 'mux' => 0]);
        RedisPool::configure($app);

        // Resolving must not open a socket: in pool mode the object is a template
        // and connections are opened lazily by the pool.
        $client = $app->make('redis')->connection()->client();

        $this->assertNotNull($client->getPool(), 'The client must be created in pool mode');

        $this->expectException(\RedisException::class);
        $client->connect('127.0.0.1', 6379);
    }

    public function test_pool_is_not_used_when_disabled(): void
    {
        $app = $this->appWithRedis(['enabled' => false]);
        RedisPool::configure($app);

        $connector = (fn () => $this->connector())->call($app->make('redis'));

        $this->assertInstanceOf(PhpRedisConnector::class, $connector);
        $this->assertNotInstanceOf(AsyncPhpRedisConnector::class, $connector);
    }

    public function test_connector_is_replaced_when_enabled(): void
    {
        $app = $this->appWithRedis(['enabled' => true, 'max' => 4]);
        RedisPool::configure($app);

        $connector = (fn () => $this->connector())->call($app->make('redis'));

        $this->assertInstanceOf(AsyncPhpRedisConnector::class, $connector);
    }

    public function test_constructor_options_carry_connection_config(): void
    {
        $connector = new AsyncPhpRedisConnector(['enabled' => true, 'min' => 1, 'max' => 8, 'mux' => 2]);

        $options = (fn (array $config) => $this->constructorOptions($config))->call($connector, [
            'host' => 'redis.internal',
            'port' => 6380,
            'database' => 3,
            'username' => 'app',
            'password' => 'secret',
            'timeout' => 1.5,
            'read_timeout' => 2.5,
        ]);

        $this->assertSame('redis.internal', $options['host']);
        $this->assertSame(6380, $options['port']);
        $this->assertSame(3, $options['database']);
        $this->assertSame(['app', 'secret'], $options['auth']);
        $this->assertSame(1.5, $options['connectTimeout']);
        $this->assertSame(2.5, $options['readTimeout']);
        $this->assertSame(
            ['enabled' => true, 'min' => 1, 'max' => 8, 'mux' => 2],
            $options['pool']
        );
    }

    public function test_serializer_and_prefix_reach_pooled_connections(): void
    {
        if (! $this->serverIsUp()) {
            $this->markTestSkipped('No Redis server at 127.0.0.1:6379.');
        }

        $app = $this->appWithRedis(['enabled' => true, 'min' => 0, 'max' => 4, 'mux' => 0]);
        $app->make('config')->set('database.redis', []);

        $manager = $app->make('redis');
        $manager->extend('phpredis', fn () => new AsyncPhpRedisConnector([
            'enabled' => true, 'min' => 0, 'max' => 4, 'mux' => 0,
        ]));

        $connection = $manager->connection();
        $client = $connection->client();
        $client->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
        $client->setOption(\Redis::OPT_PREFIX, 'spawn_pool_test:');

        $results = $this->runParallel([
            'a' => function () use ($client) {
                $client->set('a', ['n' => 1]);
                return $client->get('a');
            },
            'b' => function () use ($client) {
                $client->set('b', ['n' => 2]);
                return $client->get('b');
            },
        ]);

        $this->assertSame(['n' => 1], $results['a']);
        $this->assertSame(['n' => 2], $results['b']);

        $plain = new \Redis();
        $plain->connect('127.0.0.1', 6379, 1.0);
        $this->assertSame(1, $plain->exists('spawn_pool_test:a'));
        $plain->del('spawn_pool_test:a', 'spawn_pool_test:b');
        $plain->close();
    }

    private function serverIsUp(): bool
    {
        $probe = new \Redis();

        try {
            return @$probe->connect('127.0.0.1', 6379, 0.5);
        } catch (\Throwable) {
            return false;
        }
    }
}
