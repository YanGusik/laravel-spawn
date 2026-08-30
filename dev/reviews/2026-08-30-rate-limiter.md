# Whether the rate limiter overshoot is this package's to fix

**Date:** 2026-08-30 · **Subject:** `tests/proof/prove_rate_limiter.php`, the decision behind commit `3916c9a`

The defect is Laravel's: `RateLimiter::tooManyAttempts()` reads the counter
(`Cache/RateLimiter.php:203`) and `hit()` raises it (`:161`), `ThrottleRequests` calls them in
that order (`Routing/Middleware/ThrottleRequests.php:157`, `:164`), and no cache store closes a
window that lies between two calls into the store. The question was whether that makes it
somebody else's problem. It does not, and the measurement is why.

## What the fan-out says

`throttle:5,1`, a store costing one round trip of a millisecond per call, coroutines in one
process (`dev/measurements/fanout_rate_limiter.php`):

| arrival | calls | admitted |
|---|---|---|
| one burst | 10 | 10 |
| one burst | 500 | 500 |
| 1 ms apart | 100 | 8 |
| 2 ms apart | 100 | 7 |

A burst is admitted in full: the number let through is the number whose read landed before the
first write. Under php-fpm that number is bounded by the worker count and by whatever sits in
front of the application; under one async worker it is bounded by how many requests arrive
together, which one client decides. The same formula, a ceiling two to three orders of
magnitude higher — so the package ships the amplifier, and "Laravel's defect" is only half the
sentence.

In threat terms: a six-digit code behind a limit of five a minute takes about 3300 hours to
walk at the limit and about 33 at five hundred a minute.

## The options, and what each costs

| Shape | Closes the window? | Cost |
|---|---|---|
| Subclass `RateLimiter` | No | The window lives in the middleware and in `attempt()`, not inside the limiter |
| Middleware charging first | Yes | The rejected request pays for its attempt; with several limits on a route, earlier ones are charged before a later one rejects |
| `Cache::lock()` around the pair | Yes | A lock per request; on one worker that is a serialisation point, trading a race for a queue |
| Lua / atomic verdict | Yes | This is what `ThrottleRequestsWithRedis` almost does — see below |

The middleware was shipped as `AtomicThrottleRequests`, behind `async.atomic_throttle`, which
defaults to on. `hit()` returns the count after raising it and is atomic in itself — `add()` for
the timer, then the store's `increment()` — so one call replaces the pair. The decay window is
not extended by charging a rejected request, because the timer is written with `add()`, and the
headers are identical: `buildException()` reports a remaining of 0 either way.

## What is left open

A limit declared with an `afterCallback` is charged after the response by design, so its
pre-check is the only guard it has.

`ThrottleRequestsWithRedis` narrows the window to one round trip and keeps it: its
`tooManyAttempts()` runs a read-only Lua script and its `hit()` calls `acquire()` and discards
the atomic verdict that call returns (`ThrottleRequestsWithRedis.php:99,120`). Read from the
source, not measured here. `ASYNC_KNOWN_ISSUES.md` item 13 tells an operator what to do about
both today.

The report owed to Laravel is drafted at `dev/upstream/laravel-ratelimiter-report.md`.
