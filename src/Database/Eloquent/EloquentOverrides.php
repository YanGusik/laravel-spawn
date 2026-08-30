<?php

namespace Spawn\Laravel\Database\Eloquent;

use Composer\Autoload\ClassLoader;

/**
 * Puts this package's copies of four Eloquent classes in front of Laravel's own.
 *
 * Each copy exists for the same reason: Eloquent keeps a decision of the moment in a class
 * static, sets it, runs a callback and restores it. A class static is one value per worker
 * thread, so a callback that suspends hands that decision to every coroutine served in the
 * meantime. The copies keep the value in the coroutine's own context instead — see
 * {@see RelationWindow}, {@see GuardWindow} and {@see EventDispatcherWindow} for what each one
 * holds, and the group comments below for what breaks without it.
 *
 * The copies are ordinary files under `overrides/`, edited by hand and reviewable in a diff;
 * `bin/refresh-eloquent-overrides.php` re-applies every edit to a fresh copy and prints the
 * checksums. Each is frozen against the checksum of the Laravel file it was taken from, so a
 * release that moves that file leaves the application on Laravel's own class rather than on a
 * copy that has quietly fallen behind. Freezing is per group: a release touching `HasEvents`
 * must not take the relation fix down with it.
 *
 * `tests/EloquentOverridesTest.php` fails on the same mismatch, which is where the copies are
 * meant to be brought forward.
 */
final class EloquentOverrides
{
    /** The refusal an operator asked for, which nothing reports as a fault. */
    public const SWITCHED_OFF = 'switched off through SPAWN_ELOQUENT_OVERRIDES=0';

    /**
     * The groups of copies, installed independently of each other.
     *
     * A group is the set of files that carry one fix, and it is all-or-nothing within itself:
     * half of the relation fix is worse than none of it. Group name => class name => [the copy,
     * relative to the package root; the Laravel file it was taken from, relative to Eloquent's
     * own directory; the sha256 that file must still have].
     *
     * `relation constraints` needs both its files and neither can carry the fix alone.
     * `Relation` holds the window in the coroutine that opened it instead of in the property,
     * and `Concerns\HasRelationships` builds the relation classes of this package, which read
     * that window. Left with Laravel's relation classes, a `Relation` that no longer switches
     * the flag off would have eager loading add a `where` on a key that is not there yet; left
     * with Laravel's `Relation`, the window would never open.
     */
    private const GROUPS = [
        'relation constraints' => [
            'Illuminate\\Database\\Eloquent\\Relations\\Relation' => [
                'overrides/laravel-13/Illuminate/Database/Eloquent/Relations/Relation.php',
                'Relations/Relation.php',
                '30e8d8a056ed866be1ee586003094f9c233e2e1a8d42d732c366ba8f01982cc7',
            ],
            'Illuminate\\Database\\Eloquent\\Concerns\\HasRelationships' => [
                'overrides/laravel-13/Illuminate/Database/Eloquent/Concerns/HasRelationships.php',
                'Concerns/HasRelationships.php',
                'a7258ca67a51e13722a27ea62b9d3ed802a56c40d51920ffa23301acaf7b4059',
            ],
        ],
        'the mass-assignment window' => [
            'Illuminate\\Database\\Eloquent\\Concerns\\GuardsAttributes' => [
                'overrides/laravel-13/Illuminate/Database/Eloquent/Concerns/GuardsAttributes.php',
                'Concerns/GuardsAttributes.php',
                '2e1b77306a0fbc050e1ae3c78d2b5b53d3033c347b4d0a9a2ada5160e1fb6d61',
            ],
        ],
        'the model-event dispatcher' => [
            'Illuminate\\Database\\Eloquent\\Concerns\\HasEvents' => [
                'overrides/laravel-13/Illuminate/Database/Eloquent/Concerns/HasEvents.php',
                'Concerns/HasEvents.php',
                'e86185a511f4e5099315df5cc3959679502d7c9a71157cedf31a302fefc313cf',
            ],
        ],
    ];

    /**
     * Point the autoloader at every group of copies that is fit to install.
     *
     * A group whose Laravel file has moved is left out and named in the result; the others are
     * installed regardless, because the fixes are independent. An empty result means every
     * group is in front of Laravel's classes.
     *
     * @return array<string, string>  group name => why it was left out
     */
    public static function install(): array
    {
        $refused = [];
        $map = [];

        foreach (self::GROUPS as $group => $copies) {
            $refusal = self::refusal($copies);

            if ($refusal !== null) {
                $refused[$group] = $refusal;

                continue;
            }

            foreach ($copies as $class => [$copy]) {
                $map[$class] = self::copyPath($copy);
            }
        }

        if ($map !== []) {
            foreach (ClassLoader::getRegisteredLoaders() as $loader) {
                $loader->addClassMap($map);
            }
        }

        return $refused;
    }

    /**
     * Whether every group is in front of Laravel's classes.
     */
    public static function isInstalled(): bool
    {
        foreach (self::GROUPS as $copies) {
            if (! self::groupIsInstalled($copies)) {
                return false;
            }
        }

        return true;
    }

    /**
     * What state each group is in, as one line: the group name and either `installed` or the
     * reason it is not.
     */
    public static function status(): string
    {
        if (self::isInstalled()) {
            return 'installed';
        }

        $parts = [];

        foreach (self::GROUPS as $group => $copies) {
            $parts[] = $group.': '.(self::groupIsInstalled($copies)
                ? 'installed'
                : (self::refusal($copies) ?? 'the copies were not registered'));
        }

        return implode('; ', $parts);
    }

    /**
     * The Laravel file behind each copy and the checksum it must still have, so that a test
     * can fail the day a Laravel release moves ahead of the copies.
     *
     * @return array<string, array{0: string|null, 1: string}>  class name => [file, sha256]
     */
    public static function frozenAgainst(): array
    {
        $frozen = [];

        foreach (self::GROUPS as $copies) {
            foreach ($copies as $class => [, $relative, $checksum]) {
                $frozen[$class] = [self::laravelFile($relative), $checksum];
            }
        }

        return $frozen;
    }

    /**
     * Whether the autoloader answers with this package's copies for a whole group.
     *
     * @param  array<string, array{0: string, 1: string, 2: string}>  $copies
     */
    private static function groupIsInstalled(array $copies): bool
    {
        foreach ($copies as $class => $copy) {
            foreach (ClassLoader::getRegisteredLoaders() as $loader) {
                if ($loader->findFile($class) === self::copyPath($copy[0])) {
                    continue 2;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * Where Laravel keeps one of its own Eloquent files. Located from a class this package
     * never replaces, because once a copy is registered the autoloader answers with it.
     */
    private static function laravelFile(string $relative): ?string
    {
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $anchor = $loader->findFile('Illuminate\\Database\\Eloquent\\Builder');

            if (is_string($anchor) && is_file($anchor)) {
                $file = dirname($anchor).DIRECTORY_SEPARATOR.$relative;

                return is_file($file) ? $file : null;
            }
        }

        return null;
    }

    /**
     * Why this group cannot be installed, or null when it can.
     *
     * @param  array<string, array{0: string, 1: string, 2: string}>  $copies
     */
    private static function refusal(array $copies): ?string
    {
        if (! function_exists('Async\\coroutine_context')) {
            return 'the true_async extension is not loaded';
        }

        if (getenv('SPAWN_ELOQUENT_OVERRIDES') === '0') {
            return self::SWITCHED_OFF;
        }

        foreach ($copies as $class => [$copy, $relative, $checksum]) {
            if (class_exists($class, false) || trait_exists($class, false)) {
                return "$class was already loaded before the copies could be registered";
            }

            if (! is_readable(self::copyPath($copy))) {
                return "the copy of $class is missing from the package";
            }

            $laravel = self::laravelFile($relative);

            if ($laravel === null) {
                return "no registered autoloader knows where Laravel keeps $class";
            }

            if (hash_file('sha256', $laravel) !== $checksum) {
                return "Laravel's own $class has changed since the copy was taken from it";
            }
        }

        return null;
    }

    private static function copyPath(string $relative): string
    {
        return self::root().DIRECTORY_SEPARATOR.$relative;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
