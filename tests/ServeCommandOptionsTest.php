<?php

namespace Spawn\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use Spawn\Laravel\Console\TrueAsyncServeCommand;

class ServeCommandOptionsTest extends TestCase
{
    private const CONFIG = [
        'listeners' => [
            ['host' => '0.0.0.0', 'port' => 8080, 'tls' => false, 'protocol' => 'auto'],
            ['host' => '0.0.0.0', 'port' => 8443, 'tls' => true, 'protocol' => 'h2'],
        ],
        'workers' => 4,
    ];

    public function test_every_configured_listener_is_kept(): void
    {
        $options = TrueAsyncServeCommand::serverOptions(self::CONFIG, null, null, 4);

        $this->assertSame(self::CONFIG['listeners'], $options['listeners']);
    }

    public function test_host_and_port_override_the_first_listener_only(): void
    {
        $options = TrueAsyncServeCommand::serverOptions(self::CONFIG, '127.0.0.1', '9000', 2);

        $this->assertSame(
            ['host' => '127.0.0.1', 'port' => 9000, 'tls' => false, 'protocol' => 'auto'],
            $options['listeners'][0]
        );
        $this->assertSame(self::CONFIG['listeners'][1], $options['listeners'][1]);
        $this->assertSame(2, $options['workers']);
    }

    public function test_no_listener_configured_gives_the_default_one(): void
    {
        $options = TrueAsyncServeCommand::serverOptions([], null, null, 1);

        $this->assertSame([['host' => '0.0.0.0', 'port' => 8080]], $options['listeners']);
    }
}
