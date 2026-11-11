<?php

namespace Spawn\Laravel\Server;

use Symfony\Component\HttpFoundation\Response;

class ResponseEmitter
{
    /**
     * Write a whole HTTP/1.1 response to the socket and leave the socket open for the caller to
     * close.
     *
     * A response with content is sent with its Content-Length. One that writes its own body —
     * a StreamedResponse or a BinaryFileResponse, whose getContent() is false — is sent without
     * one and forwarded as it is produced; `Connection: close` delimits its body either way.
     *
     * @param  resource  $socket
     */
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

        if ($body !== false) {
            $out .= "Content-Length: " . strlen($body) . "\r\n";
        }

        $out .= "Connection: close\r\n";
        $out .= "\r\n";

        if ($body !== false) {
            fwrite($socket, $out . $body);

            return;
        }

        fwrite($socket, $out);

        StreamedBody::forward($response, static function (string $chunk) use ($socket): void {
            if (@fwrite($socket, $chunk) === false) {
                throw new \RuntimeException('the client closed the connection');
            }
        });
    }
}
