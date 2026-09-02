<?php

/**
 * Concurrent writers to one file channel lose their records to Monolog's loop detection.
 *
 * `Monolog\Logger::addRecord()` stops a handler that logs through its own logger by counting
 * entry depth: per `Fiber` where there is one, and on the `Logger` object where
 * `Fiber::getCurrent()` is null, which it is inside a TrueAsync coroutine. `LogManager`
 * memoises one `Logger` per channel, so every coroutine of the worker raises that one count,
 * and the file write suspends the coroutine between the raise and the lower. At three
 * concurrent writers Monolog writes its loop warning and from five on it drops the record;
 * the early return past the warning also skips the lower, so the count never comes back down
 * and the channel writes nothing for the rest of the worker's life.
 *
 * The cause is concurrency, so the same operations run one request at a time lose nothing,
 * and that is the control. `tests/LogLoopGuardTest` pins the same count against a handler
 * that suspends on purpose; this script writes to a real file.
 *
 * Exits 0 if every record reached the file, 1 if the count fell short, 2 if a control failed.
 */

use Illuminate\Log\LogManager;
use Illuminate\Log\LogServiceProvider;
use Spawn\Laravel\Foundation\AsyncApplication;
use Spawn\Laravel\Tests\BootsAsyncApplication;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/harness.php';

const WRITERS = 8;
const RECORDS_PER_WRITER = 40;
const RECORDS = WRITERS * RECORDS_PER_WRITER;

$boot = new class {
    use BootsAsyncApplication;

    /**
     * A worker whose default channel is a `single` file, opened once for the worker as the
     * memoised channel opens it.
     */
    public function worker(string $path): AsyncApplication
    {
        $app = $this->bootedApp([LogServiceProvider::class]);
        $app->make('config')->set('logging', [
            'default'  => 'file',
            'channels' => ['file' => ['driver' => 'single', 'path' => $path]],
        ]);
        $app->enableAsyncMode();

        return $app;
    }
};

/**
 * @return array<int, callable>  one request per writer, each writing its share of records
 */
function writers(AsyncApplication $app): array
{
    $writers = [];

    for ($writer = 0; $writer < WRITERS; $writer++) {
        $writers[$writer] = static function () use ($app, $writer) {
            for ($record = 0; $record < RECORDS_PER_WRITER; $record++) {
                $app->make('log')->info("writer {$writer} record {$record}");
            }
        };
    }

    return $writers;
}

/**
 * How many lines of each level the file holds, keyed by the level name Monolog wrote.
 *
 * @return array{INFO: int, WARNING: int}
 */
function lines_by_level(string $path): array
{
    $counts = ['INFO' => 0, 'WARNING' => 0];

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        foreach (array_keys($counts) as $level) {
            if (str_contains($line, ".{$level}: ")) {
                $counts[$level]++;
            }
        }
    }

    unlink($path);

    return $counts;
}

function fresh_path(string $phase): string
{
    return sys_get_temp_dir() . "/prove-log-depth-{$phase}-" . getmypid() . '.log';
}

$run = new ProofRun();

$path = fresh_path('sequential');
proof_run_sequentially(writers($boot->worker($path)));
$sequential = lines_by_level($path);
$run->control('records on disk, sequential', $sequential['INFO'], RECORDS);
$run->control('loop warnings, sequential', $sequential['WARNING'], 0);

$path = fresh_path('concurrent');
proof_run_concurrently(writers($boot->worker($path)));
$concurrent = lines_by_level($path);
$run->defect('records on disk, concurrent', $concurrent['INFO'], RECORDS);
$run->defect('loop warnings, concurrent', $concurrent['WARNING'], 0);

/* The control that keeps this script honest: on Laravel's own LogManager, whose Monolog
 * counts the depth on the logger object, the concurrent run falls short. The day Monolog
 * counts per coroutine, the control fails and says so. */
$path = fresh_path('stock');
$app = $boot->worker($path);
$app->instance('log', new LogManager($app));
proof_run_concurrently(writers($app));
$onStock = lines_by_level($path);
$run->control('on Laravel\'s own LogManager records are lost', $onStock['INFO'] < RECORDS, true);

exit($run->exitCode());
