<?php

namespace Spawn\Laravel\Server;

/**
 * Splits a `Host` header into the name and the port the servers put in SERVER_NAME and
 * SERVER_PORT.
 *
 * An IPv6 literal carries its own colons and comes in brackets (RFC 3986 §3.2.2), so a split
 * on the first colon turns `[::1]:8000` into the name `[` and port 0.
 */
final class HostHeader
{
    /**
     * @param  string  $host  the header value, e.g. `example.com:8080` or `[::1]:8000`
     * @return array{0: string, 1: int|null}  the name, an IPv6 literal kept in its brackets, and
     *   the port, or null when the header names none or names one that is not a number
     */
    public static function split(string $host): array
    {
        $host = trim($host);

        if (str_starts_with($host, '[')) {
            $close = strpos($host, ']');

            if ($close === false) {
                return [$host, null];
            }

            $name = substr($host, 0, $close + 1);
            $rest = substr($host, $close + 1);

            return [$name, str_starts_with($rest, ':') ? self::port(substr($rest, 1)) : null];
        }

        $colon = strrpos($host, ':');

        if ($colon === false) {
            return [$host, null];
        }

        return [substr($host, 0, $colon), self::port(substr($host, $colon + 1))];
    }

    private static function port(string $digits): ?int
    {
        return ctype_digit($digits) ? (int) $digits : null;
    }
}
