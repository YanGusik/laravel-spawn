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

    // ── instance() on a transient service inside a request ───────────────────

    /**
     * Under php-fpm instance() lasts the rest of the request; a transient service replaced inside one request
     * must not become every request's. laravel/mcp's ReadResource does this with its Request.
     */
    public function test_a_transient_service_replaced_in_a_request_stays_in_that_request(): void
    {
        $app = $this->container();
        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => function () use ($app) {
                $override = new Payload();
                $app->instance(Payload::class, $override);
                delay(30);
                $same = $app->make(Payload::class) === $override;
                $app->forgetInstance(Payload::class);

                return [$same, $app->make(Payload::class) === $override];
            },
            'b' => function () use ($app) {
                delay(10);

                return [$app->bound(Payload::class), $app->make(Payload::class) === $app->make(Payload::class)];
            },
        ]);

        $this->assertSame([true, false], $results['a']);
        $this->assertSame([false, false], $results['b'], "request b got request a's override or one shared object");

        $later = $this->runParallel(['c' => fn () => $app->make(Payload::class) !== $app->make(Payload::class)]);
        $this->assertTrue($later['c'], 'the service stays transient');
    }

    public function test_a_transient_service_made_twice_in_a_request_runs_its_resolving_callbacks_twice(): void
    {
        $app = $this->container();
        $step = 0;
        $app->resolving(Payload::class, function (Payload $payload) use (&$step) {
            $payload->step = ++$step;
        });
        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => function () use ($app) {
                $app->instance(Payload::class, new Payload());
                $app->forgetInstance(Payload::class);

                return [$app->make(Payload::class)->step, $app->make(Payload::class)->step];
            },
        ]);

        $this->assertSame([1, 2], $results['a']);
    }

    /** A later singleton() makes the service shared: a replacement then is the worker's, and so is its removal. */
    public function test_forgetting_a_service_that_became_shared_drops_the_workers_replacement(): void
    {
        $app = $this->container();
        $app->enableAsyncMode();

        $this->runParallel(['a' => fn () => $app->instance(Payload::class, new Payload())]);
        $app->singleton(Payload::class);

        $override = new Payload();
        $this->runParallel([
            'b' => function () use ($app, $override) {
                $app->instance(Payload::class, $override);
                $app->forgetInstance(Payload::class);
            },
        ]);

        $results = $this->runParallel(['c' => fn () => $app->make(Payload::class) === $override]);
        $this->assertFalse($results['c']);
    }

    public function test_a_scoped_attribute_class_replaced_before_its_first_resolve_stays_in_the_request(): void
    {
        $app = $this->container();
        $app->enableAsyncMode();

        $override = new ScopedPayload();
        $this->runParallel(['a' => fn () => $app->instance(ScopedPayload::class, $override)]);
        $results = $this->runParallel(['b' => fn () => $app->make(ScopedPayload::class) === $override]);

        $this->assertFalse($results['b']);
    }

    // ── scopedSingleton() with no binding, outside async mode ────────────────

    public function test_a_scoped_singleton_without_a_binding_resolves_outside_async_mode(): void
    {
        $app = $this->container();
        $app->scopedSingleton('tenant.state', fn () => new Payload());

        $this->assertTrue($app->bound('tenant.state'));
        $this->assertSame($app->make('tenant.state'), $app->make('tenant.state'));
    }

    /** A queue worker calls forgetScopedInstances() between jobs; the next job builds its own. */
    public function test_forgetting_scoped_instances_rebuilds_a_scoped_singleton(): void
    {
        $app = $this->container();
        $app->scopedSingleton('tenant.state', fn () => new Payload());

        $first = $app->make('tenant.state');
        $app->forgetScopedInstances();

        $this->assertNotSame($first, $app->make('tenant.state'));
    }

    public function test_a_scoped_singleton_answering_null_is_not_kept(): void
    {
        $app = $this->container();
        $current = null;
        $app->scopedSingleton('current.thing', function () use (&$current) {
            return $current;
        });

        $current = new Payload();
        $first = $app->make('current.thing');
        $app->forgetScopedInstances();
        $current = new Payload();

        $this->assertNotSame($first, $app->make('current.thing'));
    }

    public function test_in_async_mode_a_scoped_singleton_is_per_request(): void
    {
        $app = $this->container();
        $app->scopedSingleton('tenant.state', fn () => new Payload());
        $boot = $app->make('tenant.state');
        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => fn () => $app->make('tenant.state'),
            'b' => fn () => $app->make('tenant.state'),
        ]);

        $this->assertNotSame($results['a'], $results['b']);
        $this->assertNotSame($boot, $results['a']);
    }
}

final class Payload
{
    public int $step = 0;
}

#[\Illuminate\Container\Attributes\Scoped]
final class ScopedPayload
{
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
