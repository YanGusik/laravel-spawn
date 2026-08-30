<?php

/**
 * The HTTP client factory is one object per worker, and everything set on it crosses
 * requests.
 *
 * Laravel registers no binding for `Illuminate\Http\Client\Factory`, so with facade
 * caching off the root would be rebuilt on every call and lose its global middleware,
 * its stray-request guard and its fakes. AsyncApplication::shareFacadeRoots() registers
 * it as a singleton for that reason. That is correct for state the worker owns, such as
 * global middleware, and incorrect for state a request installs, such as a stub; both
 * sit on the same object.
 *
 * The cause is an absent per-request reset rather than a race, so the crossing
 * reproduces sequentially too, and each run below therefore gets a worker of its own
 * rather than reading what the previous run left installed. The oracle is a positive marker — the body request
 * A stubbed, arriving in request B — so a factory that fails to build, or a network
 * that answers unexpectedly, reports no crossing rather than a false one.
 *
 * Exits 0 if a request is answered only by its own stubs, 1 if a stub crosses, 2 if a
 * control failed. Reported as YanGusik/laravel-spawn#65, case 3.
 */

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Spawn\Laravel\Foundation\AsyncApplication;
use Spawn\Laravel\Tests\BootsAsyncApplication;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/harness.php';

const STUBBED_BY_A = 'from-A';

$boot = new class {
    use BootsAsyncApplication;

    /** A worker in the state it is in when async mode is switched on: booted, no request served. */
    public function worker(): AsyncApplication
    {
        $app = $this->bootedApp([]);
        $app->enableAsyncMode();

        return $app;
    }
};

/* Request A fakes its own outbound calls, the way a sandbox mode or a feature flag does. */
$fakingRequest = static function () {
    Http::fake(['*' => Http::response(STUBBED_BY_A)]);

    return Http::get('https://example.invalid/a')->body();
};

/* Request B installs no stub of its own, and refuses to leave the process: with the stray
 * guard on, a request no stub answers throws at once instead of waiting out a DNS timeout.
 * Anything else that stops it — a factory that will not build — leaves the marker absent as
 * well, which reads as isolation held rather than as a crossing. */
$plainRequest = static function () {
    try {
        return Http::preventStrayRequests()->get('https://example.invalid/b')->body();
    } catch (\Throwable $e) {
        return 'no stub answered';
    }
};

$run = new ProofRun();

$boot->worker();
$sequential = proof_run_sequentially(['a' => $fakingRequest, 'b' => $plainRequest]);
$run->control('A is answered by its own stub, sequential', $sequential['a'], STUBBED_BY_A);
$run->isolationDiffers('what answered B, sequential', $sequential['b'], STUBBED_BY_A);

$boot->worker();
$concurrent = proof_run_concurrently(['a' => $fakingRequest, 'b' => $plainRequest]);
$run->control('A is answered by its own stub, concurrent', $concurrent['a'], STUBBED_BY_A);
$run->isolationDiffers('what answered B, concurrent', $concurrent['b'], STUBBED_BY_A);

/* Both roots are held until the comparison is made: PHP reuses the id of a destroyed
 * object, so ids compared after the objects are gone would say "same" of two that never
 * overlapped. */
$boot->worker();
$roots = proof_run_concurrently([
    'a' => static fn () => Http::getFacadeRoot(),
    'b' => static fn () => Http::getFacadeRoot(),
]);
$run->control('B resolved a factory at all', $roots['b'] instanceof Factory, true);
$run->isolation('A and B share one factory', $roots['a'] === $roots['b'], false);

/* The control that names the cause: with a factory each, the same fake stays home. */
$ownFactory = static function (string $tag) {
    $factory = new Factory();
    $factory->fake(['*' => $factory->response("from-$tag")]);

    return $factory->get("https://example.invalid/$tag")->body();
};

$isolated = proof_run_concurrently([
    'a' => static fn () => $ownFactory('a'),
    'b' => static fn () => $ownFactory('b'),
]);
$run->control('a factory each: B is answered by its own', $isolated['b'], 'from-b');

exit($run->exitCode());
