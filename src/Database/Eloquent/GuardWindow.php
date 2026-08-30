<?php

namespace Spawn\Laravel\Database\Eloquent;

use Closure;

use function Async\coroutine_context;

/**
 * Eloquent's "mass assignment is allowed for the length of this callback" decision, held per
 * coroutine.
 *
 * Upstream keeps it in `Model::$unguarded`, a class static, which is one value per worker
 * thread and therefore shared by every coroutine of that worker. `Model::unguarded()` sets it,
 * runs the callback and restores it in a `finally`, so a callback that awaits anything leaves
 * mass-assignment protection off for every request served in the meantime.
 *
 * The window is read on top of the static rather than instead of it: `Model::unguard()` and
 * `Model::reguard()` are documented as a process-wide switch, which an application makes in a
 * service provider or a seeder, and they keep writing the static. A coroutine that opened no
 * window of its own therefore sees whatever the process decided.
 *
 * A coroutine spawned inside the window does not inherit it: the context is the opener's own,
 * and neither `Async\spawn()` nor `Scope::inherit()` reads it. A parallel bulk import written
 * as `Model::unguarded(fn () => await(all(...)))` therefore fills its rows guarded, and drops
 * or refuses the attributes the guard covers. Open a window inside each child that needs one.
 *
 * The copy of `Concerns\GuardsAttributes` under `overrides/` is the only caller.
 */
final class GuardWindow
{
    private const KEY = 'spawn.guard.window';

    /**
     * Run the callback with mass assignment allowed in this coroutine and nowhere else.
     *
     * The window closes on the way out however the callback leaves — a return or a throw — and
     * a coroutine killed inside one leaves nothing behind, because the context goes with it.
     */
    public static function open(Closure $callback): mixed
    {
        $context = coroutine_context();
        $previous = $context->findLocal(self::KEY);

        $context->set(self::KEY, true, true);

        try {
            return $callback();
        } finally {
            // Closing writes the previous value back rather than removing the entry, so the
            // usual case leaves a null entry behind. Reads are findLocal(), which answers null
            // either way; find() would let that entry shadow an enclosing scope's value.
            $context->set(self::KEY, $previous, true);
        }
    }

    /**
     * Whether mass assignment is allowed right now.
     *
     * @param  bool  $processWide  the value `Model::unguard()` wrote, used where this coroutine
     *                             has opened no window
     */
    public static function isUnguarded(bool $processWide): bool
    {
        return coroutine_context()->findLocal(self::KEY) ?? $processWide;
    }
}
