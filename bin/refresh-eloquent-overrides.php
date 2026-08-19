<?php

/**
 * Take the copies under overrides/ from the installed Laravel again.
 *
 * The two files this package puts in front of Eloquent are frozen against the release they
 * were copied from, and a release that touches either one leaves the application on Laravel's
 * own classes. Bringing the copies forward is this script: it re-copies both files, re-applies
 * the edits, and prints the checksums to paste into EloquentOverrides.
 *
 * It is a tool for whoever updates the package, not part of it — nothing at runtime runs it,
 * and its output is committed as plain files.
 *
 *   php bin/refresh-eloquent-overrides.php
 *
 * Every replacement below is asserted. When one no longer matches, the script stops and names
 * it: Laravel has changed that code, and the edit has to be redone by hand rather than guessed.
 */

$root = dirname(__DIR__);
$eloquent = $root.'/vendor/laravel/framework/src/Illuminate/Database/Eloquent';
$copies = $root.'/overrides/laravel-13/Illuminate/Database/Eloquent';

if (! is_dir($eloquent)) {
    fwrite(STDERR, "Laravel is not installed under vendor/.\n");
    exit(1);
}

/**
 * @param  list<array{0: string, 1: string}>  $replacements
 */
function rewrite(string $source, array $replacements, string $what): string
{
    foreach ($replacements as [$from, $to]) {
        if (substr_count($source, $from) !== 1) {
            fwrite(STDERR, "$what: this no longer appears exactly once, redo the edit by hand:\n\n$from\n");
            exit(1);
        }

        $source = str_replace($from, $to, $source);
    }

    return $source;
}

// --- Relation: the window moves from the two static properties into the coroutine ----------

$relation = rewrite(file_get_contents($eloquent.'/Relations/Relation.php'), [
    [
        <<<'PHP'
        $previous = static::$constraints;
        $previousConstraintsForNestedRelations = static::$constraintsForNestedRelations;

        static::$constraints = false;
        static::$constraintsForNestedRelations = $constraintsForNestedRelations;

        // When resetting the relation where clause, we want to shift the first element
        // off of the bindings, leaving only the constraints that the developers put
        // as "extra" on the relationships, and not original relation constraints.
        try {
            return $callback();
        } finally {
            static::$constraints = $previous;
            static::$constraintsForNestedRelations = $previousConstraintsForNestedRelations;
        }
PHP,
        <<<'PHP'
        // Upstream saves the two properties and restores them from the saved values. Two
        // coroutines of one worker thread cannot both do that: the second to enter saves the
        // first's disabled state and hands it back on the way out, and the flags never return.
        // The decision belongs to the coroutine that made it instead, and the properties below
        // are left alone — $constraints stays true, which is what the relation classes of this
        // package expect to find behind their own decision.
        return RelationWindow::open($callback, (bool) $constraintsForNestedRelations);
PHP,
    ],
    [
        <<<'PHP'
        $previous = static::$constraints;

        static::$constraints = true;

        try {
            return $callback();
        } finally {
            static::$constraints = $previous;
        }
PHP,
        '        return RelationWindow::closed($callback);',
    ],
    [
        'return static::$constraintsForNestedRelations',
        'return RelationWindow::forNestedRelations()',
    ],
    [
        'use Illuminate\Support\Traits\Macroable;',
        "use Illuminate\Support\Traits\Macroable;\nuse Spawn\Laravel\Database\Eloquent\RelationWindow;",
    ],
], 'Relation');

// --- HasRelationships: the ten factories build this package's relation classes --------------

$classes = [
    'BelongsTo', 'BelongsToMany', 'HasMany', 'HasManyThrough', 'HasOne',
    'HasOneThrough', 'MorphMany', 'MorphOne', 'MorphTo', 'MorphToMany',
];

$replacements = array_map(
    static fn (string $class): array => ["return new $class(", "return new Coroutine$class("],
    $classes
);

$imports = implode("\n", array_map(
    static fn (string $class): string => "use Spawn\\Laravel\\Database\\Eloquent\\Relations\\Coroutine$class;",
    $classes
));

$replacements[] = ['use Illuminate\Support\Arr;', "use Illuminate\Support\Arr;\n".$imports];

$hasRelationships = rewrite(
    file_get_contents($eloquent.'/Concerns/HasRelationships.php'),
    $replacements,
    'HasRelationships'
);

file_put_contents($copies.'/Relations/Relation.php', $relation);
file_put_contents($copies.'/Concerns/HasRelationships.php', $hasRelationships);

echo "Copies rewritten. Put these into EloquentOverrides::COPIES:\n\n";

foreach ([
    'Relation' => $eloquent.'/Relations/Relation.php',
    'HasRelationships' => $eloquent.'/Concerns/HasRelationships.php',
] as $name => $file) {
    printf("  %-18s %s\n", $name, hash_file('sha256', $file));
}
