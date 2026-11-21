<?php

namespace Spawn\Laravel\Tests;

use PDO;
use Spawn\Laravel\Foundation\AsyncApplication;
use Spawn\Laravel\Foundation\WorkerBootstrap;

/**
 * The pool options every worker injects on its way to its first request.
 *
 * One place injects them for all three servers, so the conversion is checked once.
 */
class DatabasePoolOptionsTest extends AsyncTestCase
{
    private function app(array $poolConfig): AsyncApplication
    {
        return $this->appWithConfig([
            'async' => ['db_pool' => $poolConfig],
            'database' => ['connections' => ['mysql' => ['driver' => 'mysql']]],
        ]);
    }

    private function configure(array $poolConfig): array
    {
        $app = $this->app($poolConfig);

        WorkerBootstrap::run($app);

        return $app->make('config')->get('database.connections.mysql.options') ?? [];
    }

    public function test_the_healthcheck_interval_is_converted_from_seconds_to_milliseconds(): void
    {
        $options = $this->configure(['enabled' => true, 'healthcheck_interval' => 15]);

        $this->assertSame(15_000, $options[PDO::ATTR_POOL_HEALTHCHECK_INTERVAL]);
    }

    public function test_the_default_interval_is_converted_too(): void
    {
        $options = $this->configure(['enabled' => true]);

        $this->assertTrue($options[PDO::ATTR_POOL_ENABLED]);
        $this->assertSame(2, $options[PDO::ATTR_POOL_MIN]);
        $this->assertSame(10, $options[PDO::ATTR_POOL_MAX]);
        $this->assertSame(30_000, $options[PDO::ATTR_POOL_HEALTHCHECK_INTERVAL]);
    }

    public function test_zero_still_disables_the_healthcheck(): void
    {
        $options = $this->configure(['enabled' => true, 'healthcheck_interval' => 0]);

        $this->assertSame(0, $options[PDO::ATTR_POOL_HEALTHCHECK_INTERVAL]);
    }

    public function test_a_sub_second_interval_survives_as_milliseconds(): void
    {
        $options = $this->configure(['enabled' => true, 'healthcheck_interval' => 0.5]);

        $this->assertSame(500, $options[PDO::ATTR_POOL_HEALTHCHECK_INTERVAL]);
    }

    public function test_a_disabled_pool_leaves_the_connection_alone(): void
    {
        $this->assertSame([], $this->configure(['enabled' => false]));
    }

    /**
     * The reader of these options is a request coroutine, which inherits nothing from
     * the coroutine that started the worker: written after the switch, they read back as
     * null here, and every coroutine in the worker then shares one PDO handle.
     */
    public function test_the_options_reach_a_request_coroutine(): void
    {
        $app = $this->app(['enabled' => true]);

        WorkerBootstrap::run($app);

        $options = $this->inRequest(fn () => $app->make('config')->get('database.connections.mysql.options'));

        $this->assertIsArray($options, 'the pool options did not reach the request');
        $this->assertArrayHasKey(PDO::ATTR_POOL_ENABLED, $options);
        $this->assertTrue($options[PDO::ATTR_POOL_ENABLED]);
    }

    public function test_a_private_in_memory_sqlite_connection_is_named_at_start_up(): void
    {
        $app = $this->appWithConfig([
            'async' => ['db_pool' => ['enabled' => true]],
            'database' => ['connections' => [
                'memory' => ['driver' => 'sqlite', 'database' => ':memory:'],
                'shared' => ['driver' => 'sqlite', 'database' => 'file:t?mode=memory&cache=shared'],
            ]],
        ]);

        $log = tempnam(sys_get_temp_dir(), 'pool');
        $previous = ini_set('error_log', $log);

        try {
            WorkerBootstrap::run($app);
            $written = (string) file_get_contents($log);
        } finally {
            ini_set('error_log', (string) $previous);
            unlink($log);
        }

        $this->assertStringContainsString("'memory'", $written);
        $this->assertStringNotContainsString("'shared'", $written);
        $this->assertNotEmpty($app->make('config')->get('database.connections.memory.options'),
            'the pool options still go on: a shared handle is the defect the pool prevents');
    }

    /**
     * Laravel merges a read or write entry over the connection with array_merge(), so an entry that names
     * its own options replaces the top-level ones the pool attributes are put in.
     */
    public function test_read_write_and_direct_entries_with_their_own_options_keep_the_pool(): void
    {
        $app = $this->appWithConfig([
            'async' => ['db_pool' => ['enabled' => true]],
            'database' => ['connections' => ['mysql' => [
                'driver' => 'mysql',
                'read' => [['host' => 'r1', 'options' => []], ['host' => 'r2']],
                'write' => ['host' => 'w', 'options' => [PDO::ATTR_TIMEOUT => 3]],
                'direct' => ['host' => 'd', 'options' => []],
            ]]],
        ]);

        WorkerBootstrap::run($app);

        $connection = $app->make('config')->get('database.connections.mysql');

        $this->assertTrue($connection['read'][0]['options'][PDO::ATTR_POOL_ENABLED] ?? false);
        $this->assertArrayNotHasKey('options', $connection['read'][1], 'an entry without options inherits them');
        $this->assertTrue($connection['write']['options'][PDO::ATTR_POOL_ENABLED] ?? false);
        $this->assertSame(3, $connection['write']['options'][PDO::ATTR_TIMEOUT], 'its own options stay');
        $this->assertTrue($connection['direct']['options'][PDO::ATTR_POOL_ENABLED] ?? false);
    }

    /**
     * A service provider's boot() runs before the worker purges the connections it opened, and registers
     * its handler on the connection the purge throws away: the replacement has to carry it.
     */
    public function test_a_duration_handler_registered_at_boot_survives_the_purge(): void
    {
        $app = $this->appWithConfig([
            'async' => ['scoped_services' => [], 'db_pool' => ['enabled' => true]],
            'database' => [
                'default' => 'sqlite',
                'connections' => [
                    'sqlite' => ['driver' => 'sqlite', 'database' => 'file:boot?mode=memory&cache=shared'],
                ],
            ],
        ]);
        $app->instance('files', new \Illuminate\Filesystem\Filesystem());
        $app->register(\Illuminate\Events\EventServiceProvider::class);
        $app->register(\Illuminate\Database\DatabaseServiceProvider::class);
        $app->register(\Spawn\Laravel\AsyncServiceProvider::class);
        $app->boot();

        $fired = [];
        $booted = $app->make('db')->connection();
        $booted->whenQueryingForLongerThan(10, function ($connection) use (&$fired) {
            $fired[] = $connection;
        });

        // The pool step alone, then the switch: the rest of WorkerBootstrap::run() resolves services this
        // application does not register.
        (fn () => self::configureDatabasePool($app))->bindTo(null, WorkerBootstrap::class)();
        $app->enableAsyncMode();

        $live = $app->make('db')->connection();
        $this->assertNotSame($booted, $live, 'the purge replaced the connection');

        $this->inRequest(fn () => $live->logQuery('select 1', [], 20.0));
        $this->inRequest(fn () => $live->logQuery('select 2', [], 20.0));
        $booted->logQuery('select 3', [], 20.0);

        $this->assertCount(2, $fired, 'once in each request, and the purged connection fires no more');
        $this->assertSame($live, $fired[0]);
    }
}
