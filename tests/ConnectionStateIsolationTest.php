<?php

namespace Spawn\Laravel\Tests;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use PDO;
use Spawn\Laravel\Database\AsyncMySqlConnection;
use Spawn\Laravel\Database\AsyncSqliteConnection;
use Spawn\Laravel\Foundation\RequestContext;

use function Async\await;
use function Async\delay;
use function Async\spawn;

/**
 * A connection is one object per worker, shared by every coroutine of every request; what Laravel keeps on
 * it per request belongs to that request or to that coroutine.
 *
 * Request scope: the query log and whether it is on, the sticky "records modified" flag, the total query
 * duration and which duration handlers have fired, useWriteConnectionWhenReading(). Coroutine scope:
 * pretend(), usingWritePdo(), the PDO a transaction began on and which PDO was handed out last.
 *
 * Each closure given to runParallel() is a request of its own; request() marks it as one, the way DevServer
 * does, so the coroutines it spawns share its request state.
 */
class ConnectionStateIsolationTest extends AsyncTestCase
{
    /** A container a previous test left in async mode would switch the sync-mode cases here to async. */
    protected function setUp(): void
    {
        parent::setUp();

        \Illuminate\Container\Container::setInstance(null);
    }

    protected function tearDown(): void
    {
        (function () {
            static::$resolvers = [];
        })->bindTo(null, Connection::class)();

        parent::tearDown();
    }

    private function sqlite(array $config = []): AsyncSqliteConnection
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('create table users (name text)');

        return new AsyncSqliteConnection($pdo, ':memory:', '', $config + ['name' => 'test', 'driver' => 'sqlite']);
    }

    private function rows(Connection $connection): array
    {
        return array_column($connection->getPdo()->query('select name from users')->fetchAll(), 'name');
    }

    /** A request handler: marks its scope as the request's own before running $fn. */
    private function request(Closure $fn): Closure
    {
        return function () use ($fn) {
            RequestContext::begin();

            return $fn();
        };
    }

    // ── pretend() ────────────────────────────────────────────────────────────

    public function test_a_pretending_coroutine_does_not_stop_a_siblings_write(): void
    {
        $connection = $this->sqlite();
        $connection->bootCompleted();

        $results = $this->runParallel([
            'a' => fn () => $connection->pretend(function () {
                delay(50);
            }),
            'b' => function () use ($connection) {
                delay(10);

                return $connection->insert('insert into users (name) values (?)', ['written']);
            },
        ]);

        $this->assertSame(['written'], $this->rows($connection), 'the sibling write was pretended');
        $this->assertSame([], $results['a'], "the sibling's insert was captured by another coroutine's pretend()");
    }

    public function test_pretend_returns_the_queries_of_its_own_callback(): void
    {
        $connection = $this->sqlite();
        $connection->bootCompleted();

        $results = $this->runParallel([
            'a' => fn () => $connection->pretend(function (Connection $connection) {
                $connection->insert('insert into users (name) values (?)', ['pretended']);
            }),
        ]);

        $this->assertSame([], $this->rows($connection));
        $this->assertCount(1, $results['a']);
        $this->assertSame("insert into users (name) values ('pretended')", $results['a'][0]['query']);
    }

    public function test_without_pretending_writes_inside_pretend(): void
    {
        $connection = $this->sqlite();
        $connection->bootCompleted();

        $this->runParallel([
            'a' => fn () => $connection->pretend(function (Connection $connection) {
                $connection->withoutPretending(
                    fn () => $connection->insert('insert into users (name) values (?)', ['real'])
                );
                $connection->insert('insert into users (name) values (?)', ['pretended']);
            }),
        ]);

        $this->assertSame(['real'], $this->rows($connection));
    }

    // ── The query log ────────────────────────────────────────────────────────

    public function test_a_query_log_records_its_own_request_alone(): void
    {
        $connection = $this->sqlite();
        $connection->bootCompleted();

        $results = $this->runParallel([
            'a' => $this->request(function () use ($connection) {
                $connection->enableQueryLog();
                $connection->select('select ?', ['tenant_a']);
                delay(50);

                return array_column($connection->getQueryLog(), 'bindings');
            }),
            'b' => $this->request(function () use ($connection) {
                delay(10);
                $connection->select('select ?', ['tenant_b_secret']);

                return ['logging' => $connection->logging(), 'log' => $connection->getQueryLog()];
            }),
        ]);

        $this->assertSame([['tenant_a']], $results['a'], "request a's log recorded request b's query");
        $this->assertSame(['logging' => false, 'log' => []], $results['b']);
    }

    public function test_the_coroutines_of_one_request_share_its_query_log(): void
    {
        $connection = $this->sqlite();
        $connection->bootCompleted();

        $results = $this->runParallel([
            'a' => $this->request(function () use ($connection) {
                $connection->enableQueryLog();
                await(spawn(fn () => $connection->select('select ?', ['child'])));

                return array_column($connection->getQueryLog(), 'bindings');
            }),
        ]);

        $this->assertSame([['child']], $results['a']);
    }

    public function test_a_query_log_switched_on_at_boot_is_on_in_every_request(): void
    {
        $connection = $this->sqlite();
        $connection->enableQueryLog();
        $connection->bootCompleted();

        $results = $this->runParallel([
            'a' => $this->request(fn () => $connection->logging()),
            'b' => $this->request(fn () => $connection->logging()),
        ]);

        $this->assertSame(['a' => true, 'b' => true], $results);
    }

    // ── Which PDO reads go to ────────────────────────────────────────────────

    public function test_a_write_in_one_request_does_not_make_another_sticky(): void
    {
        $connection = $this->sqlite(['sticky' => true]);
        $readPdo = new PDO('sqlite::memory:');
        $connection->setReadPdo($readPdo);
        $connection->bootCompleted();

        $results = $this->runParallel([
            'a' => $this->request(function () use ($connection, $readPdo) {
                $connection->insert('insert into users (name) values (?)', ['x']);

                return [
                    'own' => $connection->getReadPdo() === $readPdo,
                    'child' => await(spawn(fn () => $connection->getReadPdo() === $readPdo)),
                ];
            }),
            'b' => $this->request(function () use ($connection, $readPdo) {
                delay(20);

                return $connection->getReadPdo() === $readPdo;
            }),
        ]);

        $this->assertSame(['own' => false, 'child' => false], $results['a'], 'a request reads its own writes');
        $this->assertTrue($results['b'], "request b is pinned to the write PDO by request a's write");
    }

    public function test_reading_from_the_write_connection_is_set_per_request(): void
    {
        $connection = $this->sqlite();
        $readPdo = new PDO('sqlite::memory:');
        $connection->setReadPdo($readPdo);
        $connection->bootCompleted();

        $results = $this->runParallel([
            'a' => $this->request(function () use ($connection, $readPdo) {
                $connection->useWriteConnectionWhenReading();
                delay(50);

                return $connection->getReadPdo() === $readPdo;
            }),
            'b' => $this->request(function () use ($connection, $readPdo) {
                delay(10);

                return $connection->getReadPdo() === $readPdo;
            }),
        ]);

        $this->assertSame(['a' => false, 'b' => true], $results);
    }

    public function test_using_the_write_pdo_is_set_per_coroutine(): void
    {
        $connection = $this->sqlite();
        $readPdo = new PDO('sqlite::memory:');
        $connection->setReadPdo($readPdo);
        $connection->bootCompleted();

        $results = $this->runParallel([
            'a' => $this->request(function () use ($connection, $readPdo) {
                return $connection->usingWritePdo(function () use ($connection, $readPdo) {
                    $sibling = spawn(fn () => $connection->getReadPdo() === $readPdo);
                    delay(20);

                    return ['own' => $connection->getReadPdo() === $readPdo, 'sibling' => await($sibling)];
                });
            }),
        ]);

        $this->assertSame(['own' => false, 'sibling' => true], $results['a']);
    }

    public function test_the_type_of_the_last_pdo_is_kept_per_coroutine(): void
    {
        $connection = $this->sqlite();
        $connection->setReadPdo(new PDO('sqlite::memory:'));
        $connection->bootCompleted();

        $latest = Closure::bind(fn () => $this->latestReadWriteTypeUsed(), $connection, Connection::class);

        $results = $this->runParallel([
            'a' => function () use ($connection, $latest) {
                $connection->getReadPdo();
                delay(50);

                return $latest();
            },
            'b' => function () use ($connection) {
                delay(10);
                $connection->getPdo();
            },
        ]);

        $this->assertSame('read', $results['a']);
    }

    // ── Query duration ───────────────────────────────────────────────────────

    public function test_the_total_query_duration_is_per_request(): void
    {
        $connection = $this->sqlite();
        $connection->bootCompleted();

        $results = $this->runParallel([
            'a' => $this->request(function () use ($connection) {
                $connection->logQuery('select 1', [], 30.0);
                await(spawn(fn () => $connection->logQuery('select 2', [], 5.0)));
                delay(50);

                return $connection->totalQueryDuration();
            }),
            'b' => $this->request(function () use ($connection) {
                delay(10);
                $connection->logQuery('select 3', [], 100.0);

                return $connection->totalQueryDuration();
            }),
        ]);

        $this->assertSame(['a' => 35.0, 'b' => 100.0], $results);
    }

    public function test_a_duration_handler_registered_at_boot_fires_once_in_every_request(): void
    {
        $connection = $this->sqlite();
        $connection->setEventDispatcher(new \Illuminate\Events\Dispatcher());

        $fired = [];
        $connection->whenQueryingForLongerThan(10, function () use (&$fired) {
            $fired[] = RequestContext::current();
        });

        $connection->bootCompleted();

        $this->runParallel([
            'a' => $this->request(function () use ($connection) {
                $connection->logQuery('select 1', [], 20.0);
                $connection->logQuery('select 2', [], 20.0);
            }),
        ]);
        $this->runParallel([
            'b' => $this->request(function () use ($connection) {
                $connection->logQuery('select 3', [], 5.0);
                $connection->logQuery('select 4', [], 6.0);
            }),
        ]);

        $this->assertCount(2, $fired, 'once in each request: the first over the threshold, the second at its sum');
        $this->assertNotSame($fired[0], $fired[1]);
    }

    public function test_a_duration_handler_registered_in_a_request_ends_with_it(): void
    {
        $connection = $this->sqlite();
        $connection->setEventDispatcher(new \Illuminate\Events\Dispatcher());
        $connection->bootCompleted();

        $fired = 0;

        $this->runParallel([
            'a' => $this->request(function () use ($connection, &$fired) {
                $connection->whenQueryingForLongerThan(10, function () use (&$fired) {
                    $fired++;
                });
                $connection->logQuery('select 1', [], 20.0);
            }),
        ]);
        $this->runParallel([
            'b' => $this->request(function () use ($connection) {
                $connection->allowQueryDurationHandlersToRunAgain();
                $connection->logQuery('select 2', [], 20.0);
            }),
        ]);

        $this->assertSame(1, $fired, "request a's handler fired in request b");
    }

    // ── The PDO handle ───────────────────────────────────────────────────────

    /**
     * Two coroutines meeting a lazy PDO both resolve it, and a connect suspends: the one finishing second
     * must not replace the handle the first already began a transaction on.
     */
    public function test_a_lazy_pdo_is_resolved_into_one_handle(): void
    {
        $handles = [];
        $resolver = function () use (&$handles) {
            delay(count($handles) === 0 ? 50 : 10);

            return $handles[] = new PDO('sqlite::memory:');
        };

        $connection = new AsyncSqliteConnection($resolver, ':memory:', '', ['name' => 'test', 'driver' => 'sqlite']);
        $connection->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($connection) {
                $connection->beginTransaction();
                $began = $connection->getPdo();
                delay(80);

                return ['began' => $began, 'now' => $connection->getPdo(), 'open' => $began->inTransaction()];
            },
            'b' => fn () => $connection->getPdo(),
        ]);

        $this->assertSame($results['a']['began'], $results['b'], 'the two coroutines got two handles');
        $this->assertSame($results['a']['began'], $results['a']['now']);
        $this->assertTrue($results['a']['open']);
    }

    /**
     * A sibling's lost connection reconnects the worker-wide handle. A transaction already open stays on
     * the handle it began on: on the new one it would run in autocommit and its rollBack would do nothing.
     */
    public function test_a_reconnect_by_a_sibling_leaves_an_open_transaction_on_its_handle(): void
    {
        $began = new RecordingPdo();
        $connection = new AsyncMySqlConnection($began, 'test', '', ['name' => 'test']);
        $connection->bootCompleted();

        $fresh = new RecordingPdo();
        $connection->setReconnector(fn (Connection $connection) => $connection->setPdo($fresh));

        $results = $this->runParallel([
            'a' => function () use ($connection) {
                $connection->beginTransaction();
                delay(50);
                $during = $connection->getPdo();
                $connection->rollBack();

                return ['during' => $during, 'after' => $connection->getPdo()];
            },
            'b' => function () use ($connection, $began) {
                delay(10);
                $began->loseNextStatement = true;

                return $connection->statement('update users set name = ?', ['b']);
            },
        ]);

        $this->assertSame($began, $results['a']['during'], 'the transaction moved to the reconnected handle');
        $this->assertSame(['BEGIN', 'ROLLBACK'], $began->log);
        $this->assertSame($fresh, $results['a']['after'], 'outside the transaction the coroutine takes the new one');
        $this->assertTrue($results['b']);
    }

    /** A sibling's disconnect() empties the shared handle; the transaction goes on, on its own. */
    public function test_a_disconnect_by_a_sibling_leaves_an_open_transaction_on_its_handle(): void
    {
        $began = new RecordingPdo();
        $connection = new AsyncMySqlConnection($began, 'test', '', ['name' => 'test']);
        $connection->bootCompleted();

        $fresh = new RecordingPdo();
        $connection->setReconnector(fn (Connection $connection) => $connection->setPdo($fresh));

        $this->runParallel([
            'a' => function () use ($connection) {
                $connection->beginTransaction();
                delay(50);
                $connection->statement('update users set name = ?', ['a']);
                $connection->commit();
            },
            'b' => function () use ($connection) {
                delay(10);
                $connection->disconnect();
            },
        ]);

        $this->assertSame(['BEGIN', 'update users set name = ?', 'COMMIT'], $began->log);
        $this->assertSame([], $fresh->log);
    }

    /**
     * The server dropped the transaction with the connection. A statement run after that would autocommit
     * on whatever link the pool hands out next, so it is refused until the transaction is rolled back.
     */
    public function test_a_transaction_whose_connection_was_lost_refuses_further_statements(): void
    {
        $pdo = new RecordingPdo();
        $connection = new AsyncMySqlConnection($pdo, 'test', '', ['name' => 'test']);
        $connection->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($connection, $pdo) {
                $connection->beginTransaction();
                $pdo->loseNextStatement = true;

                try {
                    $connection->statement('update users set name = ?', ['lost']);
                } catch (QueryException) {
                }

                try {
                    $connection->statement('update users set name = ?', ['after']);
                    $after = 'ran';
                } catch (LostConnectionException) {
                    $after = 'refused';
                }

                try {
                    $connection->beginTransaction();
                    $savepoint = 'created';
                } catch (LostConnectionException) {
                    $savepoint = 'refused';
                }

                $connection->rollBack(0);
                $level = $connection->transactionLevel();
                $connection->statement('update users set name = ?', ['next']);

                return ['after' => $after, 'savepoint' => $savepoint, 'level' => $level];
            },
        ]);

        $this->assertSame(['after' => 'refused', 'savepoint' => 'refused', 'level' => 0], $results['a']);
        $this->assertSame(['BEGIN', 'update users set name = ?'], $pdo->log);
    }

    public function test_a_savepoint_lost_with_the_connection_marks_the_transaction_lost(): void
    {
        $pdo = new RecordingPdo();
        $connection = new AsyncMySqlConnection($pdo, 'test', '', ['name' => 'test']);
        $connection->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($connection, $pdo) {
                $connection->beginTransaction();
                $pdo->loseNextStatement = true;

                try {
                    $connection->beginTransaction();
                } catch (\PDOException) {
                }

                try {
                    $connection->statement('update users set name = ?', ['after']);
                    $after = 'ran';
                } catch (LostConnectionException) {
                    $after = 'refused';
                }

                $connection->rollBack();

                return ['after' => $after, 'level' => $connection->transactionLevel()];
            },
        ]);

        $this->assertSame(['after' => 'refused', 'level' => 0], $results['a']);
    }

    // ── Outside async mode ───────────────────────────────────────────────────

    public function test_outside_async_mode_the_state_stays_on_the_connection(): void
    {
        $connection = $this->sqlite(['sticky' => true]);
        $readPdo = new PDO('sqlite::memory:');
        $connection->setReadPdo($readPdo);

        $connection->enableQueryLog();
        $connection->insert('insert into users (name) values (?)', ['x']);

        $this->assertCount(1, $connection->getQueryLog());
        $this->assertTrue($connection->hasModifiedRecords());
        $this->assertNotSame($readPdo, $connection->getReadPdo());
        $this->assertCount(1, $connection->pretend(fn ($c) => $c->select('select 1')));
    }
}

/**
 * A PDO that records transaction control and statements in $log, and fails the next statement with a
 * dropped server connection when asked. It never connects.
 */
class RecordingPdo extends PDO
{
    /** @var list<string> */
    public array $log = [];

    public bool $loseNextStatement = false;

    private bool $open = false;

    public function __construct()
    {
    }

    public function beginTransaction(): bool
    {
        $this->log[] = 'BEGIN';

        return $this->open = true;
    }

    public function commit(): bool
    {
        $this->log[] = 'COMMIT';
        $this->open = false;

        return true;
    }

    public function rollBack(): bool
    {
        $this->log[] = 'ROLLBACK';
        $this->open = false;

        return true;
    }

    public function inTransaction(): bool
    {
        return $this->open;
    }

    public function exec(string $statement): int|false
    {
        $this->loseIfAsked();
        $this->log[] = $statement;

        return 0;
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $this->loseIfAsked();
        $this->log[] = $query;

        return new class extends \PDOStatement {
            public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
            {
                return true;
            }

            public function execute(?array $params = null): bool
            {
                return true;
            }
        };
    }

    private function loseIfAsked(): void
    {
        if ($this->loseNextStatement) {
            $this->loseNextStatement = false;

            throw new \PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        }
    }
}
