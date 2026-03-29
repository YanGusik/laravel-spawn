<?php

namespace Spawn\Laravel\Foundation;

/**
 * Object keys for TrueAsync Context storage.
 *
 * Using object keys instead of strings prevents accidental collisions
 * with user code or third-party packages writing to the same context.
 */
final class ContextKeys
{
    /** Key for the current Illuminate\Http\Request. */
    public static object $request;

    /**
     * Key for the scoped services container (stdClass used as mutable property bag).
     * Stored value is a stdClass where each property is an alias => instance pair.
     */
    public static object $scopedServices;

    private static bool $initialized = false;

    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$request = new \stdClass();
        self::$scopedServices = new \stdClass();
        self::$initialized = true;
    }
}
