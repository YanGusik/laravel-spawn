<?php

/**
 * How far past a limit of 5 the middleware pair goes as concurrency grows.
 *
 * Same store and same call pair as tests/proof/prove_rate_limiter.php; the only new
 * variable is the number of concurrent callers, and optionally a stagger between
 * their arrivals to model requests that do not land in one instant.
 */

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Async\Scope;

use function Async\delay;

require '/home/edmond/laravel-spawn/vendor/autoload.php';

const LIMIT = 5;
const KEY = 'login|203.0.113.7';

class RoundTripStore extends ArrayStore
{
    private const ROUND_TRIP_MS = 1;

    private bool $inFlight = false;

    public function get($key): mixed
    {
        return $this->afterRoundTrip(fn () => parent::get($key));
    }

    public function put($key, $value, $seconds): bool
    {
        return $this->afterRoundTrip(fn () => parent::put($key, $value, $seconds));
    }

    public function increment($key, $value = 1): int|bool
    {
        return $this->afterRoundTrip(fn () => parent::increment($key, $value));
    }

    public function add($key, $value, $seconds): bool
    {
        return $this->afterRoundTrip(function () use ($key, $value, $seconds) {
            if (parent::get($key) !== null) {
                return false;
            }

            return parent::put($key, $value, $seconds);
        });
    }

    private function afterRoundTrip(callable $operation): mixed
    {
        if ($this->inFlight) {
            return $operation();
        }

        delay(self::ROUND_TRIP_MS);

        $this->inFlight = true;

        try {
            return $operation();
        } finally {
            $this->inFlight = false;
        }
    }
}

/** The throttle middleware's pair, as one request makes it. */
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
