<?php

namespace Spawn\Laravel\Database\Eloquent;

use Closure;

use function Async\coroutine_context;

/**
 * Eloquent's "model events are not broadcast for the length of this callback" decision, held
 * per coroutine.
 *
 * Upstream keeps it in `Model::$isBroadcasting`, a class static, and `withoutBroadcasting()`
 * saves it, writes `false` and restores the saved value in a `finally`. That static is one
 * value per worker thread, so a callback that awaits anything silences the broadcasts of every
 * request served in the meantime. Two overlapping calls are worse: the second saves the `false`
 * the first wrote, the first leaves, the second restores `false`, and the worker never
 * broadcasts a model event again.
 *
 * The window is read on top of the static, which nothing in Laravel writes outside
 * `withoutBroadcasting()`; a model of the application that writes the protected static still
 * reaches every coroutine.
 *
 * A coroutine spawned inside the window does not inherit it: the context is the opener's own,
 * and neither `Async\spawn()` nor `Scope::inherit()` reads it. A parallel import written as
 * `Model::withoutBroadcasting(fn () => await(all(...)))` therefore broadcasts every model event
 * its children fire. Open a window inside each child that needs one.
 *
 * The copies of `Model` and `BroadcastsEvents` under `overrides/` are the only callers.
 */
final class BroadcastWindow
{
    private const KEY = 'spawn.broadcast.window';

    /**
     * Run the callback with model broadcasting off in this coroutine and nowhere else.
     */
    public static function open(Closure $callback): mixed
    {
        $context = coroutine_context();
        $previous = $context->findLocal(self::KEY);

        $context->set(self::KEY, true, true);

        try {
            return $callback();
        } finally {
            $context->set(self::KEY, $previous, true);
        }
    }

    /**
     * Whether model events are broadcast right now.
     *
     * @param  bool  $processWide  the upstream static
     */
    public static function isBroadcasting(bool $processWide): bool
    {
        return $processWide && coroutine_context()->findLocal(self::KEY) !== true;
    }
}
