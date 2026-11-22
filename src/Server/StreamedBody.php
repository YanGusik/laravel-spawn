<?php

namespace Spawn\Laravel\Server;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Forwards the body a response writes to PHP's output, chunk by chunk, to a server's own sink.
 *
 * `StreamedResponse` and `BinaryFileResponse` have no content to read — getContent() is false —
 * and produce their body by echoing it from sendContent(). An output buffer with a handler
 * catches what they echo and hands each chunk on, so a download or a stream reaches the client
 * as it is produced instead of an empty body.
 *
 * Two such buffers are stacked, sharing one handler and one state. Writers of PDFs and
 * spreadsheets clear stray output with an ob_end_clean() before they stream, which pops the
 * upper one; the lower one then takes over. Only when both are gone does the body have nowhere
 * to go but the worker's own output, and the request fails instead: no userland call can put
 * a buffer back from inside the body.
 *
 * A handler that throws is disabled, and PHP hands what it held to the buffer below. A third
 * buffer under the two drops it, so a chunk the handler failed on never reaches the worker's
 * output.
 *
 * @internal
 */
final class StreamedBody
{
    /** Bytes PHP buffers before it calls the handler. */
    public const CHUNK_BYTES = 65536;

    /**
     * The same for a `StreamedResponse`: flush() reaches no output handler, so a stream that
     * calls it without ob_flush() is held back by this much, as php-fpm with the stock
     * `output_buffering = 4096` holds it back.
     */
    public const STREAM_CHUNK_BYTES = 4096;

    /**
     * Run the response's sendContent() and pass every chunk it writes to $write.
     *
     * Rethrows a failure of sendContent() after discarding what is still buffered, so a caller
     * that has sent nothing yet can answer 500; a body that removed every output buffer fails
     * the same way, with a \LogicException. A $write that throws is taken as the peer having
     * gone: the body is stopped by a PeerGone thrown where it writes, and the rest of its output
     * is dropped; the result is false. A body that catches PeerGone and writes on runs to its
     * end, its output dropped.
     *
     * @param  callable(string): void  $write  receives each non-empty chunk, in order
     * @return bool  false when $write threw, true when every chunk was handed on
     *
     * @throws \RuntimeException when output buffering is unavailable
     */
    public static function forward(Response $response, callable $write): bool
    {
        $peerGone = false;
        // Set while this method unwinds its own buffers: their final call is not a user's pop.
        $closing = false;
        $discarding = false;
        // Forwarding buffers still on the stack.
        $layers = 2;
        $removed = null;

        /* A handler that returns no value counts as failed: PHP disables it and writes the
         * pending chunk to the next buffer down. It also runs on a discard, where dropping
         * the return value is not enough, so both the caller's ob_clean() and this method's
         * own unwind have to be recognised and answered with silence. */
        $sink = static function (string $chunk, int $phase) use (
            $write, &$peerGone, &$closing, &$discarding, &$layers, &$removed
        ): string {
            if ($discarding || $peerGone) {
                return '';
            }

            $popped = ! $closing && ($phase & PHP_OUTPUT_HANDLER_FINAL) !== 0;

            if ($popped) {
                $layers--;
            }

            if ($chunk !== '' && ($phase & PHP_OUTPUT_HANDLER_CLEAN) === 0) {
                try {
                    $write($chunk);
                } catch (\Throwable $e) {
                    $peerGone = true;

                    // The body has returned and there is nothing left to stop.
                    if ($closing) {
                        return '';
                    }

                    // Ends a body that would otherwise produce for nobody, such as an endless event stream.
                    throw new PeerGone('The client is gone, the response body is not delivered', previous: $e);
                }
            }

            if ($popped && $layers === 0) {
                $removed = new \LogicException(
                    'The response body removed every output buffer, the forwarding one included: '
                    .'what it writes next would reach the worker\'s output, not the client'
                );

                throw $removed;
            }

            return '';
        };

        $outerLevel = ob_get_level();
        $chunkBytes = $response instanceof StreamedResponse ? self::STREAM_CHUNK_BYTES : self::CHUNK_BYTES;

        $stacked = ob_start(static fn (): string => '') && ob_start($sink, $chunkBytes) && ob_start($sink, $chunkBytes);

        if (! $stacked) {
            self::unwind($outerLevel, false);

            throw new \RuntimeException('Output buffering is unavailable, the response body cannot be forwarded');
        }

        try {
            $response->sendContent();
        } catch (\Throwable $e) {
            $discarding = true;
            self::unwind($outerLevel, false);

            if ($peerGone) {
                return false;
            }

            throw $e;
        }

        // A body that caught the failure, as eventStream() reports and swallows whatever it throws.
        if ($peerGone || $removed !== null) {
            $discarding = true;
            self::unwind($outerLevel, false);

            if ($removed !== null) {
                throw $removed;
            }

            return false;
        }

        $closing = true;
        self::unwind($outerLevel, true);

        return ! $peerGone;
    }

    /** Close every buffer above $level: the forwarding ones and any the body left open. */
    private static function unwind(int $level, bool $flush): void
    {
        while (ob_get_level() > $level) {
            $flush ? ob_end_flush() : ob_end_clean();
        }
    }
}
