<?php

namespace Spawn\Laravel\Log;

use Illuminate\Log\Logger;

/**
 * A `Logger` that keeps each request's context apart from every other request's.
 *
 * `withContext()` is the only way an application tags its own log lines, and upstream writes
 * the tag into a property of an object the whole worker shares. Here it writes into
 * {@see RequestLogContext}, and every line this logger writes merges the three sources in the
 * order upstream merges its two: what the worker shares, then what this request added, then
 * what the call passed.
 */
class AsyncLogger extends Logger
{
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
        parent::writeLog($level, $message, array_merge(RequestLogContext::all(), $context));
    }
}
