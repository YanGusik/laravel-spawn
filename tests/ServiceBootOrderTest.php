<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\TestCase;
use Spawn\Laravel\AsyncServiceProvider;
use Spawn\Laravel\Config\AsyncConfig;
use Spawn\Laravel\Events\AsyncDispatcher;
use Spawn\Laravel\Foundation\AsyncApplication;
use Spawn\Laravel\Translation\AsyncTranslator;

/**
 * Verifies that AsyncServiceProvider correctly installs all adapted services
 * regardless of which providers boot before it.
 *
 * These tests catch the boot-order bug: if an adapter is registered in boot()
 * instead of register(), framework providers that boot earlier can lock in
 * the wrong (non-async) instance.
 *
 * Pattern for each test:
 *   1. Register a "EarlyProvider" that simulates what DatabaseServiceProvider /
 *      EventServiceProvider do — resolve and cache a service early in boot().
 *   2. Register AsyncServiceProvider after it.
 *   3. Boot the app.
 *   4. Assert the service is the async-safe class, not the stock one.
 *
 * A failing test means: "this adapter is not active in a real Laravel app
 * because it loses the race with a framework provider".
 */
class ServiceBootOrderTest extends TestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeApp(): AsyncApplication
    {
        $app = new AsyncApplication(sys_get_temp_dir());
        $app->enableAsyncMode();

        // AsyncServiceProvider::registerConfigAdapter() needs 'config' to exist.
        // In a real app it's bound by LoadConfiguration bootstrapper.
        $app->instance('config', new \Illuminate\Config\Repository([
            'async' => [
                'scoped_services' => [],
                'db_pool'         => ['enabled' => false, 'min' => 2, 'max' => 10],
            ],
            'app' => ['locale' => 'en', 'fallback_locale' => 'en'],
        ]));

        // TranslationServiceProvider needs 'files' (Filesystem)
        $app->instance('files', new \Illuminate\Filesystem\Filesystem());

        Facade::setFacadeApplication($app);
        Facade::clearResolvedInstances();
        return $app;
    }

    private function bootWith(AsyncApplication $app, array $providers): void
    {
        foreach ($providers as $provider) {
            $app->register($provider);
        }
        $app->boot();
    }

    // ── events ────────────────────────────────────────────────────────────────

    /**
     * Simulates a user's App\Providers\EventServiceProvider which calls
     * Event::listen() inside a booting() callback — this resolves 'events'
     * before any boot() method runs, caching the stock Dispatcher in the Facade.
     */
    public function test_events_facade_is_async_dispatcher_when_resolved_early(): void
    {
        $app = $this->makeApp();

        // Simulate App\Providers\EventServiceProvider: registers listeners
        // via booting() callback → resolves 'events' early via Facade
        $app->booting(function () {
            Event::listen('some.event', fn() => null); // forces early resolution
        });

        $this->bootWith($app, [
            \Illuminate\Events\EventServiceProvider::class,
            AsyncServiceProvider::class,
        ]);

        $this->assertInstanceOf(
            AsyncDispatcher::class,
            Event::getFacadeRoot(),
            'Event:: Facade must resolve AsyncDispatcher. ' .
            'If this fails — registerEventDispatcherAdapter() runs too late in boot(). ' .
            'Fix: move it to register().'
        );
    }

    /**
     * Simulates DatabaseServiceProvider::boot() which does:
     *   Model::setEventDispatcher($this->app['events'])
     * If this runs before AsyncServiceProvider, Model gets the stock Dispatcher.
     */
    public function test_model_event_dispatcher_is_async_when_db_provider_boots_first(): void
    {
        $app = $this->makeApp();

        // Simulates DatabaseServiceProvider::boot() — resolves 'events' and
        // stores it as a static on Model, before AsyncServiceProvider boots.
        $earlyProvider = new class($app) extends ServiceProvider {
            public function register(): void {}
            public function boot(): void
            {
                // This is exactly what DatabaseServiceProvider::boot() does
                Model::setEventDispatcher($this->app['events']);
            }
        };

        $this->bootWith($app, [
            \Illuminate\Events\EventServiceProvider::class,
            $earlyProvider,
            AsyncServiceProvider::class,
        ]);

        $this->assertInstanceOf(
            AsyncDispatcher::class,
            Model::getEventDispatcher(),
            'Model::$dispatcher must be AsyncDispatcher. ' .
            'If this fails — DatabaseServiceProvider::boot() captured the stock Dispatcher ' .
            'before AsyncServiceProvider could replace it. ' .
            'Fix: move registerEventDispatcherAdapter() to register(), or call ' .
            'Model::setEventDispatcher($app["events"]) again after boot.'
        );
    }

    /**
     * Verifies that app('events') from the container is AsyncDispatcher,
     * not the stock one registered by Illuminate\Events\EventServiceProvider.
     */
    public function test_container_events_is_async_dispatcher(): void
    {
        $app = $this->makeApp();

        $this->bootWith($app, [
            \Illuminate\Events\EventServiceProvider::class,
            AsyncServiceProvider::class,
        ]);

        $this->assertInstanceOf(
            AsyncDispatcher::class,
            $app->make('events'),
            'app("events") must be AsyncDispatcher after boot.'
        );
    }

    // ── config ────────────────────────────────────────────────────────────────

    public function test_config_is_async_config(): void
    {
        $app = $this->makeApp();

        $this->bootWith($app, [AsyncServiceProvider::class]);

        $this->assertInstanceOf(
            AsyncConfig::class,
            $app->make('config'),
            'app("config") must be AsyncConfig.'
        );
    }

    /**
     * Simulates a provider that reads config in register() — before AsyncServiceProvider.
     * AsyncConfig must still be installed even if config was accessed early.
     */
    public function test_config_is_async_config_even_when_accessed_before_registration(): void
    {
        $app = $this->makeApp();

        $earlyProvider = new class($app) extends ServiceProvider {
            public function register(): void
            {
                // Reads config early — common pattern in service providers
                $this->app->make('config')->get('app.name', 'default');
            }
            public function boot(): void {}
        };

        $this->bootWith($app, [
            $earlyProvider,
            AsyncServiceProvider::class,
        ]);

        $this->assertInstanceOf(
            AsyncConfig::class,
            $app->make('config'),
            'app("config") must be AsyncConfig even after early access.'
        );
    }

    // ── translator ────────────────────────────────────────────────────────────

    public function test_translator_is_async_translator(): void
    {
        $app = $this->makeApp();
        $app->useStoragePath(sys_get_temp_dir());
        $app->useLangPath(sys_get_temp_dir());

        $this->bootWith($app, [
            \Illuminate\Translation\TranslationServiceProvider::class,
            AsyncServiceProvider::class,
        ]);

        $this->assertInstanceOf(
            AsyncTranslator::class,
            $app->make('translator'),
            'app("translator") must be AsyncTranslator.'
        );
    }

    /**
     * Simulates a provider that resolves 'translator' before AsyncServiceProvider boots.
     */
    public function test_translator_is_async_when_resolved_early(): void
    {
        $app = $this->makeApp();
        $app->useStoragePath(sys_get_temp_dir());
        $app->useLangPath(sys_get_temp_dir());

        $earlyProvider = new class($app) extends ServiceProvider {
            public function register(): void {}
            public function boot(): void
            {
                // Some providers do $this->app['translator']->setLocale() in boot()
                $this->app->make('translator');
            }
        };

        $this->bootWith($app, [
            \Illuminate\Translation\TranslationServiceProvider::class,
            $earlyProvider,
            AsyncServiceProvider::class,
        ]);

        $this->assertInstanceOf(
            AsyncTranslator::class,
            $app->make('translator'),
            'app("translator") must be AsyncTranslator even if resolved early. ' .
            'If this fails — registerTranslatorAdapter() runs too late in boot().'
        );
    }
}
