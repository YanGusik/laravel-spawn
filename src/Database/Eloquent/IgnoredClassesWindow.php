<?php

namespace Spawn\Laravel\Database\Eloquent;

use Closure;

use function Async\coroutine_context;

/**
 * The model classes Eloquent skips timestamps or touches for, held per coroutine for the length
 * of a `withoutTimestampsOn()` or `withoutTouchingOn()` callback.
 *
 * Upstream adds the classes to a class static (`HasTimestamps::$ignoreTimestampsOn`,
 * `Model::$ignoreOnTouch`) and takes them out in a `finally`. That static is one value per
 * worker thread, so a callback that awaits anything has every request served in the meantime
 * save its rows without `created_at`/`updated_at`, or leave its parents untouched. Upstream's
 * `withoutTouchingOn()` also takes the classes out with `array_diff()`, which closes an
 * enclosing window on the same class; restoring the list saved on entry does not.
 *
 * The window is read on top of the static, which nothing in Laravel writes outside the two
 * methods replaced; a model of the application that writes the protected static still reaches
 * every coroutine.
 *
 * A coroutine spawned inside the window does not inherit it: the context is the opener's own,
 * and neither `Async\spawn()` nor `Scope::inherit()` reads it. A parallel import written as
 * `Model::withoutTimestamps(fn () => await(all(...)))` therefore stamps every row its children
 * save, and `withoutTouching()` around spawned saves touches each parent. Open a window inside
 * each child that needs one.
 *
 * The copies of `Concerns\HasTimestamps` and `Model` under `overrides/` are the only callers.
 */
final class IgnoredClassesWindow
{
    /** The classes `withoutTimestampsOn()` skips timestamps for. */
    public const TIMESTAMPS = 'spawn.ignore-timestamps.window';

    /** The classes `withoutTouchingOn()` skips touches for. */
    public const TOUCH = 'spawn.ignore-touch.window';

    /**
     * Run the callback with the given classes ignored in this coroutine and nowhere else.
     *
     * @param  self::TIMESTAMPS|self::TOUCH  $kind
     * @param  array<array-key, class-string>  $classes
     */
    public static function open(string $kind, array $classes, Closure $callback): mixed
    {
        $context = coroutine_context();
        $previous = $context->findLocal($kind) ?? [];

        $context->set($kind, array_values(array_merge($previous, $classes)), true);

        try {
            return $callback();
        } finally {
            $context->set($kind, $previous, true);
        }
    }

    /**
     * Whether the class, or a parent of it, is ignored right now.
     *
     * @param  self::TIMESTAMPS|self::TOUCH  $kind
     * @param  array<array-key, class-string>  $processWide  the upstream static
     */
    public static function ignores(string $kind, string $class, array $processWide): bool
    {
        $ignored = array_merge($processWide, coroutine_context()->findLocal($kind) ?? []);

        return array_any(
            $ignored,
            static fn ($ignoredClass) => $class === $ignoredClass || is_subclass_of($class, $ignoredClass)
        );
    }
}
