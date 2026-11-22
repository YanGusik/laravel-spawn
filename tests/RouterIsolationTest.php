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

    public function test_a_second_dispatch_in_one_request_replaces_the_injected_route(): void
    {
        $app = new \Spawn\Laravel\Foundation\AsyncApplication(sys_get_temp_dir());
        $app->instance('config', new \Illuminate\Config\Repository(['async' => ['scoped_services' => []]]));
        $router = new AsyncRouter(new Dispatcher($app), $app);
        $router->get('/slow', fn () => $app->make(Route::class)->uri());
        $router->get('/fast', fn () => $app->make(Route::class)->uri());
        $router->bootCompleted();
        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => fn () => [
                $router->dispatch(Request::create('/slow'))->getContent(),
                $router->dispatch(Request::create('/fast'))->getContent(),
            ],
        ]);

        $this->assertSame(['slow', 'fast'], $results['a']);
    }

    /**
     * `Route::respondWithRoute()` is how an exception handler answers with a named route, a
     * custom 404 page among them; upstream reads the request from a property async mode leaves
     * empty.
     */
    public function test_respond_with_route_answers_inside_a_request(): void
    {
        $router = $this->makeRouter(AsyncRouter::class);
        $router->getRoutes()->refreshNameLookups();
        $router->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($router) {
                $router->dispatch(Request::create('/fast'));

                return $router->respondWithRoute('slow')->getContent();
            },
        ]);

        $this->assertSame('slow', $results['a']);
    }

    /**
     * The collection keeps one Route per definition, and binding writes the request's
     * parameters onto it: two requests answering with the same named route must each read
     * their own.
     */
    public function test_respond_with_route_keeps_each_request_parameters(): void
    {
        $router = $this->makeRouter(AsyncRouter::class);
        $router->get('/item/{id}', function () use ($router) {
            delay(50);

            return $router->getCurrentRequest()->route('id');
        })->name('item');
        $router->getRoutes()->refreshNameLookups();
        $router->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($router) {
                $router->dispatch(Request::create('/item/1'));

                return $router->respondWithRoute('item')->getContent();
            },
            'b' => function () use ($router) {
                delay(25);
                $router->dispatch(Request::create('/item/2'));

                return $router->respondWithRoute('item')->getContent();
            },
        ]);

        $this->assertSame(['a' => '1', 'b' => '2'], $results);
    }

    /**
     * An exception from a global middleware — maintenance mode, a body-size check — is
     * rendered before the router has dispatched anything, and a handler answering it with a
     * named route still has the request the container holds.
     */
    public function test_respond_with_route_answers_before_any_dispatch(): void
    {
        $app = new \Spawn\Laravel\Foundation\AsyncApplication(sys_get_temp_dir());
        $app->instance('config', new \Illuminate\Config\Repository(['async' => ['scoped_services' => []]]));
        $router = new AsyncRouter(new Dispatcher($app), $app);
        $router->get('/down', fn () => 'down')->name('down');
        $router->getRoutes()->refreshNameLookups();
        $router->bootCompleted();
        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => function () use ($app, $router) {
                $app->instance('request', Request::create('/anything'));

                return $router->respondWithRoute('down')->getContent();
            },
        ]);

        $this->assertSame('down', $results['a']);
    }

    /**
     * A 404 answered with a fallback route: the route a controller receives by type-hint, the
     * one Route::current() names and the one the request resolves must be the same.
     */
    public function test_respond_with_route_is_the_current_and_the_injected_route(): void
    {
        $app = new \Spawn\Laravel\Foundation\AsyncApplication(sys_get_temp_dir());
        $app->instance('config', new \Illuminate\Config\Repository(['async' => ['scoped_services' => []]]));
        $router = new AsyncRouter(new Dispatcher($app), $app);
        $router->get('/missing-page', fn () => implode(',', [
            $app->make(Route::class)->getName(),
            $router->current()?->getName(),
            $router->getCurrentRequest()->route()?->getName(),
        ]))->name('fallback');
        $router->getRoutes()->refreshNameLookups();
        $router->bootCompleted();
        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => function () use ($router) {
                try {
                    $router->dispatch(Request::create('/no-such-path'));
                } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                }

                return $router->respondWithRoute('fallback')->getContent();
            },
        ]);

        $this->assertSame('fallback,fallback,fallback', $results['a']);
    }
}
