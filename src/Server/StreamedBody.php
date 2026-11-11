<?php

namespace Spawn\Laravel\Server;

use Symfony\Component\HttpFoundation\Response;

/**
 * Forwards the body a response writes to PHP's output, chunk by chunk, to a server's own sink.
 *
 * `StreamedResponse` and `BinaryFileResponse` have no content to read — getContent() is false —
 * and produce their body by echoing it from sendContent(). An output buffer with a handler
 * catches what they echo and hands each chunk on, so a download or a stream reaches the client
 * as it is produced instead of an empty body.
 */
final class StreamedBody
{
    /** Bytes PHP buffers before it calls the handler. */
    public const CHUNK_BYTES = 65536;

    /**
     * Run the response's sendContent() and pass every chunk it writes to $write.
     *
     * Rethrows a failure of sendContent() after discarding what is still buffered, so a caller
     * that has sent nothing yet can answer 500. A $write that throws is taken as the peer having
     * gone: later chunks are dropped, sendContent() runs to its end, and the result is false.
     *
     * @param  callable(string): void  $write  receives each non-empty chunk, in order
     * @return bool  false when $write threw, true when every chunk was handed on
     *
     * @throws \RuntimeException when output buffering is unavailable
     */
    public static function forward(Response $response, callable $write): bool
    {
        $peerGone = false;
        $discarding = false;

        /* A handler that returns no value counts as failed: PHP disables it and writes the
         * pending chunk to the worker's own output. It also runs on a discard, where dropping
         * the return value is not enough, so both the caller's ob_clean() and this method's
         * own unwind have to be recognised and answered with silence. */
        $sink = static function (string $chunk, int $phase) use ($write, &$peerGone, &$discarding): string {
            if ($chunk === '' || $peerGone || $discarding || ($phase & PHP_OUTPUT_HANDLER_CLEAN) !== 0) {
                return '';
            }

            try {
                $write($chunk);
            } catch (\Throwable) {
                $peerGone = true;
            }

            return '';
        };

        // Streaming code often opens with `while (ob_get_level()) ob_end_flush()`, this handler included.
        $outerLevel = ob_get_level();

        if (! ob_start($sink, self::CHUNK_BYTES)) {
            throw new \RuntimeException('Output buffering is unavailable, the response body cannot be forwarded');
        }

        try {
            $response->sendContent();
        } catch (\Throwable $e) {
            $discarding = true;

            while (ob_get_level() > $outerLevel) {
                ob_end_clean();
            }

            throw $e;
        }

        while (ob_get_level() > $outerLevel) {
            ob_end_flush();
        }

        return ! $peerGone;
    }
}
