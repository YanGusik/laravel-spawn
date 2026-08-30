<?php

/**
 * Per-request log context is written into an object the whole worker shares.
 *
 * `LogManager` memoises one `Logger` per channel name and `Logger::$context` is an
 * ordinary property of it, so `Log::withContext(['request_id' => ...])` — the common
 * way a request tags its own lines — tags every other request's lines too. The
 * carrier is the `log` singleton `LogServiceProvider` registers, which outlives the
 * request the way every framework singleton does; the facade-root sharing that
 * explains the HTTP client factory has no part in it. `LogManager::$sharedContext`
 * (`LogManager.php:52`) is process state of the same kind and is not exercised here.
 *
 * The cause is an absent per-request reset, so it reproduces sequentially too; under
 * one-request-per-process it is invisible because the process ends with the request.
 * Each run gets a worker of its own, so no run reads what the previous one installed.
 *
 * Exits 0 if a request's log context stays inside it, 1 if it crosses, 2 if a control
 * failed. Found alongside YanGusik/laravel-spawn#65; not one of its three cases.
 */

use Illuminate\Log\LogManager;
use Illuminate\Log\LogServiceProvider;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Spawn\Laravel\Foundation\AsyncApplication;
use Spawn\Laravel\Tests\BootsAsyncApplication;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/harness.php';

/* A channel that keeps its records in memory, so a written line can be read back. */
const PROBE_CHANNEL = [
    'default'  => 'probe',
    'channels' => ['probe' => ['driver' => 'monolog', 'handler' => TestHandler::class]],
];

/**
 * The context Monolog recorded on the last line of the given manager's channel.
 *
 * @return array<string, mixed>
 */
function last_line_context(LogManager $log): array
{
    $handler = $log->channel('probe')->getLogger()->getHandlers()[0];
    $records = $handler->getRecords();

    return end($records)->context;
}

$boot = new class {
    use BootsAsyncApplication;

    public function worker(): AsyncApplication
    {
        $app = $this->bootedApp([LogServiceProvider::class]);
        $app->make('config')->set('logging', PROBE_CHANNEL);
        $app->enableAsyncMode();

        return $app;
    }
};

$run = new ProofRun();

/* Request A tags its own lines, the way request-id middleware does. */
$taggingRequest = static function (AsyncApplication $app) {
    $app->make('log')->withContext(['request_id' => 'A']);
    $app->make('log')->info('a');

    return last_line_context($app->make('log'));
};

/* Request B logs one line and expects it to carry nothing it did not put there. */
$plainRequest = static function (AsyncApplication $app) {
    $app->make('log')->info('b');

    return last_line_context($app->make('log'));
};

$app = $boot->worker();
$sequential = proof_run_sequentially([
    'a' => static fn () => $taggingRequest($app),
    'b' => static fn () => $plainRequest($app),
]);
$run->control('A tagged its own line, sequential', $sequential['a'], ['request_id' => 'A']);
$run->isolation('B\'s line, sequential', $sequential['b'], []);

$app = $boot->worker();
$concurrent = proof_run_concurrently([
    'a' => static fn () => $taggingRequest($app),
    'b' => static fn () => $plainRequest($app),
]);
$run->control('A tagged its own line, concurrent', $concurrent['a'], ['request_id' => 'A']);
$run->isolation('B\'s line, concurrent', $concurrent['b'], []);

/* The control that names the cause and the remedy at once: drop the resolved manager
 * between the two requests, and B builds a Logger with a context of its own. */
$app = $boot->worker();
$withReset = proof_run_sequentially([
    'a' => static fn () => $taggingRequest($app),
    'b' => static function () use ($app, $plainRequest) {
        $app->forgetInstance('log');

        return $plainRequest($app);
    },
]);
$run->control('with the log binding reset between requests', $withReset['b'], []);

exit($run->exitCode());
