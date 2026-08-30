# Draft report for laravel/framework — not sent

**Title:** `RateLimiter` admits more than the limit: the admit decision reads a counter
that a separate call increments

**Laravel version:** v13.26.1 (the shape is as old as the method pair).

### What happens

`ThrottleRequests::handleRequest()` asks `tooManyAttempts()` (Routing/Middleware/ThrottleRequests.php:157)
and raises the counter with `hit()` (:164). `RateLimiter::attempt()` (Cache/RateLimiter.php:106)
makes the same pair of calls with the caller's closure between them. Every caller whose read
lands before the first write reads the same pre-increment value and is admitted, so a limit of N
admits N + k when k callers race.

No cache store closes the window, because it lies between two calls into the store: Redis `INCR`
is atomic, and it is issued after every racing caller has already read.

`ThrottleRequestsWithRedis` narrows the window to one round trip but keeps the shape: its
`tooManyAttempts()` runs a read-only Lua script (ThrottleRequestsWithRedis.php:99), its `hit()`
calls `DurationLimiter::acquire()` and discards the returned verdict (:120). Two callers that
read `remaining = 1` in the same round trip are both admitted, and the atomic `HINCRBY` inside
`acquire()` then records both. So "use the Redis middleware" reduces the overshoot and does not
remove it. (This paragraph is a code reading; the measurements below are of the base middleware.)

### Measurements

Standalone script attached: the middleware's call pair against a store stub that costs one 1 ms
round trip per call, callers as coroutines in one process. Sequentially a limit of 1 admits 1.
Concurrently, limit 5:

| arrival pattern     | callers | admitted |
|---------------------|---------|----------|
| one burst           | 10      | 10       |
| one burst           | 500     | 500      |
| 1 ms between callers| 100     | 8        |
| 2 ms between callers| 100     | 7        |

The overshoot equals the number of callers whose read precedes the first write. Under FPM that
number is capped by the worker count; under any runtime that serves many requests per process —
Octane, coroutine servers — it is capped only by in-flight concurrency, and one client can supply
a 500-request burst. For a 6-digit OTP endpoint behind `throttle:5,1`, a burst of 500 concurrent
requests per minute turns the expected 10^6/5 minutes of brute force into roughly 33 hours.

### The shape of a fix

`hit()` returns the post-increment count and the increment itself is atomic on every store, so
the middleware can charge first and decide from the value it was given:

```php
$hits = $this->limiter->hit($limit->key, $limit->decaySeconds);

if ($hits > $limit->maxAttempts) {
    throw $this->buildException(...);
}
```

Three consequences, named rather than hidden:

- The counter grows on rejected requests. `X-RateLimit-Remaining` on a rejected response is 0
  today and stays 0; the change is visible only to code that reads `remaining()` outside the
  middleware while requests are being rejected. The decay timer is set with `add()`, so rejected
  hits do not extend the window.
- With several limits per route, an earlier limit is charged before a later one rejects. Today's
  check-all-then-hit-all order charges nothing in that case. Either `decrement()` the charged
  keys before throwing (best effort, and it errs toward stricter limiting) or accept the charge.
- `attempt()` increment-first counts a callback that throws, which today is not counted.

For `ThrottleRequestsWithRedis` the fix is already in the tree: `DurationLimiter::acquire()`
returns the atomic verdict, and using it in place of the separate `tooManyAttempts()` pre-check
closes the window in one round trip.

The `afterCallback` path (limits that count depending on the response) cannot charge first by
definition and keeps the window; that is the documented cost of counting after the fact.
