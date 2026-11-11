<?php

namespace Spawn\Laravel\Server;

use Illuminate\Http\Request;

class RequestParser
{
    public static function parse(string $raw, string $remoteAddr = '127.0.0.1'): Request
    {
        [$headerSection, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');

        $lines = explode("\r\n", $headerSection);
        $requestLine = array_shift($lines);

        [$method, $uri, ] = explode(' ', $requestLine, 3);

        $headers = [];
        foreach ($lines as $line) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $headers[trim($name)] = trim($value);
        }

        $parsedUrl = parse_url($uri);
        $path = $parsedUrl['path'] ?? '/';
        $queryString = $parsedUrl['query'] ?? '';

        parse_str($queryString, $query);

        // Header names are case-insensitive; lookups go through the lower-cased ones.
        $lower = array_change_key_case($headers, CASE_LOWER);
        [$serverName, $serverPort] = HostHeader::split($lower['host'] ?? '');

        $server = [
            'REQUEST_METHOD'  => strtoupper($method),
            'REQUEST_URI'     => $uri,
            'QUERY_STRING'    => $queryString,
            'HTTP_HOST'       => $lower['host'] ?? 'localhost',
            'SERVER_NAME'     => $serverName !== '' ? $serverName : 'localhost',
            'SERVER_PORT'     => (string) ($serverPort ?? 8080),
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'REMOTE_ADDR'     => $remoteAddr,
        ];

        foreach ($headers as $name => $value) {
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
            $server[$key] = $value;
        }

        if (isset($lower['content-type'])) {
            $server['CONTENT_TYPE'] = $lower['content-type'];
        }

        if (isset($lower['content-length'])) {
            $server['CONTENT_LENGTH'] = $lower['content-length'];
        }

        $post = [];
        if (str_contains($lower['content-type'] ?? '', 'application/x-www-form-urlencoded')) {
            parse_str($body, $post);
        }

        $cookies = CookieHeader::parse($lower['cookie'] ?? '');

        $request = new Request($query, $post, [], $cookies, [], $server, $body);
        $request->setMethod($server['REQUEST_METHOD']);

        return $request;
    }
}
