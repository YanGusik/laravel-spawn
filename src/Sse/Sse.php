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
     * Whether the next event fits into the outbound buffer right now.
     *
     * False does NOT mean the client left. On HTTP/2 and HTTP/3 it means the
     * per-stream queue is full, and on HTTP/1 the answer is always true because
     * there is no such queue. A loop that breaks on false ends a stream that is
     * only slow, and drops the events it was about to write; event() is safe to
     * call either way, since it waits for room instead of failing.
     *
     * A real liveness check is coming with the server's `isWritable()`, and this
     * method will move to it.
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
