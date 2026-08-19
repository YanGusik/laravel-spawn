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
     * True while the client is still there — false means stop producing events.
     *
     * On a server build without isWritable() this falls back to sendable(),
     * where false can also mean a full outbound buffer, so a slow reader ends
     * the stream early. event() itself waits for room either way.
     */
    public static function connected(): bool
    {
        static $liveness = null;

        $response = trueasync_response();
        $liveness ??= method_exists($response, 'isWritable');

        return $liveness ? $response->isWritable() : $response->sendable();
    }

    public static function end(): void
    {
        trueasync_response()->end();
    }
}
