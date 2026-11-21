<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Database\Connection;
use PDO;
use Spawn\Laravel\Database\AsyncMySqlConnection;
use Spawn\Laravel\Database\PoolAttributes;

use function Async\delay;

/**
 * A lost connection met by one coroutine, against a real MySQL or MariaDB behind the PDO pool.
 *
 * Needs a server: SPAWN_TEST_MYSQL_DSN (for example `mysql:host=127.0.0.1;port=3307;dbname=spawn`),
 * SPAWN_TEST_MYSQL_USER and SPAWN_TEST_MYSQL_PASSWORD. The user must be allowed to KILL its own threads.
 * Skipped without them.
 */
class PooledLostConnectionTest extends AsyncTestCase
{
    private string $dsn;

    private string $user;

    private string $password;

    private PDO $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $dsn = getenv('SPAWN_TEST_MYSQL_DSN');

        $available = is_string($dsn) && $dsn !== '' && extension_loaded('pdo_mysql') && defined('PDO::ATTR_POOL_ENABLED');

        if (! $available) {
            $this->markTestSkipped('needs SPAWN_TEST_MYSQL_DSN, pdo_mysql and the PDO pool');
        }

        $this->dsn = $dsn;
        $this->user = (string) getenv('SPAWN_TEST_MYSQL_USER');
        $this->password = (string) getenv('SPAWN_TEST_MYSQL_PASSWORD');

        $this->admin = new PDO($this->dsn, $this->user, $this->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->admin->exec('drop table if exists spawn_lost');
        $this->admin->exec('create table spawn_lost (id int) engine=innodb');
    }

    protected function tearDown(): void
    {
        (function () {
            static::$resolvers = [];
        })->bindTo(null, Connection::class)();

        if (isset($this->admin)) {
            $this->admin->exec('drop table if exists spawn_lost');
        }

        parent::tearDown();
    }

    private function pool(): PDO
    {
        return new PDO($this->dsn, $this->user, $this->password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            + PoolAttributes::forPool(['min' => 2, 'max' => 4, 'healthcheck_interval' => 0]));
    }

    /** Kill every thread of the test user except the admin's own and those named. */
    private function killIdleThreads(array $spare): void
    {
        $spare[] = (int) $this->admin->query('select connection_id()')->fetchColumn();

        $threads = $this->admin->query(
            "select id from information_schema.processlist where user = substring_index(current_user(), '@', 1)"
        );

        foreach ($threads?->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
            if (! in_array((int) $id, $spare, true)) {
                $this->admin->exec('kill connection '.(int) $id);
            }
        }
    }

    /**
     * Coroutine b's slot was killed: it reconnects and goes on. Coroutine a, inside a transaction meanwhile,
     * keeps the handle its transaction began on, so its rollBack() removes both of its rows.
     */
    public function test_a_siblings_reconnect_does_not_move_an_open_transaction(): void
    {
        $connection = new AsyncMySqlConnection($this->pool(), 'spawn', '', ['name' => 'mysql', 'driver' => 'mysql']);
        $connection->setReconnector(fn (Connection $connection) => $connection->setPdo($this->pool()));
        $connection->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($connection) {
                $connection->beginTransaction();
                $connection->insert('insert into spawn_lost (id) values (1)');
                $thread = (int) $connection->selectOne('select connection_id() as id')->id;
                $this->killIdleThreads([$thread]);
                delay(100);
                $connection->insert('insert into spawn_lost (id) values (2)');
                $connection->rollBack();

                return $thread;
            },
            'b' => function () use ($connection) {
                delay(30);

                return $connection->selectOne('select 1 as one')->one;
            },
        ]);

        $this->assertSame(1, (int) $results['b']);
        $this->assertSame([], $this->admin->query('select id from spawn_lost')->fetchAll(PDO::FETCH_COLUMN),
            'a row of the rolled-back transaction was committed');
    }

    /**
     * The same with b opening a transaction: its BEGIN meets the killed slot, it reconnects and begins on
     * the new handle, and a's transaction stays on the one it began on.
     */
    public function test_a_siblings_reconnect_on_begin_does_not_move_an_open_transaction(): void
    {
        $connection = new AsyncMySqlConnection($this->pool(), 'spawn', '', ['name' => 'mysql', 'driver' => 'mysql']);
        $connection->setReconnector(fn (Connection $connection) => $connection->setPdo($this->pool()));
        $connection->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($connection) {
                $connection->beginTransaction();
                $began = $connection->getPdo();
                $connection->insert('insert into spawn_lost (id) values (1)');
                $this->killIdleThreads([(int) $connection->selectOne('select connection_id() as id')->id]);
                delay(100);
                $connection->insert('insert into spawn_lost (id) values (2)');
                $same = $connection->getPdo() === $began;
                $connection->rollBack();

                return $same;
            },
            'b' => function () use ($connection) {
                delay(30);

                return $connection->transaction(function (Connection $connection) {
                    $connection->insert('insert into spawn_lost (id) values (3)');

                    return true;
                });
            },
        ]);

        $this->assertTrue($results['a'], 'the transaction moved to another handle');
        $this->assertTrue($results['b']);
        $rows = $this->admin->query('select id from spawn_lost')->fetchAll(PDO::FETCH_COLUMN);

        $this->assertSame([3], array_map('intval', $rows),
            'a row of the rolled-back transaction was committed, or the sibling transaction was lost');
    }
}
