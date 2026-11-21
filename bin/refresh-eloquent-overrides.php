<?php

/**
 * Take a copy under overrides/ for each Eloquent file of the installed Laravel that has none.
 *
 * The files this package puts in front of Eloquent are frozen against the variant of the
 * Laravel file they were copied from, and a release that moves one of them leaves its group on
 * Laravel's own classes until a copy of the new variant exists. Taking it is this script: it
 * copies every file whose checksum no copy was taken from, re-applies the edits, writes the
 * result under overrides/laravel-13/<installed release>/ and prints the lines to add to
 * EloquentOverrides::GROUPS. A file that already has its copy is left as it is.
 *
 * It is a tool for whoever updates the package, not part of it — nothing at runtime runs it,
 * and its output is committed as plain files.
 *
 *   php bin/refresh-eloquent-overrides.php [vendor directory]
 *
 * The vendor directory defaults to the package's own; another one, holding a different Laravel
 * release, takes the copies for that release without reinstalling the package.
 *
 * Every replacement below is asserted, and so is the number of times Eloquent reaches each
 * replaced static, in any of its files: a release that adds a reader or a writer of one outside
 * the edited lines would leave the copy covering part of it. When an assertion fails, the script
 * names it and leaves that file's copy unwritten: Laravel has changed that code, and the edit
 * has to be redone by hand rather than guessed. The copies of the other files are still
 * written, because the groups are installed independently, and the script exits non-zero.
 */

$root = dirname(__DIR__);
$vendor = $argv[1] ?? $root.'/vendor';
$eloquent = $vendor.'/laravel/framework/src/Illuminate/Database/Eloquent';

if (! is_dir($eloquent)) {
    fwrite(STDERR, "Laravel is not installed under $vendor.\n");
    exit(1);
}

require $root.'/src/Database/Eloquent/EloquentOverrides.php';

$application = file_get_contents($vendor.'/laravel/framework/src/Illuminate/Foundation/Application.php');

if (! preg_match("/const VERSION = '([^']+)'/", $application, $version)) {
    fwrite(STDERR, "The installed Laravel release could not be read from Application::VERSION.\n");
    exit(1);
}

$release = $version[1];

/** @var array<string, string> $failures  file => what no longer matched */
$failures = [];

/**
 * How many times Eloquent reaches a static property by name — `static::$name`, `self::$name`,
 * `Model::$name`, `$model::$name` — in any of its files.
 */
function staticUses(string $property): int
{
    global $eloquent;

    $uses = 0;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($eloquent, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() === 'php') {
            $uses += preg_match_all('/::\$'.preg_quote($property, '/').'\b/', file_get_contents($file->getPathname()));
        }
    }

    return $uses;
}

/**
 * The Laravel file with the edits applied, or null when one no longer matches; the mismatch is
 * then printed and kept in $failures.
 *
 * @param  list<array{0: string, 1: string}>  $replacements
 * @param  array<string, int>  $uses  static property => how many times Eloquent reaches it
 */
function rewrite(string $source, array $replacements, string $what, array $uses = []): ?string
{
    global $failures;

    foreach ($uses as $property => $times) {
        if (staticUses($property) !== $times) {
            $failures[$what] = "Eloquent no longer reaches \$$property $times times: a reader or a writer was added or removed";
            fwrite(STDERR, "$what: {$failures[$what]}, redo the edit by hand.\n");

            return null;
        }
    }

    foreach ($replacements as [$from, $to]) {
        if (substr_count($source, $from) !== 1) {
            $failures[$what] = 'a replaced fragment no longer appears exactly once';
            fwrite(STDERR, "$what: this no longer appears exactly once, redo the edit by hand:\n\n$from\n");

            return null;
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

// --- GuardsAttributes: the unguarded() window moves into the coroutine ----------------------

$guardsAttributes = rewrite(file_get_contents($eloquent.'/Concerns/GuardsAttributes.php'), [
    [
        'use Illuminate\Database\Eloquent\Relations\Pivot;',
        "use Illuminate\Database\Eloquent\Relations\Pivot;\nuse Spawn\\Laravel\\Database\\Eloquent\\GuardWindow;",
    ],
    [
        <<<'PHP'
        return self::$unguarded === true
PHP,
        <<<'PHP'
        return static::isUnguarded() === true
PHP,
    ],
    [
        <<<'PHP'
        return static::$unguarded;
PHP,
        <<<'PHP'
        return GuardWindow::isUnguarded(static::$unguarded);
PHP,
    ],
    [
        <<<'PHP'
        if (static::$unguarded) {
            return $callback();
        }

        static::unguard();

        try {
            return $callback();
        } finally {
            static::reguard();
        }
PHP,
        <<<'PHP'
        if (static::isUnguarded()) {
            return $callback();
        }

        // The window replaces the pair of unguard()/reguard() calls upstream makes here: a
        // callback that suspends would otherwise leave the static off for every coroutine of
        // this worker until it resumed. unguard() itself keeps writing the static, because a
        // caller that never restores it is asking for exactly that.
        return GuardWindow::open(static fn () => $callback());
PHP,
    ],
    [
        <<<'PHP'
        if (static::$unguarded) {
            return true;
        }
PHP,
        <<<'PHP'
        if (static::isUnguarded()) {
            return true;
        }
PHP,
    ],
    [
        'if (count($this->getFillable()) > 0 && ! static::$unguarded) {',
        'if (count($this->getFillable()) > 0 && ! static::isUnguarded()) {',
    ],
], 'GuardsAttributes');

// --- HasEvents: the withoutEvents() dispatcher moves into the coroutine ---------------------

$hasEvents = rewrite(file_get_contents($eloquent.'/Concerns/HasEvents.php'), [
    [
        'use ReflectionClass;',
        "use ReflectionClass;\nuse Spawn\\Laravel\\Database\\Eloquent\\EventDispatcherWindow;",
    ],
    [
        <<<'PHP'
        if (isset(static::$dispatcher)) {
            $name = static::class;

            static::$dispatcher->listen("eloquent.{$event}: {$name}", $callback);
        }
PHP,
        <<<'PHP'
        $dispatcher = static::getEventDispatcher();

        if (isset($dispatcher)) {
            $name = static::class;

            $dispatcher->listen("eloquent.{$event}: {$name}", $callback);
        }
PHP,
    ],
    [
        <<<'PHP'
        if (! isset(static::$dispatcher)) {
            return true;
        }
PHP,
        <<<'PHP'
        $dispatcher = static::getEventDispatcher();

        if (! isset($dispatcher)) {
            return true;
        }
PHP,
    ],
    [
        'return ! empty($result) ? $result : static::$dispatcher->{$method}(',
        'return ! empty($result) ? $result : $dispatcher->{$method}(',
    ],
    [
        '$result = static::$dispatcher->$method(new $this->dispatchesEvents[$event]($this));',
        '$result = static::getEventDispatcher()->$method(new $this->dispatchesEvents[$event]($this));',
    ],
    [
        <<<'PHP'
        if (! isset(static::$dispatcher)) {
            return;
        }

        $instance = new static;

        foreach ($instance->getObservableEvents() as $event) {
            static::$dispatcher->forget("eloquent.{$event}: ".static::class);
        }

        foreach ($instance->dispatchesEvents as $event) {
            static::$dispatcher->forget($event);
        }
PHP,
        <<<'PHP'
        $dispatcher = static::getEventDispatcher();

        if (! isset($dispatcher)) {
            return;
        }

        $instance = new static;

        foreach ($instance->getObservableEvents() as $event) {
            $dispatcher->forget("eloquent.{$event}: ".static::class);
        }

        foreach ($instance->dispatchesEvents as $event) {
            $dispatcher->forget($event);
        }
PHP,
    ],
    [
        <<<'PHP'
        return static::$dispatcher;
PHP,
        <<<'PHP'
        return EventDispatcherWindow::current(static::$dispatcher);
PHP,
    ],
    [
        <<<'PHP'
        if ($dispatcher) {
            static::setEventDispatcher(new NullDispatcher($dispatcher));
        }

        try {
            return $callback();
        } finally {
            if ($dispatcher) {
                static::setEventDispatcher($dispatcher);
            }
        }
PHP,
        <<<'PHP'
        if (! $dispatcher) {
            return $callback();
        }

        // The window replaces the pair of setEventDispatcher() calls upstream makes here: a
        // callback that suspends would otherwise route every coroutine's model events into a
        // NullDispatcher, which answers each call and delivers nothing, until it resumed.
        return EventDispatcherWindow::open(new NullDispatcher($dispatcher), static fn () => $callback());
PHP,
    ],
], 'HasEvents');

// --- HasTimestamps: the withoutTimestampsOn() classes move into the coroutine ---------------

$hasTimestamps = rewrite(file_get_contents($eloquent.'/Concerns/HasTimestamps.php'), [
    [
        'use Illuminate\Support\Facades\Date;',
        "use Illuminate\Support\Facades\Date;\nuse Spawn\\Laravel\\Database\\Eloquent\\IgnoredClassesWindow;",
    ],
    [
        <<<'PHP'
        static::$ignoreTimestampsOn = array_values(array_merge(static::$ignoreTimestampsOn, $models));

        try {
            return $callback();
        } finally {
            foreach ($models as $model) {
                if (($key = array_search($model, static::$ignoreTimestampsOn, true)) !== false) {
                    unset(static::$ignoreTimestampsOn[$key]);
                }
            }
        }
PHP,
        <<<'PHP'
        // The window replaces the shared list upstream adds the classes to here: a callback
        // that suspends would otherwise have every coroutine of this worker save its rows
        // without timestamps until it resumed.
        return IgnoredClassesWindow::open(IgnoredClassesWindow::TIMESTAMPS, $models, static fn () => $callback());
PHP,
    ],
    [
        'return array_any(static::$ignoreTimestampsOn, fn ($ignoredClass) => $class === $ignoredClass || is_subclass_of($class, $ignoredClass));',
        'return IgnoredClassesWindow::ignores(IgnoredClassesWindow::TIMESTAMPS, $class, static::$ignoreTimestampsOn);',
    ],
], 'HasTimestamps', ['ignoreTimestampsOn' => 5]);

// --- BroadcastsEvents: the broadcast gate reads the coroutine's window ----------------------

$broadcastsEvents = rewrite(file_get_contents($eloquent.'/BroadcastsEvents.php'), [
    [
        'use Illuminate\Support\Arr;',
        "use Illuminate\Support\Arr;\nuse Spawn\\Laravel\\Database\\Eloquent\\BroadcastWindow;",
    ],
    [
        'if (! static::$isBroadcasting) {',
        'if (! BroadcastWindow::isBroadcasting(static::$isBroadcasting)) {',
    ],
], 'BroadcastsEvents', ['isBroadcasting' => 4]);

// --- Model: touching, broadcasting and the isset() strict-mode switch move into the coroutine

$model = rewrite(file_get_contents($eloquent.'/Model.php'), [
    [
        'use Stringable;',
        "use Spawn\\Laravel\\Database\\Eloquent\\BroadcastWindow;\n"
            ."use Spawn\\Laravel\\Database\\Eloquent\\IgnoredClassesWindow;\n"
            ."use Spawn\\Laravel\\Database\\Eloquent\\MissingAttributeWindow;\n"
            .'use Stringable;',
    ],
    [
        <<<'PHP'
        static::$ignoreOnTouch = array_values(array_merge(static::$ignoreOnTouch, $models));

        try {
            $callback();
        } finally {
            static::$ignoreOnTouch = array_values(array_diff(static::$ignoreOnTouch, $models));
        }
PHP,
        <<<'PHP'
        // The window replaces the shared list upstream adds the classes to here: a callback
        // that suspends would otherwise leave the parents of every coroutine of this worker
        // untouched until it resumed.
        IgnoredClassesWindow::open(IgnoredClassesWindow::TOUCH, $models, static fn () => $callback());
PHP,
    ],
    [
        'return array_any(static::$ignoreOnTouch, fn ($ignoredClass) => $class === $ignoredClass || is_subclass_of($class, $ignoredClass));',
        'return IgnoredClassesWindow::ignores(IgnoredClassesWindow::TOUCH, $class, static::$ignoreOnTouch);',
    ],
    [
        <<<'PHP'
        $isBroadcasting = static::$isBroadcasting;

        static::$isBroadcasting = false;

        try {
            return $callback();
        } finally {
            static::$isBroadcasting = $isBroadcasting;
        }
PHP,
        <<<'PHP'
        // The window replaces the save, write and restore of the static upstream makes here:
        // a callback that suspends would otherwise silence every coroutine's broadcasts, and
        // two overlapping calls would leave them off for good.
        return BroadcastWindow::open(static fn () => $callback());
PHP,
    ],
    [
        <<<'PHP'
        return static::$modelsShouldPreventAccessingMissingAttributes;
PHP,
        <<<'PHP'
        return MissingAttributeWindow::prevents(static::$modelsShouldPreventAccessingMissingAttributes);
PHP,
    ],
    [
        <<<'PHP'
        $shouldPrevent = static::$modelsShouldPreventAccessingMissingAttributes;

        static::$modelsShouldPreventAccessingMissingAttributes = false;

        try {
            return ! is_null($this->getAttribute($offset));
        } finally {
            static::$modelsShouldPreventAccessingMissingAttributes = $shouldPrevent;
        }
PHP,
        <<<'PHP'
        if (! static::$modelsShouldPreventAccessingMissingAttributes) {
            return ! is_null($this->getAttribute($offset));
        }

        // The window replaces the save, write and restore of the static upstream makes here:
        // an accessor that suspends would otherwise turn strict mode off for every coroutine
        // of this worker, and two overlapping reads would leave it off for good.
        return MissingAttributeWindow::open(fn () => ! is_null($this->getAttribute($offset)));
PHP,
    ],
], 'Model', [
    'ignoreOnTouch' => 5,
    'isBroadcasting' => 4,
    'modelsShouldPreventAccessingMissingAttributes' => 5,
]);

$written = 0;

foreach ([
    'Illuminate\Database\Eloquent\Relations\Relation' => ['Relations/Relation.php', $relation],
    'Illuminate\Database\Eloquent\Concerns\HasRelationships' => ['Concerns/HasRelationships.php', $hasRelationships],
    'Illuminate\Database\Eloquent\Concerns\GuardsAttributes' => ['Concerns/GuardsAttributes.php', $guardsAttributes],
    'Illuminate\Database\Eloquent\Concerns\HasEvents' => ['Concerns/HasEvents.php', $hasEvents],
    'Illuminate\Database\Eloquent\Concerns\HasTimestamps' => ['Concerns/HasTimestamps.php', $hasTimestamps],
    'Illuminate\Database\Eloquent\BroadcastsEvents' => ['BroadcastsEvents.php', $broadcastsEvents],
    'Illuminate\Database\Eloquent\Model' => ['Model.php', $model],
] as $class => [$relative, $copy]) {
    if ($copy === null) {
        echo "  $class: not written, see above\n";

        continue;
    }

    $checksum = hash_file('sha256', $eloquent.'/'.$relative);
    $existing = Spawn\Laravel\Database\Eloquent\EloquentOverrides::copyFor($class, $checksum);

    if ($existing !== null) {
        echo "  $class: $existing already fits\n";

        continue;
    }

    $target = $root.'/overrides/laravel-13/'.$release.'/'.str_replace('\\', '/', $class).'.php';

    if (! is_dir(dirname($target))) {
        mkdir(dirname($target), 0777, true);
    }

    file_put_contents($target, $copy);
    $written++;

    echo "  $class: written; add to EloquentOverrides::GROUPS:\n      '$checksum' => '$release',\n";
}

echo $written === 0 && $failures === [] ? "\nEvery file of Laravel $release already has its copy.\n" : "\n";

exit($failures === [] ? 0 : 1);
