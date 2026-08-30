<?php

namespace Spawn\Laravel\Tests\Fixtures;

use Illuminate\Cache\ArrayStore;

use function Async\delay;

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
