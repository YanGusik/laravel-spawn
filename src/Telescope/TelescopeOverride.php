<?php

namespace Spawn\Laravel\Telescope;

/**
 * Which copy of Laravel\Telescope\Telescope this package puts in front of the installed one.
 *
 * The copy keeps recording per coroutine ({@see CoroutineSafeRecording}). Each copy is frozen
 * against the checksum of the Telescope file it was taken from, and the copy loaded is the one
 * taken from the file the application has: a copy over another variant would take away whatever
 * that release added, `Telescope::cspNonce()` among it. With no copy for the installed variant
 * Telescope runs on its own class, recording shared between coroutines, and the worker says so.
 * `bin/refresh-telescope-override.php` takes a copy of a new variant.
 */
final class TelescopeOverride
{
    /**
     * sha256 of the installed `laravel/telescope/src/Telescope.php` => the release directory
     * under `overrides/telescope-5/` holding the copy taken from it.
     */
    private const VARIANTS = [
        // Telescope 5.16.1 to 5.20.x.
        'c7526e4553e5a10da1775020fbe53eace56ea6e1fe5fc74cc9e515a1aa81ccf2' => '5.20.0',
        '9fd98522f52de098757e2aadd2046a69b5bd7c3249f9ff934af5adb1ebc32ac6' => '5.21.0',
        // Telescope 5.22.x, which adds Telescope::cspNonce().
        '1e58d1853b1fb9133a95d6de7478f522c4f8a2186479ae63db7e02fee49fcde8' => '5.22.0',
        'cd6fcce17be5b02179af3bb69520bd66b2b4bb9f9ab5747bb0f419fc46189ab9' => '5.23.0',
        // Telescope 5.24.0 to 5.28.0.
        'b341b1cc566d3531681c129c64e0fdf970ca65b853b0f5620d02cacc753f1f8a' => '5.28.0',
    ];

    /**
     * The copy for the installed Telescope, as an absolute path, or null when there is none.
     *
     * @param  string|null  $installed  path of the installed Telescope.php, or null if not found
     */
    public static function file(?string $installed): ?string
    {
        if ($installed === null || ! is_file($installed)) {
            return null;
        }

        $copy = self::copyFor(hash_file('sha256', $installed));

        return $copy === null ? null : dirname(__DIR__, 2).'/'.$copy;
    }

    /**
     * The copy taken from a Telescope file with this checksum, relative to the package root, or
     * null when the package has none.
     */
    public static function copyFor(string $checksum): ?string
    {
        $release = self::VARIANTS[$checksum] ?? null;

        if ($release === null) {
            return null;
        }

        $copy = 'overrides/telescope-5/'.$release.'/Telescope.php';

        return is_readable(dirname(__DIR__, 2).'/'.$copy) ? $copy : null;
    }

    /**
     * The checksums a copy was taken from.
     *
     * @return list<string>
     */
    public static function checksums(): array
    {
        return array_keys(self::VARIANTS);
    }
}
