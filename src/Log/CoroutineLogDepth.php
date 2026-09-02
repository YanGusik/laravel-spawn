<?php

namespace Spawn\Laravel\Log;

use function Async\coroutine_context;

/**
 * How many writes of {@see AsyncLogger} are open in the current coroutine, one inside the
 * other.
 *
 * A handler or a log listener that logs through the logger it serves opens a write inside a
 * write, and without a bound the two recurse until the stack is gone. Monolog bounds it by
 * counting the depth per `Fiber`, and on the `Logger` object where `Fiber::getCurrent()` is
 * null; a TrueAsync coroutine is no `Fiber`, so on that count every coroutine of the worker
 * shares the one count of the memoised channel, and a write that suspends holds it raised for
 * the others: three concurrent writers reach Monolog's loop warning and five reach the drop.
 * The count here is kept in the coroutine's own context, which belongs to one coroutine
 * and is inherited by nobody, so it measures the recursion in this coroutine and nothing else.
 * The request's context would not do: every coroutine of the request shares it, and a request
 * that fans out to concurrent writers would read as a loop again.
 */
final class CoroutineLogDepth
{
    private const KEY = 'spawn.log.depth';

    /**
     * Open a write and return its depth: 1 for a write with none open above it in this
     * coroutine. Every call is matched by one {@see leave()}, whatever the write did.
     */
    public static function enter(): int
    {
        $depth = self::current() + 1;

        coroutine_context()->set(self::KEY, $depth, replace: true);

        return $depth;
    }

    /**
     * Close the innermost open write.
     */
    public static function leave(): void
    {
        $depth = self::current() - 1;

        if ($depth > 0) {
            coroutine_context()->set(self::KEY, $depth, replace: true);
        } else {
            coroutine_context()->unset(self::KEY);
        }
    }

    private static function current(): int
    {
        return (int) (coroutine_context()->findLocal(self::KEY) ?? 0);
    }
}
