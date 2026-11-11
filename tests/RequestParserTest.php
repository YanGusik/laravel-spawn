<?php

namespace Spawn\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use Spawn\Laravel\Server\RequestParser;

/**
 * DevServer's request line and headers. Header names are case-insensitive (RFC 9110 §5.1),
 * and HTTP/1.1 clients do send them in lower case.
 */
class RequestParserTest extends TestCase
{
    public function test_lowercase_headers_carry_the_form_body_and_its_type(): void
    {
        $body = 'name=Ann&age=7';
        $request = RequestParser::parse(
            "POST /form HTTP/1.1\r\nhost: example.com:9000\r\ncontent-type: application/x-www-form-urlencoded\r\n"
            .'content-length: '.strlen($body)."\r\ncookie: a=1\r\n\r\n".$body
        );

        $this->assertSame(['name' => 'Ann', 'age' => '7'], $request->request->all());
        $this->assertSame('application/x-www-form-urlencoded', $request->server('CONTENT_TYPE'));
        $this->assertSame((string) strlen($body), $request->server('CONTENT_LENGTH'));
        $this->assertSame(['a' => '1'], $request->cookies->all());
    }

    public function test_server_name_and_port_come_from_the_host_header(): void
    {
        $request = RequestParser::parse("GET / HTTP/1.1\r\nHost: example.com:9000\r\n\r\n");

        $this->assertSame('example.com', $request->server('SERVER_NAME'));
        $this->assertSame('9000', $request->server('SERVER_PORT'));
        $this->assertSame('example.com:9000', $request->getHttpHost());
    }

    public function test_without_a_host_header_the_defaults_stay(): void
    {
        $request = RequestParser::parse("GET / HTTP/1.0\r\n\r\n");

        $this->assertSame('localhost', $request->server('SERVER_NAME'));
        $this->assertSame('8080', $request->server('SERVER_PORT'));
    }
}
