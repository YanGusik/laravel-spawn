<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Spawn\Laravel\Http\Middleware\AtomicThrottleRequests;
use Spawn\Laravel\Tests\Fixtures\RoundTripStore;

/**
 * A limit of N admits N, whatever arrives at once.
 *
 * Laravel's `throttle` reads the counter and raises it in two calls, so every request whose
 * read lands before the first write is admitted: under php-fpm that is bounded by the worker
 * count, and under one async worker by nothing but how many requests arrive together. The
 * middleware of this package charges first and decides on what `hit()` returns, which is one
 * atomic call and no window.
 *
 * The store here suspends on every call, the way Redis, Memcached and a database store do —
 * without that, requests never interleave inside the pair and the test would pass on the
 * unfixed middleware too.
 */
class AtomicThrottleTest extends AsyncTestCase
{
    public function test_a_burst_is_admitted_up_to_the_limit(): void
    {
        $middleware = new AtomicThrottleRequests(new RateLimiter(new Repository(new RoundTripStore())));

        $verdicts = $this->runParallel(array_combine(
            range(1, 20),
            array_fill(0, 20, fn () => $this->admits($middleware, 5)),
        ));

        $this->assertSame(5, count(array_filter($verdicts)), 'a limit of five admits five');
    }

    public function test_laravels_own_middleware_admits_the_whole_burst(): void
    {
        $middleware = new \Illuminate\Routing\Middleware\ThrottleRequests(
            new RateLimiter(new Repository(new RoundTripStore()))
        );

        $verdicts = $this->runParallel(array_combine(
            range(1, 20),
            array_fill(0, 20, fn () => $this->admits($middleware, 5)),
        ));

        $this->assertSame(20, count(array_filter($verdicts)),
            'the control: without the fix the whole burst is admitted, so the test above tests something');
    }

    public function test_requests_arriving_one_after_another_still_see_the_limit(): void
    {
        $middleware = new AtomicThrottleRequests(new RateLimiter(new Repository(new RoundTripStore())));

        $verdicts = [];

        foreach (range(1, 4) as $i) {
            $verdicts[] = $this->inRequest(fn () => $this->admits($middleware, 2));
        }

        $this->assertSame([true, true, false, false], $verdicts);
    }

    /**
     * The headers a passing request carries are upstream's, counted after the charge in both.
     */
    public function test_the_headers_of_an_admitted_request_are_unchanged(): void
    {
        $middleware = new AtomicThrottleRequests(new RateLimiter(new Repository(new RoundTripStore())));

        $response = $this->inRequest(fn () => $middleware->handle(
            $this->request(),
            fn () => new Response('ok'),
            5,
            1,
        ));

        $this->assertSame('5', $response->headers->get('X-RateLimit-Limit'));
        $this->assertSame('4', $response->headers->get('X-RateLimit-Remaining'));
    }

    private function admits(object $middleware, int $maxAttempts): bool
    {
        try {
            $middleware->handle($this->request(), fn () => new Response('ok'), $maxAttempts, 1);

            return true;
        } catch (ThrottleRequestsException) {
            return false;
        }
    }

    /**
     * A request the signature resolver can name: it asks the route and the client address,
     * and every request here is the same client on the same route on purpose.
     */
    private function request(): Request
    {
        $request = Request::create('/probe', 'GET', server: ['REMOTE_ADDR' => '203.0.113.7']);
        $request->setRouteResolver(fn () => new Route('GET', '/probe', []));

        return $request;
    }
}
