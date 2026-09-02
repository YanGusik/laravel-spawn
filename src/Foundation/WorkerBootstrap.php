<?php

namespace Spawn\Laravel\Foundation;

use Illuminate\Contracts\Foundation\Application;
use Spawn\Laravel\Database\Eloquent\EloquentOverrides;
use Spawn\Laravel\Database\PoolAttributes;

/**
 * Everything a worker does between a booted application and its first request.
 *
 * The three servers each need exactly this, which is why it is here rather than in one
 * of them: they differ in how they accept a connection and in nothing else about start-up.
 *
 * The order is the point. The pools are configured first, the adapters are told that
 * boot is over next, and async mode is switched on last — because switching one on is
 * what makes a write per-request, and bootstrap has no request. Anything configured
 * after the first switch is configured where nobody reads it.
 */
final class WorkerBootstrap
{
    /**
     * Take a booted application to the state a request handler expects, once per worker.
     *
     * A second call configures through switched adapters and loses what it configures.
     */
    public static function run(Application $app): void
    {
        set_time_limit(0);

        self::configure($app);
        self::switchToAsync($app);
    }

    private static function configure(Application $app): void
    {
        self::configureDatabasePool($app);

        // Redis needs the same treatment: one shared connection would let concurrent
        // coroutines interleave commands on a single socket.
        \Spawn\Laravel\Redis\RedisPool::configure($app);

        // And SMTP, where a message is a sequence of commands rather than one round trip,
        // so an interleaving sends one request's body under another's envelope.
        \Spawn\Laravel\Mail\MailPool::configure($app);
    }

    private static function switchToAsync(Application $app): void
    {
        // Adapters first: AsyncRouter registers Route as per-request here, and
        // enableAsyncMode() walks the per-request aliases only once.
        self::completeBoot($app);

        self::reportEloquentOverrides();

        if ($app instanceof AsyncApplication) {
            $app->enableAsyncMode();
        }
    }

    /**
     * Say out loud when any group of Eloquent copies is Laravel's own class instead.
     *
     * A worker without them still serves, and what it serves is one request's rows to another,
     * or a write that got past a guard it should not have — silently, in valid-looking SQL.
     * That is worth a line on stderr at every start.
     */
    private static function reportEloquentOverrides(): void
    {
        if (EloquentOverrides::isInstalled()) {
            return;
        }

        fwrite(STDERR, '[async] Eloquent is not coroutine-safe in this worker: '
            .EloquentOverrides::status()."\n");
    }

    /**
     * Tell every adapter that registration and boot are over.
     *
     * Each of them behaves like the class it replaces until this call and keeps
     * per-request state in the coroutine context after it, so that what bootstrap
     * configured stays configuration rather than becoming the first request's state.
     */
    private static function completeBoot(Application $app): void
    {
        if ($app->bound('view') && ($view = $app->make('view')) instanceof \Spawn\Laravel\View\AsyncViewFactory) {
            $view->bootCompleted();
        }

        if ($app->bound(\Spatie\Permission\PermissionRegistrar::class)) {
            $registrar = $app->make(\Spatie\Permission\PermissionRegistrar::class);

            if ($registrar instanceof \Spawn\Laravel\Permission\AsyncPermissionRegistrar) {
                $registrar->bootCompleted();
            }
        }

        if ($app->bound(\Inertia\ResponseFactory::class)) {
            $inertia = $app->make(\Inertia\ResponseFactory::class);

            if ($inertia instanceof \Spawn\Laravel\Inertia\AsyncResponseFactory) {
                $inertia->bootCompleted();
            }
        }

        if ($app->bound('translator')
            && ($translator = $app->make('translator')) instanceof \Spawn\Laravel\Translation\AsyncTranslator) {
            $translator->bootCompleted();
        }

        if ($app->bound('config') && ($config = $app->make('config')) instanceof \Spawn\Laravel\Config\AsyncConfig) {
            $config->bootCompleted();
        }

        if ($app->bound('events')
            && ($events = $app->make('events')) instanceof \Spawn\Laravel\Events\AsyncDispatcher) {
            $events->bootCompleted();
        }

        if ($app->bound('router') && ($router = $app->make('router')) instanceof \Spawn\Laravel\Routing\AsyncRouter) {
            $router->bootCompleted();
        }

        if (class_exists(\Laravel\Telescope\Telescope::class)
            && method_exists(\Laravel\Telescope\Telescope::class, 'enableAsyncRecording')) {
            \Laravel\Telescope\Telescope::enableAsyncRecording();
        }
    }

    /**
     * Put every database connection behind the PDO pool.
     *
     * Runs before any connection is established, and purges the ones bootstrap opened:
     * a connection created before the options were set is not pooled, and it would go on
     * being handed to coroutine after coroutine.
     */
    private static function configureDatabasePool(Application $app): void
    {
        $config = $app->make('config');
        $pool   = $config->get('async.db_pool', []);

        if (empty($pool['enabled'])) {
            return;
        }

        foreach (array_keys($config->get('database.connections', [])) as $name) {
            $config->set(
                "database.connections.{$name}.options",
                array_replace(
                    $config->get("database.connections.{$name}.options", []),
                    PoolAttributes::forPool($pool)
                )
            );
        }

        if ($app->bound('db')) {
            $db = $app->make('db');

            // By name: purge() without one reaches the default connection alone.
            foreach (array_keys($db->getConnections()) as $name) {
                $db->purge($name);
            }
        }
    }
}
