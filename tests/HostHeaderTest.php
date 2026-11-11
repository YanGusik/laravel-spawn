<?php

namespace Spawn\Laravel\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Spawn\Laravel\Server\HostHeader;

class HostHeaderTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: array{0: string, 1: int|null}}>
     */
    public static function hosts(): array
    {
        return [
            'name' => ['example.com', ['example.com', null]],
            'name and port' => ['example.com:8080', ['example.com', 8080]],
            'IPv4 and port' => ['127.0.0.1:8000', ['127.0.0.1', 8000]],
            'IPv6' => ['[::1]', ['[::1]', null]],
            'IPv6 and port' => ['[::1]:8000', ['[::1]', 8000]],
            'IPv6 full' => ['[2001:db8::7]:443', ['[2001:db8::7]', 443]],
            'unclosed bracket' => ['[::1', ['[::1', null]],
            'port not a number' => ['example.com:http', ['example.com', null]],
            'empty' => ['', ['', null]],
        ];
    }

    #[DataProvider('hosts')]
    public function test_a_host_splits_into_name_and_port(string $host, array $expected): void
    {
        $this->assertSame($expected, HostHeader::split($host));
    }
}
