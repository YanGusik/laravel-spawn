<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Spawn\Laravel\Foundation\AsyncApplication;

use function Async\delay;
use function Async\spawn;

/**
 * What the container answers about a request's own objects: resolved(), the 'request' binding, a facade reached
 * through another name of a per-request service.
 */
class ContainerRequestStateTest extends AsyncTestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    private function container(): AsyncApplication
    {
        $app = new AsyncApplication(sys_get_temp_dir());
        $app->instance('config', new Repository(['async' => ['scoped_services' => []]]));

        return $app;
    }

    // ── resolved() ───────────────────────────────────────────────────────────

    public function test_a_per_request_service_resolved_at_boot_is_not_resolved_in_a_fresh_request(): void
    {
        $app = $this->container();
        $app->scoped(RequestCounter::class, fn () => new RequestCounter());
        $app->make(RequestCounter::class);
        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => function () use ($app) {
                $before = $app->resolved(RequestCounter::class);
                $app->make(RequestCounter::class);

                return [$before, $app->resolved(RequestCounter::class)];
            },
        ]);

        $this->assertSame([false, true], $results['a']);
    }

    public function test_a_per_request_service_made_in_a_request_is_resolved_there_alone(): void
    {
        $app = $this->container();
        $app->scoped(RequestCounter::class, fn () => new RequestCounter());
        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => function () use ($app) {
                $app->make(RequestCounter::class);
                delay(30);

                return $app->resolved(RequestCounter::class);
            },
            'b' => function () use ($app) {
                delay(10);

                return $app->resolved(RequestCounter::class);
            },
        ]);

        $this->assertSame(['a' => true, 'b' => false], $results);
    }

    public function test_outside_async_mode_resolved_is_laravels(): void
    {
        $app = $this->container();
        $app->scoped(RequestCounter::class, fn () => new RequestCounter());

        $before = $app->resolved(RequestCounter::class);
        $app->make(RequestCounter::class);

        $this->assertSame([false, true], [$before, $app->resolved(RequestCounter::class)]);
    }

    // ── instance('request') ──────────────────────────────────────────────────

    /**
     * The kernel replaces the request with instance(); a coroutine outside any request (a timer, a detached
     * scope's own task) must not be answered with it afterwards.
     */
    public function test_the_kernels_request_does_not_outlive_its_request(): void
    {
        $app = $this->container();
        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => function () use ($app) {
                $request = Request::create('https://tenant-a.test/secret', 'POST');
                $app->instance('request', $request);

                return $app->make('request') === $request;
            },
        ]);

        $this->assertTrue($results['a'], 'the request reads back its own request');
        $this->assertNotSame('tenant-a.test', $app->make('request')->getHost(), "the worker kept request a's object");
    }

    public function test_the_request_rebinding_still_runs(): void
    {
        $app = $this->container();
        $rebound = [];
        $app->rebinding('request', function ($app, $request) use (&$rebound) {
            $rebound[] = $request;
        });
        $app->enableAsyncMode();

        $request = Request::create('/');
        $this->runParallel(['a' => fn () => $app->instance('request', $request)]);

        $this->assertSame([$request], $rebound);
    }

    public function test_forgetting_the_request_inside_a_request_keeps_the_servers_one(): void
    {
        $app = $this->container();
        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => function () use ($app) {
                $request = Request::create('https://served.test/');
                \Spawn\Laravel\Foundation\RequestContext::current()->set(
                    \Spawn\Laravel\Foundation\ScopedService::REQUEST, $request
                );
                $app->forgetInstance('request');

                return $app->make('request') === $request;
            },
        ]);

        $this->assertTrue($results['a']);
    }

    // ── Facades through another name ─────────────────────────────────────────

    public function test_a_facade_on_another_name_of_a_per_request_service_resolves_per_request(): void
    {
        $app = $this->container();
        $app->scoped(RequestCounter::class, fn () => new RequestCounter());
        $app->alias(RequestCounter::class, 'request.counter');
        $app->alias('request.counter', 'counter');
        Facade::setFacadeApplication($app);

        RequestCounterFacade::getFacadeRoot();
        CounterFacade::getFacadeRoot();
        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => function () {
                RequestCounterFacade::getFacadeRoot()->actor = 'a';
                CounterFacade::getFacadeRoot()->actor = 'a';
                delay(30);

                return [RequestCounterFacade::getFacadeRoot()->actor, CounterFacade::getFacadeRoot()->actor];
            },
            'b' => function () {
                delay(10);
                RequestCounterFacade::getFacadeRoot()->actor = 'b';
                CounterFacade::getFacadeRoot()->actor = 'b';

                return [RequestCounterFacade::getFacadeRoot()->actor, CounterFacade::getFacadeRoot()->actor];
            },
        ]);

        $this->assertSame(['a' => ['a', 'a'], 'b' => ['b', 'b']], $results);
    }
}

final class RequestCounter
{
    public string $actor = 'boot';
}

final class RequestCounterFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'request.counter';
    }
}

final class CounterFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'counter';
    }
}
