<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Spawn\Laravel\Routing\AsyncRouter;
use function Async\delay;

class RouterIsolationTest extends AsyncTestCase
{
    private function makeRouter(string $class = Router::class): Router
    {
        $app = $this->createApp();
        $dispatcher = new Dispatcher($app);

        $router = new $class($dispatcher, $app);

        $router->get('/slow', fn() => 'slow')->name('slow');
        $router->get('/fast', fn() => 'fast')->name('fast');

        return $router;
    }

    // ── Stock Router: prove the bug ──

    public function test_stock_router_current_route_leaks(): void
    {
        $router = $this->makeRouter();

        $results = $this->runParallel([
            'a' => function () use ($router) {
                $router->dispatch(Request::create('/slow'));
                delay(200);
                return $router->current()?->getName();
            },
            'b' => function () use ($router) {
                delay(50);
                $router->dispatch(Request::create('/fast'));
                return $router->current()?->getName();
            },
        ]);

        // A should see 'slow', but sees 'fast' because B overwrote $current
        $this->assertEquals('fast', $results['a'],
            'BUG: request A sees request B\'s route');
    }

    // ── AsyncRouter: prove the fix ──

    public function test_async_router_current_route_isolated(): void
    {
        $router = $this->makeRouter(AsyncRouter::class);
        $router->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($router) {
                $router->dispatch(Request::create('/slow'));
                delay(200);
                return $router->current()?->getName();
            },
            'b' => function () use ($router) {
                delay(50);
                $router->dispatch(Request::create('/fast'));
                return $router->current()?->getName();
            },
        ]);

        $this->assertEquals('slow', $results['a'], 'A must see its own route');
        $this->assertEquals('fast', $results['b'], 'B must see its own route');
    }

    public function test_async_router_current_request_isolated(): void
    {
        $router = $this->makeRouter(AsyncRouter::class);
        $router->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($router) {
                $router->dispatch(Request::create('/slow'));
                delay(200);
                return $router->getCurrentRequest()?->getPathInfo();
            },
            'b' => function () use ($router) {
                delay(50);
                $router->dispatch(Request::create('/fast'));
                return $router->getCurrentRequest()?->getPathInfo();
            },
        ]);

        $this->assertEquals('/slow', $results['a'], 'A must see its own request');
        $this->assertEquals('/fast', $results['b'], 'B must see its own request');
    }

    /**
     * A second dispatch in one request (an internal sub-request, an error page routed again)
     * replaces the current route and request, as the stock router replaces its properties.
     */
    public function test_a_second_dispatch_in_one_request_replaces_the_current_route(): void
    {
        $router = $this->makeRouter(AsyncRouter::class);
        $router->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($router) {
                $router->dispatch(Request::create('/slow'));
                $router->dispatch(Request::create('/fast'));

                return [$router->current()?->getName(), $router->getCurrentRequest()?->getPathInfo()];
            },
        ]);

        $this->assertSame(['fast', '/fast'], $results['a']);
    }
}
