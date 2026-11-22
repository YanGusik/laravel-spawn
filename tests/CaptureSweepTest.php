<?php

namespace Spawn\Laravel\Tests;

use Closure;
use Illuminate\Config\Repository;
use Spawn\Laravel\Foundation\AsyncApplication;
use Spawn\Laravel\Foundation\CaptureSweep;
use Spawn\Laravel\Tests\Fixtures\CapturingMiddleware;
use Spawn\Laravel\Tests\Fixtures\SelfContainedMiddleware;

/**
 * What the sweep is for: a capture that only exists once a request has been served.
 *
 * Nothing per-request is resolved at worker start, so a singleton that takes a
 * per-request service is built during the first request that needs it and is invisible
 * before then. That is the whole reason `async:audit` drives requests instead of looking
 * at a freshly booted container.
 */
class CaptureSweepTest extends AsyncTestCase
{
    private function bootedApp(): AsyncApplication
    {
        $app = new AsyncApplication(sys_get_temp_dir());

        $app->instance('config', new Repository([
            'async' => ['scoped_services' => ['widgets']],
        ]));
        $app->singleton('widgets', fn () => new \stdClass());
        $app->enableAsyncMode();

        return $app;
    }

    /**
     * A request handler that does one thing: resolve what it was told to.
     *
     * @return Closure(mixed): void
     */
    private function resolving(AsyncApplication $app, string ...$aliases): Closure
    {
        return function () use ($app, $aliases): void {
            foreach ($aliases as $alias) {
                $app->make($alias);
            }
        };
    }

    public function test_a_singleton_built_during_a_request_is_reported(): void
    {
        $app = $this->bootedApp();

        /* The StartSession shape: a singleton keeping the per-request service. */
        $app->singleton('middleware', fn ($app) => new CapturingMiddleware($app->make('widgets')));

        $found = (new CaptureSweep($app, $this->resolving($app, 'middleware')))->over(['/orders']);

        $this->assertCount(1, $found);
        $this->assertSame(
            ['alias' => 'middleware', 'path' => 'captured', 'captured' => 'widgets', 'url' => '/orders'],
            reset($found),
        );
    }

    public function test_nothing_is_reported_when_the_singleton_keeps_its_own_collaborator(): void
    {
        $app = $this->bootedApp();

        $app->singleton('middleware', fn () => new SelfContainedMiddleware(new \stdClass()));

        $found = (new CaptureSweep($app, $this->resolving($app, 'middleware')))->over(['/orders']);

        $this->assertSame([], $found);
    }

    public function test_a_capture_found_twice_is_reported_once_under_the_first_url(): void
    {
        $app = $this->bootedApp();

        $app->singleton('middleware', fn ($app) => new CapturingMiddleware($app->make('widgets')));

        $found = (new CaptureSweep($app, $this->resolving($app, 'middleware')))->over(['/first', '/second']);

        $this->assertCount(1, $found);
        $this->assertSame('/first', reset($found)['url']);
    }

    public function test_the_swept_request_is_served_as_a_request(): void
    {
        $app = $this->bootedApp();
        $inRequest = null;

        // The adapters that keep fakes and log context per request take their in-request branch only here.
        (new CaptureSweep($app, function () use (&$inRequest): void {
            $inRequest = \Spawn\Laravel\Foundation\RequestContext::inRequest();
        }))->over(['/orders']);

        $this->assertTrue($inRequest);
    }

    public function test_the_worker_sees_nothing_before_a_request_is_driven(): void
    {
        $app = $this->bootedApp();

        $app->singleton('middleware', fn ($app) => new CapturingMiddleware($app->make('widgets')));

        $this->assertSame(
            [],
            $app->perRequestCaptures(),
            'the singleton is built lazily, so a booted worker has nothing to walk',
        );
    }
}
