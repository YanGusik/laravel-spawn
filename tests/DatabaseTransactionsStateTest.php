<?php

namespace Spawn\Laravel\Tests;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Events\EventServiceProvider;
use ReflectionClass;
use Spawn\Laravel\AsyncServiceProvider;
use Spawn\Laravel\Database\AsyncDatabaseTransactionsManager;
use Spawn\Laravel\Database\AsyncSqliteConnection;
use Spawn\Laravel\Foundation\AsyncApplication;

/**
 * The transaction records of the manager belong to the coroutine that opened them; the
 * manager itself belongs to the worker.
 *
 * It is one object per worker because DatabaseManager hands it to each connection once,
 * when the connection is configured, and the connection keeps it: a per-request binding
 * would never reach a connection configured before the request. The interleaving itself
 * is in TransactionIsolationTest; this file checks that nothing is left on the shared
 * object and that the binding reaches a connection.
 */
class DatabaseTransactionsStateTest extends AsyncTestCase
{
    /**
     * The properties that stay on the shared object. Everything else the framework manager
     * declares must be in AsyncDatabaseTransactionsManager::COROUTINE_STATE, or a Laravel
     * upgrade has added state nobody placed.
     *
     * Empty: the manager is nothing but its records, and every record is a coroutine's.
     */
    private const WORKER_STATE = [];

    protected function tearDown(): void
    {
        (function () {
            static::$resolvers = [];
        })->bindTo(null, Connection::class)();

        parent::tearDown();
    }

    public function test_every_property_of_the_framework_manager_is_placed(): void
    {
        $declared = array_map(
            static fn ($property) => $property->getName(),
            (new ReflectionClass(DatabaseTransactionsManager::class))->getProperties()
        );

        $placed = array_merge(AsyncDatabaseTransactionsManager::COROUTINE_STATE, self::WORKER_STATE);

        $this->assertSame([], array_values(array_diff($declared, $placed)),
            'Laravel added a property to DatabaseTransactionsManager; decide whether it is the coroutine\'s or the worker\'s');
    }

    /**
     * Registered in the order the application uses: Illuminate providers first, packages
     * after them. The connection is made after boot, the way a request makes it, and must
     * hold the replaced binding.
     */
    public function test_a_connection_holds_the_async_manager(): void
    {
        $app = new AsyncApplication(sys_get_temp_dir());

        $app->instance('config', new \Illuminate\Config\Repository([
            'async'    => ['scoped_services' => [], 'db_pool' => ['enabled' => false]],
            'database' => [
                'default'     => 'sqlite',
                'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:']],
            ],
        ]));
        $app->instance('files', new \Illuminate\Filesystem\Filesystem());

        $app->register(EventServiceProvider::class);
        $app->register(DatabaseServiceProvider::class);
        $app->register(AsyncServiceProvider::class);
        $app->boot();

        $manager = $app->make('db.transactions');
        $this->assertInstanceOf(AsyncDatabaseTransactionsManager::class, $manager);

        $connection = $app->make('db')->connection();
        $this->assertInstanceOf(AsyncSqliteConnection::class, $connection);

        $held = Closure::bind(fn () => $this->transactionsManager, $connection, Connection::class)();
        $this->assertSame($manager, $held, 'the connection was handed the replaced binding');
    }
}
