<?php

namespace Spawn\Laravel\Log;

use Spawn\Laravel\Foundation\RequestContext;

/**
 * The log context one request added to its own lines.
 *
 * `Logger::$context` is an ordinary property of a `Logger`, and `LogManager` memoises one
 * `Logger` per channel for the life of the process, so the request-id middleware that every
 * application writes — `Log::withContext(['request_id' => …])` — tags every other request's
 * lines as well. The context is kept in the request's own context instead, which every
 * coroutine of that request inherits and nothing outside it reads.
 *
 * What a provider shares for the whole worker stays on the `Logger`; see
 * {@see AsyncLogger::shareProcessContext()}.
 */
final class RequestLogContext
{
    private const KEY = 'spawn.log.context';

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return RequestContext::current()->findLocal(self::KEY) ?? [];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function add(array $context): void
    {
        self::replace(array_merge(self::all(), $context));
    }

    /**
     * Drop the given keys, or the whole of this request's context when given none.
     *
     * @param  string[]|null  $keys
     */
    public static function forget(?array $keys = null): void
    {
        self::replace($keys === null ? [] : array_diff_key(self::all(), array_flip($keys)));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function replace(array $context): void
    {
        RequestContext::current()->set(self::KEY, $context, true);
    }
}
