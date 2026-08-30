<?php

/**
 * How far past a limit of five the throttle pair goes as concurrency grows.
 *
 * Same store and same call pair as prove_rate_limiter.php beside it; the only new variable is
 * the number of concurrent callers, and optionally a stagger between their arrivals, to model
 * requests that do not land in one instant.
 *
 * Prints a table rather than a verdict: it is where the numbers quoted in ASYNC_ADAPTATION.md
 * and in AtomicThrottleRequests come from, so that they can be re-taken instead of remembered.
 */

use Async\Scope;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Spawn\Laravel\Tests\Fixtures\RoundTripStore;

use function Async\delay;

require __DIR__ . '/../../vendor/autoload.php';

const LIMIT = 5;
const KEY = 'login|203.0.113.7';

/** The throttle middleware's pair of calls, as one request makes them. */
function admits(RateLimiter $limiter): bool
{
    if ($limiter->tooManyAttempts(KEY, LIMIT)) {
        return false;
    }

    $limiter->hit(KEY, 60);

    return true;
}

/**
 * @param int   $callers    concurrent requests
 * @param float $staggerMs  gap between arrivals, 0 for one burst
 */
function admitted_of(int $callers, float $staggerMs): int
{
    $limiter = new RateLimiter(new Repository(new RoundTripStore()));
    $admitted = 0;
    $scope = new Scope();

    for ($i = 0; $i < $callers; $i++) {
        $scope->spawn(static function () use ($i, $staggerMs, $limiter, &$admitted) {
            if ($staggerMs > 0) {
                delay((int) round($i * $staggerMs));
            }

            if (admits($limiter)) {
                $admitted++;
            }
        });
    }

    $scope->awaitCompletion(\Async\timeout(30000));

    return $admitted;
}

printf("limit %d, store round trip 1 ms\n\n", LIMIT);
printf("%-28s %-10s %s\n", 'arrival pattern', 'callers', 'admitted');

foreach ([10, 50, 100, 200, 500] as $n) {
    printf("%-28s %-10d %d\n", 'one burst', $n, admitted_of($n, 0));
}

foreach ([[50, 0.5], [100, 0.5], [100, 1.0], [100, 2.0]] as [$n, $gap]) {
    printf("%-28s %-10d %d\n", sprintf('staggered by %.1f ms', $gap), $n, admitted_of($n, $gap));
}
