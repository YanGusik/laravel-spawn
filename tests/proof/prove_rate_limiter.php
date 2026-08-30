<?php

/**
 * A limit of one admits two, and the window belongs to Laravel rather than the adapter.
 *
 * `RateLimiter::tooManyAttempts()` reads the counter (`RateLimiter.php:203`) and
 * `hit()` raises it (`:161`), with nothing atomic spanning the two — the shape the
 * `throttle` middleware and `attempt()` are built on. Any pause between the read and
 * the write lets a second caller read the same pre-increment value.
 *
 * Ownership, by reading rather than by experiment: no store closes that window,
 * because it lies between two separate calls into the store. Two FPM workers sharing
 * one Redis overshoot exactly as two coroutines do; what concurrency sets is how many
 * callers get their read in before the first write, not whether the window exists.
 * The third run below is the runtime reading behind that claim — `tooManyAttempts()`
 * has no side effect, so two consecutive calls both answer "go ahead" — and it is a
 * control, not evidence about processes.
 *
 * Store-dependent by construction: the overshoot needs the coroutine to suspend
 * between the read and the write, which Redis, Memcached and a database store do and
 * the `array` and `apcu` stores do not.
 *
 * This one stays red on purpose: the defect is Laravel's and lives in `RateLimiter`, which
 * this package does not replace. What it does replace is the `throttle` middleware — see
 * `Spawn\Laravel\Http\Middleware\AtomicThrottleRequests` and the charge-first control
 * below, which is the shape that holds.
 *
 * Exits 0 if a limit of one admits one, 1 if it admits more, 2 if a control failed.
 * Reported as YanGusik/laravel-spawn#65, case 1.
 */

use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Spawn\Laravel\Tests\Fixtures\RoundTripStore;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/harness.php';

const LIMIT = 1;
const KEY = 'login|203.0.113.7';

function fresh_limiter(): RateLimiter
{
    return new RateLimiter(new Repository(new RoundTripStore()));
}

/** The `throttle` middleware's own pair of calls, as one request makes them. */
function admits(RateLimiter $limiter): bool
{
    if ($limiter->tooManyAttempts(KEY, LIMIT)) {
        return false;
    }

    $limiter->hit(KEY, 60);

    return true;
}

$run = new ProofRun();

$limiter = fresh_limiter();
$sequential = proof_run_sequentially([
    'a' => static fn () => admits($limiter),
    'b' => static fn () => admits($limiter),
]);
$run->control('limit of 1, sequential', $sequential, ['a' => true, 'b' => false]);

$limiter = fresh_limiter();
$concurrent = proof_run_concurrently([
    'a' => static fn () => admits($limiter),
    'b' => static fn () => admits($limiter),
]);
$run->control(
    'both concurrent requests reached a verdict',
    array_map(static fn ($admitted) => is_bool($admitted), $concurrent),
    ['a' => true, 'b' => true]
);
$run->defect('limit of 1, concurrent', $concurrent, ['a' => true, 'b' => false]);

/* The shape this package's middleware uses: charge first, decide on what hit() returns.
 * One atomic call, so there is nothing to interleave. */
$limiter = fresh_limiter();
$chargeFirst = proof_run_concurrently([
    'a' => static fn () => $limiter->hit(KEY, 60) <= LIMIT,
    'b' => static fn () => $limiter->hit(KEY, 60) <= LIMIT,
]);
$run->control('charge-first, concurrent', $chargeFirst, ['a' => true, 'b' => false]);

/* Both readings happen before either write, which is what a second caller sees on any
 * store: the answer to "may I?" is not itself a claim on the budget. */
$limiter = fresh_limiter();
$readA = ! $limiter->tooManyAttempts(KEY, LIMIT);
$readB = ! $limiter->tooManyAttempts(KEY, LIMIT);
$run->control('the check has no side effect', [$readA, $readB], [true, true]);

exit($run->exitCode());
