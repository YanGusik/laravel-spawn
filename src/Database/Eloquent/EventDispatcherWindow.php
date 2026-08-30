<?php

namespace Spawn\Laravel\Database\Eloquent;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;

use function Async\coroutine_context;

/**
 * The dispatcher Eloquent's model events go to, held per coroutine for the length of a
 * `Model::withoutEvents()` callback.
 *
 * Upstream swaps `Model::$dispatcher` for a `NullDispatcher` and restores it in a `finally`.
 * That static is one value per worker thread, so a callback that awaits anything silences the
 * model events of every request served in the meantime — with no error, because a
 * `NullDispatcher` answers every call and delivers nothing.
 *
 * The window is read on top of the static: `Model::setEventDispatcher()` is how bootstrap
 * gives the whole worker its dispatcher, and it keeps writing the static.
 *
 * The copy of `Concerns\HasEvents` under `overrides/` is the only caller.
 */
final class EventDispatcherWindow
{
    private const KEY = 'spawn.model-events.window';

    /**
     * Run the callback with model events going to the given dispatcher in this coroutine and
     * nowhere else.
     */
    public static function open(Dispatcher $dispatcher, Closure $callback): mixed
    {
        $context = coroutine_context();
        $previous = $context->findLocal(self::KEY);

        $context->set(self::KEY, $dispatcher, true);

        try {
            return $callback();
        } finally {
            $context->set(self::KEY, $previous, true);
        }
    }

    /**
     * The dispatcher model events go to right now.
     *
     * @param  ?Dispatcher  $processWide  the dispatcher `Model::setEventDispatcher()` wrote,
     *                                    used where this coroutine has opened no window
     */
    public static function current(?Dispatcher $processWide): ?Dispatcher
    {
        return coroutine_context()->findLocal(self::KEY) ?? $processWide;
    }
}
