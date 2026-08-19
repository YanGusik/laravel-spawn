<?php

namespace Spawn\Laravel\Sse;

use function trueasync_response;

/**
 * Thin static wrapper over the raw HttpResponse SSE helpers, so a Laravel
 * controller doesn't need to reach for trueasync_response() itself.
 *
 * A route using this never returns the stream as an Illuminate Response —
 * it writes directly and ends the raw response. TrueAsyncServer::sendResponse()
 * detects the response is already closed and skips the normal buffered path.
 */
final class Sse
{
    public static function start(?int $retryMs = null): void
    {
        $res = trueasync_response();
        $res->sseStart(); // also disables compression — buffered gzip would kill the stream's immediacy

        if ($retryMs !== null) {
            $res->sseRetry($retryMs);
        }
    }

    public static function event(string $data, ?string $event = null, ?string $id = null): void
    {
        trueasync_response()->sseEvent(data: $data, event: $event, id: $id);
    }

    public static function comment(?string $text = null): void
    {
        $text === null ? trueasync_response()->sseComment() : trueasync_response()->sseComment($text);
    }

    /**
     * True while the outbound buffer has room for the next event. False means
     * backpressure, not a client that left — event() waits for room by itself,
     * so breaking a loop on false only drops events from a slow stream.
     */
    public static function connected(): bool
    {
        return trueasync_response()->sendable();
    }

    public static function end(): void
    {
        trueasync_response()->end();
    }
}
