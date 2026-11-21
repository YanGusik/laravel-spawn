<?php

namespace Spawn\Laravel\Database\Eloquent;

use Closure;

use function Async\coroutine_context;

/**
 * Strict mode's refusal of a missing attribute, lifted per coroutine for the length of one
 * `isset($model->attribute)`.
 *
 * `Model::offsetExists()`, which `isset()` and `empty()` on a model reach, asks for the
 * attribute with `Model::$modelsShouldPreventAccessingMissingAttributes` saved, set to `false`
 * and restored in a `finally`, so that a missing attribute answers "not set" instead of
 * throwing. That static is one value per worker thread. An accessor that awaits anything turns
 * strict mode off for every request served in the meantime, and two overlapping reads leave it
 * off for good: the second saves the `false` the first wrote and restores it last.
 *
 * The window is read on top of the static: `Model::preventAccessingMissingAttributes()` and
 * `Model::shouldBeStrict()` are a process-wide switch and keep writing it.
 *
 * A coroutine spawned inside the window, which only an accessor can do, does not inherit it:
 * the context is the opener's own, and neither `Async\spawn()` nor `Scope::inherit()` reads it.
 *
 * The copy of `Model` under `overrides/` is the only caller. It opens the window only while
 * strict mode is on, so `isset()` on a model costs nothing more where strict mode is off.
 */
final class MissingAttributeWindow
{
    private const KEY = 'spawn.missing-attribute.window';

    /**
     * Run the read with missing attributes answered as null in this coroutine and nowhere else.
     */
    public static function open(Closure $read): mixed
    {
        $context = coroutine_context();
        $previous = $context->findLocal(self::KEY);

        $context->set(self::KEY, true, true);

        try {
            return $read();
        } finally {
            $context->set(self::KEY, $previous, true);
        }
    }

    /**
     * Whether a missing attribute is refused right now.
     *
     * @param  bool  $processWide  the upstream static
     */
    public static function prevents(bool $processWide): bool
    {
        return $processWide && coroutine_context()->findLocal(self::KEY) !== true;
    }
}
