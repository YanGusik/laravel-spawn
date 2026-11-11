<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Config\Repository;
use Spawn\Laravel\Foundation\AsyncApplication;

use function Async\suspend;

/**
 * `$app->instance()` follows the lifetime of the alias it replaces: called inside a request
 * for a per-request alias, the object belongs to that request alone; called by the worker,
 * outside any request, it answers everybody.
 */
class InstanceDuringRequestTest extends AsyncTestCase
{
    private function container(): AsyncApplication
    {
        $app = new AsyncApplication(sys_get_temp_dir());
        $app->instance('config', new Repository(['async' => ['scoped_services' => ['tenant']]]));
        $app->bind('tenant', fn () => new Tenant('guest'));

        return $app;
    }

    public function test_a_later_request_does_not_get_the_object_an_earlier_one_put_in(): void
    {
        $app = $this->container();
        $app->enableAsyncMode();

        $this->runParallel(['a' => fn () => $app->instance('tenant', new Tenant('A'))]);
        $results = $this->runParallel(['b' => fn () => $app->make('tenant')->id]);

        $this->assertSame('guest', $results['b']);
    }

    public function test_a_concurrent_request_does_not_get_it_either(): void
    {
        $app = $this->container();
        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => function () use ($app) {
                $app->instance('tenant', new Tenant('A'));
                suspend();

                return $app->make('tenant')->id;
            },
            'b' => function () use ($app) {
                suspend();

                return $app->make('tenant')->id;
            },
        ]);

        $this->assertSame(['a' => 'A', 'b' => 'guest'], $results);
    }

    public function test_the_request_that_put_it_in_resolves_it_and_sees_it_bound(): void
    {
        $app = new AsyncApplication(sys_get_temp_dir());
        $app->instance('config', new Repository(['async' => ['scoped_services' => ['tenant']]]));
        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => function () use ($app) {
                $tenant = new Tenant('A');
                $app->instance('tenant', $tenant);

                return [$app->make('tenant') === $tenant, $app->bound('tenant'), $app->has('tenant')];
            },
            'b' => fn () => [$app->bound('tenant'), $app->has('tenant')],
        ]);

        $this->assertSame([true, true, true], $results['a']);
        $this->assertSame([false, false], $results['b']);
    }

    public function test_forget_instance_inside_a_request_drops_that_requests_object(): void
    {
        $app = $this->container();
        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => function () use ($app) {
                $app->instance('tenant', new Tenant('A'));
                $app->forgetInstance('tenant');

                return $app->make('tenant')->id;
            },
        ]);

        $this->assertSame('guest', $results['a']);
    }

    public function test_a_replacement_made_by_the_worker_still_answers_every_request(): void
    {
        $app = $this->container();
        $app->enableAsyncMode();

        $mock = new Tenant('mock');
        $app->instance('tenant', $mock);

        $results = $this->runParallel([
            'a' => fn () => $app->make('tenant'),
            'b' => fn () => $app->make('tenant'),
        ]);

        $this->assertSame($mock, $results['a']);
        $this->assertSame($mock, $results['b']);
    }

    public function test_an_object_a_request_put_in_outranks_the_workers_replacement(): void
    {
        $app = $this->container();
        $app->enableAsyncMode();
        $app->instance('tenant', new Tenant('mock'));

        $results = $this->runParallel([
            'a' => function () use ($app) {
                $app->instance('tenant', new Tenant('A'));

                return $app->make('tenant')->id;
            },
            'b' => fn () => $app->make('tenant')->id,
        ]);

        $this->assertSame(['a' => 'A', 'b' => 'mock'], $results);
    }
}

final class Tenant
{
    public function __construct(public string $id)
    {
    }
}
