<?php

namespace Spawn\Laravel\Server;

use Symfony\Component\HttpFoundation\Response;

class ResponseEmitter
{
    public static function emit(mixed $socket, Response $response): void
    {
        $status = $response->getStatusCode();
        $statusText = $response->headers->get('x-status-text') ?? Response::$statusTexts[$status] ?? 'Unknown';
        $body = $response->getContent();

        $out = "HTTP/1.1 {$status} {$statusText}\r\n";

        foreach ($response->headers->all() as $name => $values) {
            foreach ($values as $value) {
                $out .= "{$name}: {$value}\r\n";
            }
        }

        $out .= "Content-Length: " . strlen($body) . "\r\n";
        $out .= "Connection: close\r\n";
        $out .= "\r\n";
        $out .= $body;

        fwrite($socket, $out);
    }
}
