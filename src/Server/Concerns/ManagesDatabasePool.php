<?php

namespace TrueAsync\Laravel\Server\Concerns;

/**
 * Provides PDO Pool configuration and warm-up for async servers.
 *
 * Requires $this->app to be an Illuminate\Contracts\Foundation\Application instance.
 */
trait ManagesDatabasePool
{
    /**
     * Inject PDO Pool options into every database connection config.
     * Must be called before any DB connection is established.
     */
    protected function configureDatabasePool(): void
    {
        $poolConfig = $this->app->make('config')->get('async.db_pool', []);

        if (empty($poolConfig['enabled'])) {
            return;
        }

        $connections = $this->app->make('config')->get('database.connections', []);

        foreach (array_keys($connections) as $name) {
            $this->app->make('config')->set(
                "database.connections.{$name}.options",
                array_replace(
                    $this->app->make('config')->get("database.connections.{$name}.options", []),
                    [
                        \PDO::ATTR_POOL_ENABLED              => true,
                        \PDO::ATTR_POOL_MIN                  => $poolConfig['min'] ?? 2,
                        \PDO::ATTR_POOL_MAX                  => $poolConfig['max'] ?? 10,
                        \PDO::ATTR_POOL_HEALTHCHECK_INTERVAL => $poolConfig['healthcheck_interval'] ?? 30,
                    ]
                )
            );
        }
    }

    /**
     * Force the DatabaseManager to establish its connection in the current coroutine scope.
     *
     * PDO Pool must be created in the server coroutine (not lazily in a request coroutine),
     * otherwise the pool ends up scoped to a short-lived coroutine and gets destroyed between requests.
     */
    protected function warmUpDatabasePool(): void
    {
        $poolConfig = $this->app->make('config')->get('async.db_pool', []);

        if (empty($poolConfig['enabled'])) {
            return;
        }

        if (!$this->app->bound('db')) {
            return;
        }

        try {
            $this->app->make('db')->connection()->getPdo();
        } catch (\Throwable $e) {
            echo "[async] DB pool warm-up failed: " . $e->getMessage() . "\n";
        }
    }
}
