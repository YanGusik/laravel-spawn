<?php

namespace Spawn\Laravel\Database\Eloquent;

use Composer\Autoload\ClassLoader;

/**
 * Puts this package's copies of two Eloquent classes in front of Laravel's own.
 *
 * `Relation::$constraints` is a static property that eager loading switches off while it builds
 * a relation object and restores from a value the caller captured. Class statics live per worker
 * thread, so every coroutine of a worker shares that flag, and the callback yields as soon as a
 * model boots against config or cache inside it: overlapping windows restore each other's value
 * and leave the flag off for the life of the worker, and while it is off every relation built by
 * every sibling coroutine comes out without its `where foreign_key = ?`.
 *
 * Two files carry the fix and neither can carry it alone. `Relation` holds the window in the
 * coroutine that opened it instead of in the property, and `Concerns\HasRelationships` builds
 * the relation classes of this package, which read that window. Left with Laravel's relation
 * classes, a `Relation` that no longer switches the flag off would have eager loading add a
 * `where` on a key that is not there yet; left with Laravel's `Relation`, the window would
 * never open.
 *
 * The copies are ordinary files under `overrides/`, edited by hand and reviewable in a diff.
 * They are frozen against the Laravel release they were taken from, so each is paired with the
 * checksum of that original: a release that touches either file leaves the application on
 * Laravel's own classes rather than on a copy that has quietly fallen behind, and status() says
 * so. tests/EloquentOverridesTest.php fails on the same mismatch, which is where the copies are
 * meant to be brought forward.
 */
final class EloquentOverrides
{
    /**
     * Class name => [the copy, relative to the package root; the Laravel file it was taken
     * from, relative to Eloquent's own directory; the sha256 that file must still have].
     */
    private const COPIES = [
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
        'Illuminate\\Database\\Eloquent\\Concerns\\GuardsAttributes' => [
            'overrides/laravel-13/Illuminate/Database/Eloquent/Concerns/GuardsAttributes.php',
            'Concerns/GuardsAttributes.php',
            '2e1b77306a0fbc050e1ae3c78d2b5b53d3033c347b4d0a9a2ada5160e1fb6d61',
        ],
        'Illuminate\\Database\\Eloquent\\Concerns\\HasEvents' => [
            'overrides/laravel-13/Illuminate/Database/Eloquent/Concerns/HasEvents.php',
            'Concerns/HasEvents.php',
            'e86185a511f4e5099315df5cc3959679502d7c9a71157cedf31a302fefc313cf',
        ],
    ];

    /**
     * Point the autoloader at the copies, or leave Laravel's classes alone and say why.
     *
     * Either both are installed or neither is. Returns false when nothing was installed, which
     * is not an error: the application then runs on Laravel's own classes and behaves as it does
     * without this package.
     */
    public static function install(): bool
    {
        if (self::refusal() !== null) {
            return false;
        }

        $map = [];

        foreach (self::COPIES as $class => [$copy]) {
            $map[$class] = self::copyPath($copy);
        }

        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $loader->addClassMap($map);
        }

        return true;
    }

    /**
     * Whether the copies are in front of Laravel's classes, and when they are not, what
     * stands in the way.
     */
    public static function status(): string
    {
        if (self::isInstalled()) {
            return 'installed';
        }

        return 'not installed: '.(self::refusal() ?? 'the copies were not registered');
    }

    /**
     * Whether the autoloader answers with this package's copies.
     */
    public static function isInstalled(): bool
    {
        foreach (self::COPIES as $class => $copy) {
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
     * The Laravel file behind each copy and the checksum it must still have, so that a test
     * can fail the day a Laravel release moves ahead of the copies.
     *
     * @return array<string, array{0: string|null, 1: string}>  class name => [file, sha256]
     */
    public static function frozenAgainst(): array
    {
        $frozen = [];

        foreach (self::COPIES as $class => [, $relative, $checksum]) {
            $frozen[$class] = [self::laravelFile($relative), $checksum];
        }

        return $frozen;
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

    private static function refusal(): ?string
    {
        if (! function_exists('Async\\coroutine_context')) {
            return 'the true_async extension is not loaded';
        }

        if (getenv('SPAWN_ELOQUENT_OVERRIDES') === '0') {
            return 'switched off through SPAWN_ELOQUENT_OVERRIDES=0';
        }

        foreach (self::COPIES as $class => [$copy, $relative, $checksum]) {
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
