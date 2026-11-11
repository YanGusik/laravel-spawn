<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Facade;
use Spawn\Laravel\Foundation\AsyncApplication;

use function Async\suspend;

/**
 * `Facade::swap()` writes the facade's cached root, which is one array for the whole worker,
 * and then calls `instance()`. Inside a request, for a scoped alias, both have to stay in
 * that request.
 */
class FacadeSwapDuringRequestTest extends AsyncTestCase
{
    private AsyncApplication $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new AsyncApplication(sys_get_temp_dir());
        $this->app->instance('config', new Repository(['async' => ['scoped_services' => ['swap.tenant']]]));
        $this->app->bind('swap.tenant', fn () => new SwapTenant('guest'));
        Facade::setFacadeApplication($this->app);
        Facade::clearResolvedInstances();
        $this->app->enableAsyncMode();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function test_a_later_request_does_not_get_the_object_an_earlier_one_swapped_in(): void
    {
        $this->runParallel(['a' => fn () => SwapTenantFacade::swap(new SwapTenant('A'))]);
        $results = $this->runParallel(['b' => fn () => [SwapTenantFacade::getFacadeRoot()->id, $this->app->make('swap.tenant')->id]]);

        $this->assertSame(['guest', 'guest'], $results['b']);
    }

    public function test_a_concurrent_request_does_not_get_it_and_the_swapping_one_does(): void
    {
        $results = $this->runParallel([
            'a' => function () {
                SwapTenantFacade::swap(new SwapTenant('A'));
                suspend();

                return SwapTenantFacade::getFacadeRoot()->id;
            },
            'b' => function () {
                suspend();

                return SwapTenantFacade::getFacadeRoot()->id;
            },
        ]);

        $this->assertSame(['a' => 'A', 'b' => 'guest'], $results);
    }

    public function test_a_swap_made_by_the_worker_still_answers_every_request(): void
    {
        $mock = new SwapTenant('mock');
        SwapTenantFacade::swap($mock);

        $results = $this->runParallel([
            'a' => fn () => SwapTenantFacade::getFacadeRoot(),
            'b' => fn () => SwapTenantFacade::getFacadeRoot(),
        ]);

        $this->assertSame($mock, $results['a']);
        $this->assertSame($mock, $results['b']);
    }
}

class SwapTenantFacade extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'swap.tenant';
    }
}

final class SwapTenant
{
    public function __construct(public string $id)
    {
    }
}
