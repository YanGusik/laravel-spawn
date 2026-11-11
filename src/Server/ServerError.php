<?php

namespace Spawn\Laravel\Server;

/**
 * The body of a 500 a server answers itself, when an exception escapes the kernel or happens
 * outside it.
 *
 * Laravel's exception handler decides what a client sees of an exception it renders; one that
 * escapes it has bypassed that decision, and its message can carry a SQL error, a path or a
 * credential. The message goes to the log and, only with `app.debug`, to the client.
 */
final class ServerError
{
    public const BODY = 'Internal Server Error';

    /**
     * @param  mixed  $app  the container, or null when there is none to ask
     */
    public static function body(\Throwable $e, mixed $app): string
    {
        try {
            $debug = $app !== null && (bool) $app->make('config')->get('app.debug', false);
        } catch (\Throwable) {
            $debug = false;
        }

        return $debug ? $e->getMessage() : self::BODY;
    }
}
