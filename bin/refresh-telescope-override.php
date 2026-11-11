<?php

/**
 * Take a copy of Laravel\Telescope\Telescope from the installed Telescope, when it has none.
 *
 * The package puts its own Telescope class in front of the installed one so that recording is
 * per coroutine (src/Telescope/CoroutineSafeRecording.php). The copy is frozen against the
 * variant of the file it was taken from, and a Telescope release that moves the file leaves the
 * application on Telescope's own class until a copy of the new variant exists. Taking it is this
 * script: it applies the edits below to the installed file, writes the result under
 * overrides/telescope-5/<installed release>/Telescope.php and prints the line to add to
 * TelescopeOverride::VARIANTS.
 *
 * It is a tool for whoever updates the package, not part of it — nothing at runtime runs it.
 *
 *   php bin/refresh-telescope-override.php [release]
 *
 * The release names the directory and defaults to the one vendor/composer/installed.json records;
 * pass it when vendor/laravel/telescope is a checkout of another tag.
 *
 * Every edit is asserted. When one no longer matches, the script stops and names it: Telescope
 * has changed that code, and the edit has to be redone by hand rather than guessed.
 */

$root = dirname(__DIR__);
$source = $root.'/vendor/laravel/telescope/src/Telescope.php';

if (! is_file($source)) {
    fwrite(STDERR, "Telescope is not installed under vendor/.\n");
    exit(1);
}

$installed = json_decode((string) file_get_contents($root.'/vendor/composer/installed.json'), true);
$packages = $installed['packages'] ?? $installed;
$release = null;

foreach ($packages as $package) {
    if (($package['name'] ?? null) === 'laravel/telescope') {
        $release = ltrim($package['version'], 'v');
    }
}

$release = $argv[1] ?? $release;

if ($release === null) {
    fwrite(STDERR, "The installed Telescope release could not be read from vendor/composer/installed.json.\n");
    exit(1);
}

require $root.'/src/Telescope/TelescopeOverride.php';

$checksum = hash_file('sha256', $source);
$existing = Spawn\Laravel\Telescope\TelescopeOverride::copyFor($checksum);

if ($existing !== null) {
    echo "Telescope $release already has its copy: $existing\n";
    exit(0);
}

function fail(string $what): never
{
    fwrite(STDERR, "$what: this no longer matches, redo the edit by hand.\n");
    exit(1);
}

/** Replace text that must appear exactly once. */
function replaceOnce(string $source, string $from, string $to, string $what): string
{
    if (substr_count($source, $from) !== 1) {
        fail($what);
    }

    return str_replace($from, $to, $source);
}

/**
 * Remove a static method with its doc comment, leaving a line that says where it went.
 * The body ends at the first line that is exactly the method's own closing brace.
 */
function removeMethod(string $source, string $visibility, string $name, string $leftBehind): string
{
    $signature = "    $visibility static function $name(";
    $at = strpos($source, $signature);

    if ($at === false || substr_count($source, $signature) !== 1) {
        fail("the method $name");
    }

    $docStart = strrpos(substr($source, 0, $at), "    /**\n");
    $end = strpos($source, "\n    }\n", $at);

    if ($docStart === false || $end === false) {
        fail("the method $name");
    }

    $after = $end + strlen("\n    }\n");

    // With nothing left behind, the blank line that followed the method goes with it.
    if ($leftBehind === '' && ($source[$after] ?? '') === "\n") {
        $after++;
    }

    return substr($source, 0, $docStart).$leftBehind.substr($source, $after);
}

$code = file_get_contents($source);

$code = replaceOnce($code, "        RegistersWatchers;\n", <<<'PHP'
        RegistersWatchers,
        \Spawn\Laravel\Telescope\CoroutineSafeRecording;

PHP, 'the trait list');

$code = replaceOnce($code, "        if (! static::runningWithinOctane(\$app) &&\n", <<<'PHP'
        // In async worker mode, recording is managed per-request by the server
        // (startRecording on request arrival, stopRecording after terminate).
        // At bootstrap there is no real HTTP request yet, so we must not call
        // handlingApprovedRequest() which would access $app['request'].
        if (static::$asyncMode) {
            return;
        }

        if (! static::runningWithinOctane($app) &&

PHP, 'start()');

$code = removeMethod($code, 'public', 'startRecording', '');
$code = removeMethod($code, 'public', 'stopRecording', '');
$code = removeMethod($code, 'public', 'withoutRecording', '');
$code = removeMethod($code, 'public', 'isRecording', '');
$code = removeMethod($code, 'protected', 'record', '');
$code = removeMethod($code, 'public', 'recordUpdate',
    "    // startRecording, stopRecording, withoutRecording, isRecording,\n"
    ."    // record, recordUpdate — provided by CoroutineSafeRecording trait\n");
$code = removeMethod($code, 'public', 'flushEntries', "    // flushEntries — provided by CoroutineSafeRecording trait\n");
$code = removeMethod($code, 'public', 'store', "    // store — provided by CoroutineSafeRecording trait\n");

// The copy lives outside vendor/, so the dist assets are found from Telescope's own package.
if (substr_count($code, "__DIR__.'/../dist/") < 1) {
    fail('the dist paths');
}

$code = str_replace("__DIR__.'/../dist/", "static::distPath().'/", $code);

$lastBrace = strrpos($code, "\n}");

if ($lastBrace === false) {
    fail('the end of the class');
}

$code = substr($code, 0, $lastBrace).<<<'PHP'


    /**
     * Path to the Telescope package root (cached to avoid repeated reflection).
     */
    private static ?string $packagePath = null;

    private static function distPath(): string
    {
        return (static::$packagePath ??= dirname((new \ReflectionClass(EntryType::class))->getFileName(), 2)) . '/dist';
    }
}

PHP;

$target = $root.'/overrides/telescope-5/'.$release.'/Telescope.php';

if (! is_dir(dirname($target))) {
    mkdir(dirname($target), 0777, true);
}

file_put_contents($target, $code);

echo "Written $target; add to TelescopeOverride::VARIANTS:\n    '$checksum' => '$release',\n";
