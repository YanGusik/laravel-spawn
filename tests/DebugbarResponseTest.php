<?php

namespace Spawn\Laravel\Tests;

use Fruitcake\LaravelDebugbar\LaravelDebugbar;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spawn\Laravel\Debugbar\ResetDebugbar;
use Spawn\Laravel\Foundation\RequestContext;

use function Async\delay;

/**
 * The one Debugbar of a worker, driven the way the servers drive it: ResetDebugbar when the
 * request arrives, handleResponse() when its response leaves (#85, Issue 10).
 */
class DebugbarResponseTest extends AsyncTestCase
{
    use BootsAsyncApplication;

    public function test_a_request_that_turns_the_bar_off_leaves_it_on_for_another_and_each_render_carries_its_nonce(): void
    {
        $app = $this->bootedApp([
            \Illuminate\Routing\RoutingServiceProvider::class,
            \Illuminate\Cookie\CookieServiceProvider::class,
            \Fruitcake\LaravelDebugbar\ServiceProvider::class,
        ]);
        $app->instance('env', 'local');
        $app['config']->set('session', [
            'driver' => 'stub', 'path' => '/', 'domain' => null, 'secure' => false, 'same_site' => 'lax',
        ]);
        $app['config']->set('debugbar', require __DIR__.'/../vendor/barryvdh/laravel-debugbar/config/debugbar.php');
        $app['config']->set('debugbar.enabled', true);
        $app['config']->set('debugbar.force_allow_enable', true);
        $app->instance('request', Request::create('/'));
        // The provider adds the bar's routes at boot only when the bar can be enabled by then.
        $app['router']->get('_debugbar/assets', static fn () => '')->name('debugbar.assets');
        $app['router']->getRoutes()->refreshNameLookups();
        $debugbar = $app->make(LaravelDebugbar::class);
        $app->enableAsyncMode();

        $serve = static function (string $nonce, bool $turnOff, int $holdMs) use ($app, $debugbar): string {
            RequestContext::begin();
            $request = Request::create('/page');
            ResetDebugbar::handle($app, $request);
            $app->make(Vite::class)->useCspNonce($nonce);

            if ($turnOff) {
                $debugbar->disable();
            }

            delay($holdMs);

            return $debugbar->handleResponse($request, new Response('<html><body>page</body></html>'))->getContent();
        };

        $pages = $this->runParallel([
            'off' => fn () => $serve('nonce-off', true, 10),
            'first' => fn () => $serve('nonce-first', false, 40),
            'second' => fn () => $serve('nonce-second', false, 20),
        ]);

        $this->assertSame('<html><body>page</body></html>', $pages['off']);
        $this->assertStringContainsString('Laravel Debugbar Widget', $pages['first'], 'turned off by another request');
        $this->assertStringContainsString('nonce="nonce-first"', $pages['first']);
        $this->assertStringNotContainsString('nonce-second', $pages['first']);
        $this->assertStringContainsString('nonce="nonce-second"', $pages['second'], 'rendered with another request\'s nonce');
    }
}
