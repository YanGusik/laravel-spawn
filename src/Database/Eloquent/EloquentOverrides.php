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
 * The copies are ordinary files under `overrides/laravel-13/<release>/`, edited by hand and
 * reviewable in a diff; `bin/refresh-eloquent-overrides.php` re-applies every edit to the files
 * of the installed Laravel and writes a copy for each file it has none for. Each copy is frozen
 * against the checksum of the Laravel file it was taken from, and a file has one copy per
 * upstream variant of it: the copy installed is the one taken from the very file the
 * application has. A copy is never put over a file it was not taken from, because a release
 * that moves a file can add a method or change one the copy would silently take away. A file
 * with no copy for its checksum leaves its group on Laravel's own class. Freezing is per group:
 * a release touching `HasEvents` must not take the relation fix down with it.
 *
 * `tests/EloquentOverridesTest.php` fails on the same mismatch, which is where a copy for the
 * new variant is meant to be taken.
 */
final class EloquentOverrides
{
    /** The refusal an operator asked for, which nothing reports as a fault. */
    public const SWITCHED_OFF = 'switched off through SPAWN_ELOQUENT_OVERRIDES=0';

    /**
     * The groups of copies, installed independently of each other.
     *
     * A group is the set of files that carry one fix, and it is all-or-nothing within itself:
     * half of the relation fix is worse than none of it. Group name => class name => [the
     * Laravel file, relative to Eloquent's own directory; the sha256 of each variant of that
     * file a copy was taken from => the release directory under `overrides/laravel-13/` that
     * holds the copy]. A copy sits at the same path below its release directory as the class
     * has in Laravel's `src/`.
     *
     * `relation constraints` needs both its files and neither can carry the fix alone.
     * `Relation` holds the window in the coroutine that opened it instead of in the property,
     * and `Concerns\HasRelationships` builds the relation classes of this package, which read
     * that window. Left with Laravel's relation classes, a `Relation` that leaves the flag
     * alone would have eager loading add a `where` on a key that is not there yet; left with
     * Laravel's `Relation`, the window would never open.
     */
    private const GROUPS = [
        'relation constraints' => [
            'Illuminate\\Database\\Eloquent\\Relations\\Relation' => [
                'Relations/Relation.php',
                [
                    // Laravel 13.26.1 to 13.37.x.
                    '30e8d8a056ed866be1ee586003094f9c233e2e1a8d42d732c366ba8f01982cc7' => '13.26.1',
                    // Laravel 13.38.0 and later, which adds Relation::clone().
                    '0b785232312e99af673281299ad42d9024f1919d8e25266864188a51645d48e4' => '13.42.0',
                ],
            ],
            'Illuminate\\Database\\Eloquent\\Concerns\\HasRelationships' => [
                'Concerns/HasRelationships.php',
                [
                    // Laravel 13.26.1 to 13.33.x.
                    'a7258ca67a51e13722a27ea62b9d3ed802a56c40d51920ffa23301acaf7b4059' => '13.26.1',
                    // Laravel 13.34.0 and later: getMorphClass() honours Relation::requiresMorphMap().
                    '503994cc37f53f8182ce05113655c1a916f6379a1d940d2112f517d23076e32f' => '13.42.0',
                ],
            ],
        ],
        'the mass-assignment window' => [
            'Illuminate\\Database\\Eloquent\\Concerns\\GuardsAttributes' => [
                'Concerns/GuardsAttributes.php',
                ['2e1b77306a0fbc050e1ae3c78d2b5b53d3033c347b4d0a9a2ada5160e1fb6d61' => '13.26.1'],
            ],
        ],
        'the model-event dispatcher' => [
            'Illuminate\\Database\\Eloquent\\Concerns\\HasEvents' => [
                'Concerns/HasEvents.php',
                ['e86185a511f4e5099315df5cc3959679502d7c9a71157cedf31a302fefc313cf' => '13.26.1'],
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
            $chosen = self::choose($copies);

            if (is_string($chosen)) {
                $refused[$group] = $chosen;

                continue;
            }

            $map += $chosen;
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
     * The Laravel file behind each copy and the checksums a copy was taken from, so that a test
     * can fail the day a Laravel release moves a file to a variant no copy was taken from.
     *
     * @return array<string, array{0: string|null, 1: list<string>}>  class name => [file, sha256s]
     */
    public static function frozenAgainst(): array
    {
        $frozen = [];

        foreach (self::GROUPS as $copies) {
            foreach ($copies as $class => [$relative, $variants]) {
                $frozen[$class] = [self::laravelFile($relative), array_keys($variants)];
            }
        }

        return $frozen;
    }

    /**
     * Which copy fits a Laravel file with this checksum, as a path relative to the package
     * root, or null when no copy of that variant is in the package. The refresh script asks it so
     * that it writes a copy only for a variant that has none.
     */
    public static function copyFor(string $class, string $checksum): ?string
    {
        foreach (self::GROUPS as $copies) {
            if (isset($copies[$class])) {
                $variants = $copies[$class][1];
                $copy = isset($variants[$checksum]) ? self::copyRelative($class, $variants[$checksum]) : null;

                return $copy !== null && is_readable(self::root().DIRECTORY_SEPARATOR.$copy) ? $copy : null;
            }
        }

        return null;
    }

    /**
     * The copies of one group that fit the installed Laravel, or why the group cannot be
     * installed.
     *
     * @param  array<string, array{0: string, 1: array<string, string>}>  $copies
     * @return array<string, string>|string  class name => absolute path of its copy, or the refusal
     */
    private static function choose(array $copies): array|string
    {
        if (! function_exists('Async\\coroutine_context')) {
            return 'the true_async extension is not loaded';
        }

        if (getenv('SPAWN_ELOQUENT_OVERRIDES') === '0') {
            return self::SWITCHED_OFF;
        }

        $chosen = [];

        foreach ($copies as $class => [$relative, $variants]) {
            if (class_exists($class, false) || trait_exists($class, false)) {
                return "$class was already loaded before the copies could be registered";
            }

            [$copy, $reason] = self::fittingCopy($class, $relative, $variants);

            if ($copy === null) {
                return $reason;
            }

            $chosen[$class] = $copy;
        }

        return $chosen;
    }

    /**
     * Whether the autoloader answers with this package's copies for a whole group.
     *
     * @param  array<string, array{0: string, 1: array<string, string>}>  $copies
     */
    private static function groupIsInstalled(array $copies): bool
    {
        foreach ($copies as $class => [$relative, $variants]) {
            [$copy] = self::fittingCopy($class, $relative, $variants);

            foreach (ClassLoader::getRegisteredLoaders() as $loader) {
                if ($copy !== null && $loader->findFile($class) === $copy) {
                    continue 2;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * Why this group cannot be installed, or null when it can.
     *
     * @param  array<string, array{0: string, 1: array<string, string>}>  $copies
     */
    private static function refusal(array $copies): ?string
    {
        $chosen = self::choose($copies);

        return is_string($chosen) ? $chosen : null;
    }

    /**
     * The copy taken from the Laravel file the application has, or why there is none.
     *
     * @param  array<string, string>  $variants  sha256 => release directory
     * @return array{0: string, 1: null}|array{0: null, 1: string}  [absolute path, null] or [null, reason]
     */
    private static function fittingCopy(string $class, string $relative, array $variants): array
    {
        $laravel = self::laravelFile($relative);

        if ($laravel === null) {
            return [null, "no registered autoloader knows where Laravel keeps $class"];
        }

        $release = $variants[hash_file('sha256', $laravel)] ?? null;

        if ($release === null) {
            return [null, "Laravel's own $class has changed since the last copy was taken from it"];
        }

        $copy = self::root().DIRECTORY_SEPARATOR.self::copyRelative($class, $release);

        return is_readable($copy) ? [$copy, null] : [null, "the copy of $class is missing from the package"];
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

    private static function copyRelative(string $class, string $release): string
    {
        return 'overrides/laravel-13/'.$release.'/'.str_replace('\\', '/', $class).'.php';
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
