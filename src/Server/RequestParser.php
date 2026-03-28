<?php

namespace Spawn\Laravel\Server;

use Illuminate\Http\Request;

class RequestParser
{
    public static function parse(string $raw): Request
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

        $server = [
            'REQUEST_METHOD'  => strtoupper($method),
            'REQUEST_URI'     => $uri,
            'QUERY_STRING'    => $queryString,
            'HTTP_HOST'       => $headers['Host'] ?? 'localhost',
            'SERVER_NAME'     => 'localhost',
            'SERVER_PORT'     => '8080',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
        ];

        foreach ($headers as $name => $value) {
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
            $server[$key] = $value;
        }

        if (isset($headers['Content-Type'])) {
            $server['CONTENT_TYPE'] = $headers['Content-Type'];
        }

        if (isset($headers['Content-Length'])) {
            $server['CONTENT_LENGTH'] = $headers['Content-Length'];
        }

        $post = [];
        if (str_contains($headers['Content-Type'] ?? '', 'application/x-www-form-urlencoded')) {
            parse_str($body, $post);
        }

        $request = new Request($query, $post, [], [], [], $server, $body);
        $request->setMethod($server['REQUEST_METHOD']);

        return $request;
    }
}
