<?php

namespace Spawn\Laravel\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Spawn\Laravel\Server\CookieHeader;
use Spawn\Laravel\Server\RequestParser;

class CookieHeaderTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    public static function headers(): array
    {
        return [
            'none' => ['', []],
            'percent-encoded padding' => ['XSRF-TOKEN=eyJpdiI6ImEifQ%3D%3D', ['XSRF-TOKEN' => 'eyJpdiI6ImEifQ==']],
            'encoded space, plus kept' => ['name=John%20Doe; sum=1+1', ['name' => 'John Doe', 'sum' => '1+1']],
            'no space after the separator' => ['a=1;b=2', ['a' => '1', 'b' => '2']],
            'the first of two equal names wins' => ['a=first; a=second', ['a' => 'first']],
            'an empty value' => ['a=; b=2', ['a' => '', 'b' => '2']],
            'a pair without = is skipped' => ['flag; b=2', ['b' => '2']],
            'an = inside the value' => ['a=x=y', ['a' => 'x=y']],
        ];
    }

    #[DataProvider('headers')]
    public function test_a_header_reads_as_php_reads_it(string $header, array $expected): void
    {
        $this->assertSame($expected, CookieHeader::parse($header));
    }

    public function test_the_dev_server_parser_takes_the_cookie_header(): void
    {
        $request = RequestParser::parse("GET / HTTP/1.1\r\nHost: localhost\r\nCookie: a=b%3D; c=d\r\n\r\n");

        $this->assertSame(['a' => 'b=', 'c' => 'd'], $request->cookies->all());
    }
}
