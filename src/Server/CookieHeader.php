<?php

namespace Spawn\Laravel\Server;

/**
 * Reads a `Cookie` request header into the array a Laravel request takes as its cookies.
 *
 * Values are decoded as PHP decodes them into `$_COOKIE`: `%XX` is decoded and `+` stays `+`
 * (rawurldecode()). Symfony percent-encodes what it sets — the `==` padding of an encrypted
 * session or XSRF cookie leaves as `%3D%3D` — so a value left encoded fails to decrypt and the
 * session is lost on every request.
 *
 * Unlike `$_COOKIE`, names are kept as sent: a dot or a space in a name is not turned into `_`,
 * and `name[key]` does not build an array.
 */
final class CookieHeader
{
    /**
     * @param  string  $header  the header value, without the `Cookie:` name; empty for none
     * @return array<string, string>  name => decoded value; of two cookies with one name the
     *   first sent wins, as a browser sends the one with the more specific path first
     */
    public static function parse(string $header): array
    {
        $cookies = [];

        foreach (explode(';', $header) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, null);
            $name = trim($name);

            if ($name === '' || $value === null || array_key_exists($name, $cookies)) {
                continue;
            }

            $cookies[$name] = rawurldecode(trim($value));
        }

        return $cookies;
    }
}
