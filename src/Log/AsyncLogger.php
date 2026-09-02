<?php

namespace Spawn\Laravel\Log;

use Illuminate\Log\Logger;

/**
 * A `Logger` that keeps each request's context apart from every other request's, and that
 * counts its own depth against a logging loop.
 *
 * `withContext()` is the only way an application tags its own log lines, and upstream writes
 * the tag into a property of an object the whole worker shares. Here it writes into
 * {@see RequestLogContext}, and every line this logger writes merges the three sources in the
 * order upstream merges its two: what the worker shares, then what this request added, then
 * what the call passed.
 *
 * Every level reaches `writeLog()`, and that is where the depth is counted, in
 * {@see CoroutineLogDepth}: at the third write open in one coroutine a warning is written in
 * place of the record, and a deeper write is dropped without a word. The threshold is
 * Monolog's, whose own count is off on every channel {@see AsyncLogManager} builds. The
 * count sees a write that arrives through this logger and no other: a handler that logs
 * straight into the `Monolog\Logger` bypasses this count and Monolog's disabled one alike,
 * and a loop through that path is unbounded.
 */
class AsyncLogger extends Logger
{
    /**
     * The depth at which a write is judged a loop rather than a handler logging once.
     */
    private const LOOP_DEPTH = 3;

    /**
     * Monolog's own wording, so whatever an operator watches for keeps matching.
     */
    private const LOOP_WARNING = 'A possible infinite logging loop was detected and aborted. It appears some of your'
        . ' handler code is triggering logging, see the previous log record for a hint as to what may be the cause.';

    /**
     * Add context to this request's log lines, and to no other request's.
     *
     * @param  array<string, mixed>  $context
     * @return $this
     */
    public function withContext(array $context = [])
    {
        RequestLogContext::add($context);

        return $this;
    }

    /**
     * Drop context this request added. Context the worker shares is left alone: a request
     * cannot un-share what it did not share.
     *
     * @param  string[]|null  $keys
     * @return $this
     */
    public function withoutContext(?array $keys = null)
    {
        RequestLogContext::forget($keys);

        return $this;
    }

    /**
     * Context every request writing through this logger gets, whoever resolved the channel.
     *
     * `LogManager` gives a freshly built channel its shared context this way, and
     * `Log::shareContext()` sends it to the channels already built. Both mean the worker.
     *
     * @param  array<string, mixed>  $context
     * @return $this
     */
    public function shareProcessContext(array $context)
    {
        return parent::withContext($context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function writeLog($level, $message, $context): void
    {
        $depth = CoroutineLogDepth::enter();

        try {
            if ($depth === self::LOOP_DEPTH) {
                parent::writeLog('warning', self::LOOP_WARNING, []);
            } elseif ($depth < self::LOOP_DEPTH) {
                parent::writeLog($level, $message, array_merge(RequestLogContext::all(), $context));
            }
        } finally {
            CoroutineLogDepth::leave();
        }
    }
}
