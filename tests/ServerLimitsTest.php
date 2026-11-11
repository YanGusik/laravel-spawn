<?php

namespace Spawn\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use Spawn\Laravel\Server\TrueAsyncServer;
use TrueAsync\HttpServerConfig;

/**
 * The admission limits of async.server reach the extension's config, and an unset one leaves
 * the extension's default in place.
 */
class ServerLimitsTest extends TestCase
{
    protected function setUp(): void
    {
        // buildConfig() reads the hot-reload settings and the environment from the container.
        $app = new \Illuminate\Foundation\Application(sys_get_temp_dir());
        $app->instance('config', new \Illuminate\Config\Repository(['app' => ['env' => 'testing']]));
        $app['env'] = 'testing';
        \Illuminate\Container\Container::setInstance($app);
    }

    protected function tearDown(): void
    {
        \Illuminate\Container\Container::setInstance(null);
    }

    private function configFor(array $options): HttpServerConfig
    {
        $server = new TrueAsyncServer('', '', $options + [
            'listeners' => [['host' => '127.0.0.1', 'port' => 8403, 'tls' => false, 'protocol' => 'auto']],
        ]);

        return (fn () => $this->buildConfig())->call($server);
    }

    public function test_configured_limits_reach_the_server(): void
    {
        $config = $this->configFor([
            'max_connections' => 100,
            'max_inflight_requests' => 7,
            'backpressure_target_ms' => 50,
        ]);

        $this->assertSame(100, $config->getMaxConnections());
        $this->assertSame(7, $config->getMaxInflightRequests());
        $this->assertSame(50, $config->getBackpressureTargetMs());
    }

    public function test_unset_limits_keep_the_server_defaults(): void
    {
        $defaults = new HttpServerConfig();
        $config = $this->configFor(['max_connections' => null]);

        $this->assertSame($defaults->getMaxConnections(), $config->getMaxConnections());
        $this->assertSame($defaults->getMaxInflightRequests(), $config->getMaxInflightRequests());
        $this->assertSame($defaults->getBackpressureTargetMs(), $config->getBackpressureTargetMs());
    }
}
