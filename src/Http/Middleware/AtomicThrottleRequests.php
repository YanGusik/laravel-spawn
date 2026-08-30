<?php

namespace Spawn\Laravel\Http\Middleware;

use Closure;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * The `throttle` middleware, charging the counter before it decides instead of after.
 *
 * Upstream asks `tooManyAttempts()` and then calls `hit()`, and nothing is atomic across the
 * two. Every caller whose read lands before the first write is admitted, so the number let
 * through is the number that arrived inside that window. Under php-fpm that is bounded by the
 * worker count; one async worker serves as many coroutines as arrive, and a burst of 500
 * against `throttle:5,1` was admitted in full (`tests/proof/prove_rate_limiter.php` shows the
 * pair at a limit of one).
 *
 * `hit()` returns the count after raising it and is atomic in itself — `add()` for the timer,
 * then the store's own `increment()` — so charging first answers the question in one call and
 * leaves no window. The rejected request pays for its attempt, which upstream does not charge
 * it: the decay window is not extended by that (the timer is written with `add()`), and the
 * headers are identical, because `buildException()` reports a remaining of 0 either way.
 *
 * Two things stay as upstream has them. A limit with an `afterCallback` is charged after the
 * response, so its pre-check is all there is and its window remains. And on a route carrying
 * several limits, the earlier ones are charged before a later one rejects, where upstream
 * checks all of them before charging any.
 */
class AtomicThrottleRequests extends ThrottleRequests
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  array  $limits
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Illuminate\Http\Exceptions\ThrottleRequestsException
     */
    protected function handleRequest($request, Closure $next, array $limits)
    {
        foreach ($limits as $limit) {
            if ($limit->afterCallback) {
                if ($this->limiter->tooManyAttempts($limit->key, $limit->maxAttempts)) {
                    throw $this->buildException($request, $limit->key, $limit->maxAttempts, $limit->responseCallback);
                }

                continue;
            }

            if ($this->limiter->hit($limit->key, $limit->decaySeconds) > $limit->maxAttempts) {
                throw $this->buildException($request, $limit->key, $limit->maxAttempts, $limit->responseCallback);
            }
        }

        $response = $next($request);

        foreach ($limits as $limit) {
            if ($limit->afterCallback && ($limit->afterCallback)($response)) {
                $this->limiter->hit($limit->key, $limit->decaySeconds);
            }

            $response = $this->addHeaders(
                $response,
                $limit->maxAttempts,
                $this->calculateRemainingAttempts($limit->key, $limit->maxAttempts)
            );
        }

        return $response;
    }
}
