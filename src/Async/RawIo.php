<?php

namespace Spawn\Laravel\Async;

use TrueAsync\HttpRequest;
use TrueAsync\HttpResponse;

use function Async\request_context;

/**
 * Access to the raw TrueAsync HttpRequest/HttpResponse for the current request.
 *
 * TrueAsyncServer converts the incoming request into a Symfony/Illuminate
 * Request and buffers the Illuminate Response back into HttpResponse. That is
 * the right default for ordinary controllers, but streaming protocols (SSE,
 * gRPC) need the raw object: sseEvent(), sendable(), readMessage(),
 * writeMessage() have no equivalent on the buffered Illuminate Response.
 *
 * Stored per-request (request_context(), not coroutine_context()) so that any
 * coroutine spawned by the handler — e.g. a background TaskGroup — can still
 * reach the same response stream.
 */
final class RawIo
{
    private const CTX_REQUEST = 'trueasync.raw_request';
    private const CTX_RESPONSE = 'trueasync.raw_response';

    public static function set(HttpRequest $request, HttpResponse $response): void
    {
        $ctx = request_context();
        $ctx->set(self::CTX_REQUEST, $request, replace: true);
        $ctx->set(self::CTX_RESPONSE, $response, replace: true);
    }

    public static function request(): HttpRequest
    {
        $request = request_context()->find(self::CTX_REQUEST);

        if (! $request instanceof HttpRequest) {
            throw new \RuntimeException(
                'No raw TrueAsync HttpRequest in the current request context. '.
                'trueasync_request() is only available while TrueAsyncServer is handling an HTTP or gRPC request.'
            );
        }

        return $request;
    }

    public static function response(): HttpResponse
    {
        $response = request_context()->find(self::CTX_RESPONSE);

        if (! $response instanceof HttpResponse) {
            throw new \RuntimeException(
                'No raw TrueAsync HttpResponse in the current request context. '.
                'trueasync_response() is only available while TrueAsyncServer is handling an HTTP or gRPC request.'
            );
        }

        return $response;
    }
}
