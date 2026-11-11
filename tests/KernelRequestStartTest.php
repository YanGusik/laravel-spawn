<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;

use function Async\delay;

/**
 * The HTTP kernel is one object per worker, and Laravel keeps the start time of the request
 * it is handling in it: an overlapping request must not overwrite or clear another's.
 */
class KernelRequestStartTest extends AsyncTestCase
{
    use BootsHttpApplication;

    /**
     * Request A starts first and ends last; B starts inside A and ends before it.
     *
     * @return array{fired: list<array{0: string, 1: float}>, seenInA: float|null, started: array<string, float>}
     */
    private function overlap(string $kernel): array
    {
        $app = $this->httpApp($kernel);
        $fired = [];
        $started = [];
        $seenInA = null;

        $app->make(KernelContract::class)->whenRequestLifecycleIsLongerThan(-1, function ($startedAt, $request) use (&$fired) {
            $fired[] = [$request->path(), (float) $startedAt->format('U.u')];
        });

        $router = $app->make('router');
        $router->get('/a', function () use ($app, &$started, &$seenInA) {
            $started['a'] = (float) $app->make(KernelContract::class)->requestStartedAt()?->format('U.u');
            delay(80);
            $seenInA = $app->make(KernelContract::class)->requestStartedAt()?->format('U.u');

            return 'a';
        });
        $router->get('/b', function () use ($app, &$started) {
            $started['b'] = (float) $app->make(KernelContract::class)->requestStartedAt()?->format('U.u');

            return 'b';
        });

        $app->enableAsyncMode();

        $this->runParallel([
            'a' => fn () => $this->get($app, '/a'),
            'b' => function () use ($app) {
                delay(20);

                return $this->get($app, '/b');
            },
        ]);

        return ['fired' => $fired, 'seenInA' => $seenInA === null ? null : (float) $seenInA, 'started' => $started];
    }

    public function test_each_overlapping_request_keeps_its_own_start_time(): void
    {
        ['fired' => $fired, 'seenInA' => $seenInA, 'started' => $started] = $this->overlap(HttpKernel::class);

        $this->assertSame(['b', 'a'], array_column($fired, 0), 'the lifecycle handler fires once per request');
        $this->assertLessThan($started['b'], $started['a']);
        $this->assertSame($started['a'], $seenInA, "A still reads its own start after B ended");

        $byPath = array_column($fired, 1, 0);
        $this->assertSame($started['a'], $byPath['a']);
        $this->assertSame($started['b'], $byPath['b']);
    }

    public function test_a_kernel_of_the_applications_own_with_the_trait_keeps_them_apart_too(): void
    {
        ['fired' => $fired, 'seenInA' => $seenInA, 'started' => $started] = $this->overlap(TraitKernel::class);

        $this->assertSame(['b', 'a'], array_column($fired, 0));
        $this->assertSame($started['a'], $seenInA);
    }

    public function test_the_stock_kernel_is_replaced_and_any_other_left_as_it_is(): void
    {
        $this->assertInstanceOf(\Spawn\Laravel\Http\Kernel\AsyncHttpKernel::class,
            $this->httpApp(HttpKernel::class)->make(KernelContract::class));
        $this->assertInstanceOf(PlainKernel::class, $this->httpApp(PlainKernel::class)->make(KernelContract::class));
    }

    /**
     * Every property of the stock kernel is either the request's or the worker's: a Laravel
     * release that adds one makes this fail until it is placed.
     */
    public function test_every_property_of_the_stock_kernel_is_placed(): void
    {
        $perRequest = ['requestStartedAt'];
        $perWorker = ['app', 'router', 'bootstrappers', 'middleware', 'middlewareGroups', 'middlewareAliases',
            'middlewarePriority', 'requestLifecycleDurationHandlers', 'routeMiddleware'];

        $properties = array_map(
            static fn (\ReflectionProperty $property) => $property->getName(),
            (new \ReflectionClass(HttpKernel::class))->getProperties()
        );

        sort($properties);
        $placed = array_merge($perRequest, $perWorker);
        sort($placed);

        $this->assertSame($placed, $properties);
    }
}

class TraitKernel extends HttpKernel
{
    use \Spawn\Laravel\Http\Kernel\PerRequestStartTime;

    protected $middleware = [];
}
