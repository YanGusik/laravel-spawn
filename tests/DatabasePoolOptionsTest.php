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
}
