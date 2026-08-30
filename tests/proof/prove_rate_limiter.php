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
 * Exits 0 if a limit of one admits one, 1 if it admits more, 2 if a control failed.
 * Reported as YanGusik/laravel-spawn#65, case 1.
 */

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;

use function Async\delay;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/harness.php';

const LIMIT = 1;
const KEY = 'login|203.0.113.7';

/**
 * An in-memory store whose every call costs one round trip and nothing more.
 *
 * Redis, Memcached and a database store all suspend the coroutine while the socket is
 * in flight; an ArrayStore never does, and a reproducer built on one would report the
 * same verdict for a limiter that was safe. What the delay must not do is add
 * suspension points *inside* a single store call: `INCR` and `SET NX` are one command
 * each, so a stub that yielded between its own read and its own write would
 * manufacture a lost update that no real store has, and the counter — rather than
 * Laravel's check-then-act — would be what the run measured.
 */
class RoundTripStore extends ArrayStore
{
    private const ROUND_TRIP_MS = 1;

    /** True while a store call is past its round trip, so nested calls do not pay again. */
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

    /**
     * `SET NX`: stores the value only if the key is absent, and answers whether it did.
     *
     * ArrayStore has none, and without one Repository::add() falls back to a read and a
     * write of its own — two round trips with a suspension between them, which is the
     * artefact this stub exists to avoid.
     */
    public function add($key, $value, $seconds): bool
    {
        return $this->afterRoundTrip(function () use ($key, $value, $seconds) {
            if (parent::get($key) !== null) {
                return false;
            }

            return parent::put($key, $value, $seconds);
        });
    }

    /**
     * Pay the round trip, then run the operation with no suspension inside it.
     *
     * The delay comes first and the flag second on purpose: another coroutine can only
     * run while this one is suspended, and it is suspended only before the flag is set.
     */
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

/* Both readings happen before either write, which is what a second caller sees on any
 * store: the answer to "may I?" is not itself a claim on the budget. */
$limiter = fresh_limiter();
$readA = ! $limiter->tooManyAttempts(KEY, LIMIT);
$readB = ! $limiter->tooManyAttempts(KEY, LIMIT);
$run->control('the check has no side effect', [$readA, $readB], [true, true]);

exit($run->exitCode());
