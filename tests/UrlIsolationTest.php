<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Config\Repository;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Routing\RoutingServiceProvider;
use Illuminate\Support\Facades\Facade;
use Spawn\Laravel\AsyncServiceProvider;
use Spawn\Laravel\Foundation\AsyncApplication;
use Spawn\Laravel\Foundation\ScopedService;

use function Async\current_context;

/**
 * The URL generator answers for the request that is asking.
 *
 * Upstream keeps one generator and moves it onto each new request through
 * rebinding('request'), which under concurrency means every request overwrites the
 * request, the cached root and the cached scheme the others are reading — so
 * url()->current() and redirect()->back() answer for somebody else's URL, and with
 * several hosts route() and asset() name another tenant's host.
 */
class UrlIsolationTest extends AsyncTestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    /**
     * @param  ?callable  $configure  run against the generator bootstrap leaves behind,
     *   which is where an application calls URL::forceScheme() and URL::defaults()
     */
    private function bootedApp(?callable $configure = null): AsyncApplication
    {
        $app = new AsyncApplication(sys_get_temp_dir());

        $app->instance('config', new Repository([
            'async' => ['scoped_services' => [], 'db_pool' => ['enabled' => false]],
            'app'   => ['asset_url' => null, 'key' => null],
        ]));

        $app->register(EventServiceProvider::class);
        $app->register(RoutingServiceProvider::class);
        $app->register(AsyncServiceProvider::class);

        /* The response factory takes the view factory in its constructor; nothing renders here. */
        $app->instance('view', $this->createStub(\Illuminate\Contracts\View\Factory::class));

        $app->boot();

        if ($configure !== null) {
            $configure($app->make('url'));
        }

        $app->enableAsyncMode();

        return $app;
    }

    private function urlSeenBy(AsyncApplication $app, string $uri): string
    {
        current_context()->set(ScopedService::REQUEST, Request::create($uri, 'GET'));

        return $app->make('url')->full();
    }

    /**
     * How many subscriptions the container is holding for an abstract.
     */
    private function reboundCallbacks(AsyncApplication $app, string $abstract): int
    {
        return count((fn () => $this->reboundCallbacks)->call($app)[$abstract] ?? []);
    }

    /** Published at boot: a request that published it would keep it to itself. */
    public function test_the_route_collection_is_published_for_every_request(): void
    {
        $app = $this->bootedApp();

        $results = $this->runParallel([
            'a' => fn () => $app->make('url') !== null,
            'b' => fn () => $app->bound('routes') && $app['routes'] === $app['router']->getRoutes(),
        ]);

        $this->assertTrue($results['b']);
    }

    public function test_each_request_reads_its_own_url(): void
    {
        $app = $this->bootedApp();

        $seen = $this->runParallel([
            'first'  => fn () => $this->urlSeenBy($app, 'https://first.example.com/orders?page=2'),
            'second' => fn () => $this->urlSeenBy($app, 'https://second.example.com/invoices'),
        ]);

        $this->assertSame('https://first.example.com/orders?page=2', $seen['first']);
        $this->assertSame('https://second.example.com/invoices', $seen['second']);
    }

    public function test_the_generator_is_not_the_one_the_other_request_is_holding(): void
    {
        $app = $this->bootedApp();

        $ids = $this->runParallel([
            'first' => function () use ($app) {
                $this->urlSeenBy($app, 'https://first.example.com/');

                return spl_object_id($app->make('url'));
            },
            'second' => function () use ($app) {
                $this->urlSeenBy($app, 'https://second.example.com/');

                return spl_object_id($app->make('url'));
            },
        ]);

        $this->assertNotSame($ids['first'], $ids['second']);
    }

    public function test_building_a_generator_per_request_leaves_no_subscription_behind(): void
    {
        $app = $this->bootedApp();

        /* Upstream's url extender calls rebinding('routes') every time it runs. */
        $before = $this->reboundCallbacks($app, 'routes');

        for ($request = 0; $request < 5; $request++) {
            $this->runParallel(['one' => fn () => $this->urlSeenBy($app, 'https://example.com/')]);
        }

        $this->assertSame(
            $before,
            $this->reboundCallbacks($app, 'routes'),
            'a per-request generator subscribing to the shared container grows it without bound',
        );
    }

    /**
     * A provider's boot() configures the generator through setters, and all of it is
     * instance state. Behind a TLS-terminating proxy a generator built from scratch emits
     * http:// for every route(), asset() and redirect() — silently, and on every page.
     */
    public function test_boot_time_configuration_reaches_every_request(): void
    {
        $app = $this->bootedApp(fn ($url) => $url->forceScheme('https'));

        $links = $this->runParallel([
            'first' => function () use ($app) {
                $this->urlSeenBy($app, 'http://first.example.com/orders');

                return $app->make('url')->to('/done');
            },
            'second' => function () use ($app) {
                $this->urlSeenBy($app, 'http://second.example.com/invoices');

                return $app->make('url')->to('/done');
            },
        ]);

        $this->assertSame('https://first.example.com/done', $links['first']);
        $this->assertSame('https://second.example.com/done', $links['second']);
    }

    public function test_the_response_factory_redirects_through_its_own_request(): void
    {
        $app = $this->bootedApp();

        $targets = $this->runParallel([
            'first' => function () use ($app) {
                $this->urlSeenBy($app, 'https://first.example.com/orders');

                return $app->make(\Illuminate\Contracts\Routing\ResponseFactory::class)
                    ->redirectTo('/done')->getTargetUrl();
            },
            'second' => function () use ($app) {
                $this->urlSeenBy($app, 'https://second.example.com/invoices');

                return $app->make(\Illuminate\Contracts\Routing\ResponseFactory::class)
                    ->redirectTo('/done')->getTargetUrl();
            },
        ]);

        $this->assertSame('https://first.example.com/done', $targets['first']);
        $this->assertSame('https://second.example.com/done', $targets['second']);
    }
}
