<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Spawn\Laravel\Database\AsyncDatabaseTransactionsManager;
use Spawn\Laravel\Database\AsyncMySqlConnection;
use Spawn\Laravel\Database\AsyncSqlServerConnection;
use function Async\delay;

class TransactionIsolationTest extends AsyncTestCase
{
    protected function tearDown(): void
    {
        // Reset Connection::resolverFor() entries set by registerDatabaseAdapter()
        // so they don't bleed into other test classes.
        (function () {
            static::$resolvers = [];
        })->bindTo(null, Connection::class)();

        parent::tearDown();
    }

    private function makeMockConnection(string $class, ?\PDO $pdo = null, array $config = []): Connection
    {
        // 'name' is what Connection::getName() answers, and the transaction manager keys
        // its pending transactions by it — null there is a deprecated array offset.
        return new $class($pdo ?? $this->makeLoggingPdo(), 'test', '', $config + ['name' => 'test']);
    }

    /**
     * A PDO that records the statements it was asked to run in $log, in order,
     * so a test can assert on BEGIN / COMMIT / SAVEPOINT without a database.
     */
    private function makeLoggingPdo(): \PDO
    {
        return new class extends \PDO {
            public array $log = [];

            /** Follows BEGIN / COMMIT / ROLLBACK: Connection::performRollBack() asks before rolling back. */
            private bool $open = false;

            public function __construct()
            {
                // no-op, don't connect
            }

            public function beginTransaction(): bool
            {
                $this->log[] = 'BEGIN';
                $this->open = true;
                return true;
            }

            public function commit(): bool
            {
                $this->log[] = 'COMMIT';
                $this->open = false;
                return true;
            }

            public function exec(string $statement): int|false
            {
                $this->log[] = $statement;
                return 0;
            }

            public function inTransaction(): bool
            {
                return $this->open;
            }

            public function rollBack(): bool
            {
                $this->log[] = 'ROLLBACK';
                $this->open = false;
                return true;
            }
        };
    }

    /**
     * A PDO whose prepare() reports a dropped server connection. Transaction control
     * still succeeds, so a test can open a transaction and then lose the link inside it.
     */
    private function makeLostConnectionPdo(): \PDO
    {
        return new class extends \PDO {
            public function __construct()
            {
                // no-op, don't connect
            }

            public function beginTransaction(): bool
            {
                return true;
            }

            public function commit(): bool
            {
                return true;
            }

            public function inTransaction(): bool
            {
                return false;
            }

            public function rollBack(): bool
            {
                return true;
            }

            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                throw new \PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
            }
        };
    }

    // ── Stock Connection: prove the bug ──────────────────────────────────────

    public function test_stock_connection_transaction_counter_leaks(): void
    {
        $conn = $this->makeMockConnection(MySqlConnection::class);

        $results = $this->runParallel([
            'a' => function () use ($conn) {
                $conn->beginTransaction();
                delay(200);
                $level = $conn->transactionLevel();
                $conn->commit();
                return $level;
            },
            'b' => function () use ($conn) {
                delay(50);
                $levelBefore = $conn->transactionLevel();
                $conn->beginTransaction();
                $levelAfter  = $conn->transactionLevel();
                $conn->commit();
                return ['before' => $levelBefore, 'after' => $levelAfter];
            },
        ]);

        $this->assertEquals(1, $results['b']['before'],
            'BUG confirmed: B sees A\'s transaction counter — should be 0 but is 1');
    }

    // ── AsyncMySqlConnection: counter isolated per coroutine ─────────────────

    public function test_async_connection_transaction_counter_isolated(): void
    {
        $conn = $this->makeMockConnection(AsyncMySqlConnection::class);
        $conn->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($conn) {
                $this->assertEquals(0, $conn->transactionLevel());
                $conn->beginTransaction();
                $this->assertEquals(1, $conn->transactionLevel());
                delay(200);
                $level = $conn->transactionLevel(); // still 1, not affected by B
                $conn->commit();
                return $level;
            },
            'b' => function () use ($conn) {
                delay(50);
                $levelBefore = $conn->transactionLevel(); // must be 0, not A's 1
                $conn->beginTransaction();
                $levelAfter = $conn->transactionLevel();
                $conn->commit();
                return ['before' => $levelBefore, 'after' => $levelAfter];
            },
        ]);

        $this->assertEquals(1, $results['a'], 'A sees its own counter = 1');
        $this->assertEquals(0, $results['b']['before'], 'B sees 0 before begin (no leak from A)');
        $this->assertEquals(1, $results['b']['after'], 'B sees 1 after own begin');
    }

    public function test_async_connection_nested_transactions_isolated(): void
    {
        $conn = $this->makeMockConnection(AsyncMySqlConnection::class);
        $conn->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($conn) {
                $conn->beginTransaction();   // level 1 — BEGIN
                $conn->beginTransaction();   // level 2 — SAVEPOINT
                delay(200);
                $level = $conn->transactionLevel();
                $conn->rollBack();           // level 1
                $conn->rollBack();           // level 0
                return $level;
            },
            'b' => function () use ($conn) {
                delay(50);
                // B must start a real BEGIN, not SAVEPOINT
                $conn->beginTransaction();
                $level = $conn->transactionLevel();
                $conn->commit();
                return $level;
            },
        ]);

        $this->assertEquals(2, $results['a'], 'A has nested transaction level 2');
        $this->assertEquals(1, $results['b'], 'B has own transaction level 1 (not nested into A)');
    }

    // ── AsyncMySqlConnection in async app mode (no bootCompleted) ────────────

    /**
     * In production the connection detects async mode via AsyncApplication::isAsyncModeEnabled()
     * without needing an explicit bootCompleted() call.
     */
    public function test_async_connection_detects_app_async_mode(): void
    {
        $app = new \Spawn\Laravel\Foundation\AsyncApplication(sys_get_temp_dir());
        $app->instance('config', new \Illuminate\Config\Repository([
            'async' => ['scoped_services' => [], 'db_pool' => ['enabled' => false]],
        ]));
        $app->enableAsyncMode();

        // bind app into the container so app() helper finds it
        \Illuminate\Container\Container::setInstance($app);

        $conn = $this->makeMockConnection(AsyncMySqlConnection::class);
        // Note: no bootCompleted() — relies on AsyncApplication::isAsyncModeEnabled()

        $results = $this->runParallel([
            'a' => function () use ($conn) {
                $conn->beginTransaction();
                delay(200);
                $level = $conn->transactionLevel();
                $conn->commit();
                return $level;
            },
            'b' => function () use ($conn) {
                delay(50);
                $before = $conn->transactionLevel();
                $conn->beginTransaction();
                $after  = $conn->transactionLevel();
                $conn->commit();
                return ['before' => $before, 'after' => $after];
            },
        ]);

        $this->assertEquals(1, $results['a']);
        $this->assertEquals(0, $results['b']['before'], 'App async mode detected — no counter leak');
        $this->assertEquals(1, $results['b']['after']);

        \Illuminate\Container\Container::setInstance(null);
    }

    // ── The closure form of transaction() ────────────────────────────────────

    /**
     * Laravel commits the closure form against its own $transactions property instead of
     * calling commit(), so a connection that keeps the counter in coroutine context reaches
     * PDO::commit() only if it overrides transaction() too. Without the override the pooled
     * connection goes back to the pool inside an open transaction and the pool rolls it back.
     */
    public function test_transaction_closure_commits(): void
    {
        $conn = $this->makeMockConnection(AsyncMySqlConnection::class);
        $conn->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($conn) {
                $returned = $conn->transaction(fn () => 'result');

                return [
                    'returned' => $returned,
                    'log'      => $conn->getPdo()->log,
                    'level'    => $conn->transactionLevel(),
                ];
            },
        ]);

        $this->assertSame('result', $results['a']['returned'], 'the callback result is passed through');
        $this->assertSame(['BEGIN', 'COMMIT'], $results['a']['log'], 'the transaction was committed');
        $this->assertSame(0, $results['a']['level'], 'the coroutine counter is back to zero');
    }

    /**
     * A counter left at 1 by the previous call turns the next BEGIN into a SAVEPOINT,
     * so the desync compounds within one request instead of staying local to one block.
     */
    public function test_two_transaction_closures_each_begin_their_own(): void
    {
        $conn = $this->makeMockConnection(AsyncMySqlConnection::class);
        $conn->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($conn) {
                $conn->transaction(fn () => null);
                $conn->transaction(fn () => null);

                return $conn->getPdo()->log;
            },
        ]);

        $this->assertSame(['BEGIN', 'COMMIT', 'BEGIN', 'COMMIT'], $results['a']);
    }

    public function test_transaction_closure_rolls_back_and_rethrows(): void
    {
        $conn = $this->makeMockConnection(AsyncMySqlConnection::class);
        $conn->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($conn) {
                try {
                    $conn->transaction(function () {
                        throw new \RuntimeException('callback failed');
                    });
                    $caught = null;
                } catch (\Throwable $e) {
                    $caught = $e->getMessage();
                }

                return [
                    'caught' => $caught,
                    'log'    => $conn->getPdo()->log,
                    'level'  => $conn->transactionLevel(),
                ];
            },
        ]);

        $this->assertSame('callback failed', $results['a']['caught'], 'the callback exception is rethrown');
        $this->assertSame(['BEGIN', 'ROLLBACK'], $results['a']['log']);
        $this->assertSame(0, $results['a']['level']);
    }

    /**
     * Laravel's retry loop needs the counter it can see: on a deadlock it rolls back,
     * runs the callback again and commits the second attempt.
     */
    public function test_transaction_closure_retries_on_deadlock(): void
    {
        $conn = $this->makeMockConnection(AsyncMySqlConnection::class);
        $conn->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($conn) {
                $attempts = 0;

                $conn->transaction(function () use (&$attempts) {
                    $attempts++;

                    if ($attempts === 1) {
                        throw new \Illuminate\Database\QueryException(
                            'mysql',
                            'update users set name = ?',
                            ['x'],
                            new \PDOException(
                                'SQLSTATE[40001]: Serialization failure: '
                                . '1213 Deadlock found when trying to get lock; try restarting transaction'
                            )
                        );
                    }
                }, attempts: 2);

                return [
                    'attempts' => $attempts,
                    'log'      => $conn->getPdo()->log,
                    'level'    => $conn->transactionLevel(),
                ];
            },
        ]);

        $this->assertSame(2, $results['a']['attempts'], 'the callback ran twice');
        $this->assertSame(['BEGIN', 'ROLLBACK', 'BEGIN', 'COMMIT'], $results['a']['log']);
        $this->assertSame(0, $results['a']['level']);
    }

    /**
     * Two coroutines, one Connection object, one counter each. Without the coroutine-scoped
     * counter the second BEGIN would compile to a SAVEPOINT on a transaction the first
     * coroutine owns, on a physical link the second one never touched.
     */
    public function test_two_coroutines_each_begin_and_commit_their_own(): void
    {
        $conn = $this->makeMockConnection(AsyncMySqlConnection::class);
        $conn->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($conn) {
                $conn->transaction(fn () => delay(200));

                return $conn->transactionLevel();
            },
            'b' => function () use ($conn) {
                delay(50);
                $conn->transaction(fn () => null);

                return $conn->transactionLevel();
            },
        ]);

        // Counted, not ordered: which of the two commits first is a scheduling outcome,
        // and the entries are indistinguishable strings, so the order asserts nothing.
        $this->assertSame(
            ['BEGIN' => 2, 'COMMIT' => 2],
            array_count_values($conn->getPdo()->log),
            'two BEGINs and two COMMITs, and no SAVEPOINT among them'
        );
        $this->assertSame(0, $results['a'], 'A ends at level 0');
        $this->assertSame(0, $results['b'], 'B ends at level 0');
    }

    /**
     * The 'committed' event and the transaction manager run after the commit is final,
     * outside the retry loop's reach. A listener throwing what looks like a deadlock —
     * a queued after-commit job hitting a lock wait timeout, say — would otherwise send
     * the loop back over a callback whose writes are already in the database.
     */
    public function test_a_throwing_committed_listener_does_not_re_run_the_callback(): void
    {
        $conn = $this->makeMockConnection(AsyncMySqlConnection::class);
        $conn->bootCompleted();

        $events = new \Illuminate\Events\Dispatcher();
        $events->listen(\Illuminate\Database\Events\TransactionCommitted::class, function () {
            throw new \Illuminate\Database\QueryException(
                'mysql',
                'insert into jobs (payload) values (?)',
                ['{}'],
                new \PDOException('SQLSTATE[HY000]: Lock wait timeout exceeded; try restarting transaction')
            );
        });

        $conn->setEventDispatcher($events);

        $results = $this->runParallel([
            'a' => function () use ($conn) {
                $runs = 0;

                try {
                    $conn->transaction(function () use (&$runs) {
                        $runs++;
                    }, attempts: 3);
                    $caught = null;
                } catch (\Throwable $e) {
                    $caught = $e->getMessage();
                }

                return ['runs' => $runs, 'caught' => $caught, 'log' => $conn->getPdo()->log];
            },
        ]);

        $this->assertSame(1, $results['a']['runs'], 'the callback ran once');
        $this->assertSame(['BEGIN', 'COMMIT'], $results['a']['log'], 'committed once');
        $this->assertStringContainsString(
            'Lock wait timeout',
            $results['a']['caught'],
            'the listener\'s own exception came out'
        );
    }

    /**
     * The transaction manager runs after the commit for the same reason the event does:
     * `DB::afterCommit()` is where a queued job is dispatched, and a queue driver hitting
     * a lock wait timeout there must not put the retry loop back over the callback.
     */
    public function test_a_throwing_after_commit_callback_does_not_re_run_the_callback(): void
    {
        $conn = $this->makeMockConnection(AsyncMySqlConnection::class);
        $conn->bootCompleted();
        $conn->setTransactionManager(new \Illuminate\Database\DatabaseTransactionsManager());

        $results = $this->runParallel([
            'a' => function () use ($conn) {
                $runs = 0;

                try {
                    $conn->transaction(function () use ($conn, &$runs) {
                        $runs++;

                        $conn->afterCommit(function () {
                            throw new \Illuminate\Database\QueryException(
                                'mysql',
                                'insert into jobs (payload) values (?)',
                                ['{}'],
                                new \PDOException(
                                    'SQLSTATE[HY000]: Lock wait timeout exceeded; try restarting transaction'
                                )
                            );
                        });
                    }, attempts: 3);
                    $caught = null;
                } catch (\Throwable $e) {
                    $caught = $e->getMessage();
                }

                return ['runs' => $runs, 'caught' => $caught, 'log' => $conn->getPdo()->log];
            },
        ]);

        $this->assertSame(1, $results['a']['runs'], 'the callback ran once');
        $this->assertSame(['BEGIN', 'COMMIT'], $results['a']['log'], 'committed once');
        $this->assertStringContainsString('Lock wait timeout', $results['a']['caught']);
    }

    // ── The other reads of the raw counter ───────────────────────────────────

    /**
     * Inside a transaction a read has to go to the connection that opened it: a replica
     * cannot see the uncommitted rows. Connection::getReadPdo() decides that from the raw
     * property, which is zero for a coroutine-scoped counter.
     */
    public function test_read_pdo_inside_a_transaction_is_the_write_connection(): void
    {
        $conn = $this->makeMockConnection(AsyncMySqlConnection::class);
        $conn->bootCompleted();

        $readPdo = $this->makeLoggingPdo();
        $conn->setReadPdo($readPdo);

        $results = $this->runParallel([
            'a' => function () use ($conn, $readPdo) {
                $outside = $conn->getReadPdo() === $readPdo;

                $conn->beginTransaction();
                $inside = $conn->getReadPdo() === $conn->getPdo();
                $conn->rollBack();

                return ['outside' => $outside, 'inside' => $inside];
            },
        ]);

        $this->assertTrue($results['a']['outside'], 'outside a transaction reads go to the read connection');
        $this->assertTrue($results['a']['inside'], 'inside a transaction reads go to the write connection');
    }

    /**
     * A lost connection inside a transaction must surface as a QueryException. Reconnecting
     * and retrying the statement would run it outside the transaction the caller opened,
     * and Connection::handleQueryException() tells the two cases apart by the raw counter.
     */
    public function test_lost_connection_inside_a_transaction_does_not_retry(): void
    {
        $conn = $this->makeMockConnection(AsyncMySqlConnection::class, $this->makeLostConnectionPdo());
        $conn->bootCompleted();

        $reconnects = 0;
        $conn->setReconnector(function () use (&$reconnects) {
            $reconnects++;
        });

        $results = $this->runParallel([
            'a' => function () use ($conn, &$reconnects) {
                $conn->beginTransaction();

                try {
                    $conn->statement('update users set name = ?', ['x']);
                    $thrown = null;
                } catch (\Throwable $e) {
                    $thrown = $e::class;
                }

                return ['thrown' => $thrown, 'reconnects' => $reconnects];
            },
        ]);

        $this->assertSame(\Illuminate\Database\QueryException::class, $results['a']['thrown']);
        $this->assertSame(0, $results['a']['reconnects'], 'no reconnect attempt inside a transaction');
    }

    /**
     * A new handle carries no transaction, so the counter of the coroutine that replaced it
     * goes too — otherwise the next commit() calls PDO::commit() on a link that never saw a
     * BEGIN. The route production takes is disconnect(), which reaches setPdo(null).
     */
    public function test_disconnecting_clears_the_counter_of_that_coroutine(): void
    {
        $conn = $this->makeMockConnection(AsyncMySqlConnection::class);
        $conn->bootCompleted();

        $fresh = $this->makeLoggingPdo();

        $results = $this->runParallel([
            'a' => function () use ($conn, $fresh) {
                $conn->beginTransaction();
                $before = $conn->transactionLevel();

                $conn->disconnect();
                $conn->setPdo($fresh);
                $conn->commit();

                return ['before' => $before, 'after' => $conn->transactionLevel()];
            },
        ]);

        $this->assertSame(1, $results['a']['before']);
        $this->assertSame(0, $results['a']['after'], 'the counter went with the old handle');
        $this->assertSame([], $fresh->log, 'nothing was committed on the replacement handle');
    }

    // ── SQL Server, the one driver with a transaction() of its own ───────────

    public function test_sqlsrv_commits_through_the_coroutine_counter(): void
    {
        $conn = $this->makeMockConnection(AsyncSqlServerConnection::class, null, ['driver' => 'sqlsrv']);
        $conn->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($conn) {
                $conn->transaction(fn () => null);

                return ['log' => $conn->getPdo()->log, 'level' => $conn->transactionLevel()];
            },
        ]);

        $this->assertSame(['BEGIN', 'COMMIT'], $results['a']['log']);
        $this->assertSame(0, $results['a']['level']);
    }

    /**
     * The dblib and odbc drivers reach SQL Server without PDO transaction control, so
     * SqlServerConnection issues the statements itself. A trait method takes precedence over
     * an inherited one, which is enough to lose that path without any error.
     *
     * The package registers its resolver under 'sqlsrv' alone, so nothing here constructs
     * this class with another driver today. The test guards the day something does.
     */
    public function test_other_sqlserver_drivers_keep_their_own_transaction_statements(): void
    {
        $conn = $this->makeMockConnection(AsyncSqlServerConnection::class, null, ['driver' => 'dblib']);
        $conn->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($conn) {
                $returned = $conn->transaction(fn () => 'result');

                return ['returned' => $returned, 'log' => $conn->getPdo()->log];
            },
        ]);

        $this->assertSame('result', $results['a']['returned'], 'the callback result is passed through');
        $this->assertSame(['BEGIN TRAN', 'COMMIT TRAN'], $results['a']['log']);
    }

    // ── The transactions manager ─────────────────────────────────────────────

    /**
     * Laravel's manager identifies a transaction by connection name and level, and two
     * coroutines on one connection name both report level 1: B's commit staged A's pending
     * record with its own and ran A's callback, and A rolled back afterwards. The records
     * are the coroutine's, like the counter.
     */
    public function test_a_commit_runs_the_callbacks_of_its_own_coroutine_alone(): void
    {
        $conn = $this->makeMockConnection(AsyncMySqlConnection::class);
        $conn->bootCompleted();
        $conn->setTransactionManager(new AsyncDatabaseTransactionsManager());

        $events = [];

        $this->runParallel([
            'a' => function () use ($conn, &$events) {
                $conn->beginTransaction();
                $events[] = 'A began';
                $conn->afterCommit(function () use (&$events) {
                    $events[] = 'A callback ran';
                });
                $events[] = 'A registered';
                delay(200);
                $conn->rollBack();
                $events[] = 'A rolled back';
            },
            'b' => function () use ($conn, &$events) {
                delay(50);
                $conn->beginTransaction();
                $events[] = 'B began';
                $conn->afterCommit(function () use (&$events) {
                    $events[] = 'B callback ran';
                });
                $events[] = 'B registered';
                $conn->commit();
                $events[] = 'B committed';
            },
        ]);

        $this->assertSame([
            'A began', 'A registered',
            'B began', 'B registered', 'B callback ran', 'B committed',
            'A rolled back',
        ], $events);
    }

    /**
     * addCallback() attaches to the last pending record of the manager, which under
     * concurrency is whichever coroutine began last: a callback A registered after B's
     * begin() went onto B's record and ran inside B's commit, and A rolled back afterwards.
     */
    public function test_a_callback_attaches_to_the_transaction_of_its_own_coroutine(): void
    {
        $conn = $this->makeMockConnection(AsyncMySqlConnection::class);
        $conn->bootCompleted();
        $conn->setTransactionManager(new AsyncDatabaseTransactionsManager());

        $events = [];

        $this->runParallel([
            'a' => function () use ($conn, &$events) {
                $conn->beginTransaction();
                $events[] = 'A began';
                delay(100);
                $conn->afterCommit(function () use (&$events) {
                    $events[] = 'A callback ran';
                });
                $events[] = 'A registered';
                delay(100);
                $conn->rollBack();
                $events[] = 'A rolled back';
            },
            'b' => function () use ($conn, &$events) {
                delay(50);
                $conn->beginTransaction();
                $events[] = 'B began';
                $conn->afterCommit(function () use (&$events) {
                    $events[] = 'B callback ran';
                });
                $events[] = 'B registered';
                delay(100);
                $conn->commit();
                $events[] = 'B committed';
            },
        ]);

        $this->assertSame([
            'A began',
            'B began', 'B registered',
            'A registered',
            'B callback ran', 'B committed',
            'A rolled back',
        ], $events);
    }

    /**
     * A coroutine that ends inside a transaction leaves the manager a pending record
     * nothing will ever commit. A callback the next coroutine registers outside any
     * transaction of its own runs at once; attached to the leftover record it never runs.
     */
    public function test_a_record_left_by_a_finished_coroutine_does_not_capture_a_later_callback(): void
    {
        $conn = $this->makeMockConnection(AsyncMySqlConnection::class);
        $conn->bootCompleted();
        $conn->setTransactionManager(new AsyncDatabaseTransactionsManager());

        $this->inRequest(function () use ($conn) {
            $conn->beginTransaction();
        });

        $ran = $this->inRequest(function () use ($conn) {
            $ran = false;
            $conn->afterCommit(function () use (&$ran) {
                $ran = true;
            });

            return $ran;
        });

        $this->assertTrue($ran, 'outside a transaction the callback runs at registration');
    }

    /**
     * A rolled-back savepoint takes the callbacks registered inside it, and the outer
     * transaction's callbacks still run on its commit. Unwinding the savepoint assigns
     * the parent record into currentTransaction[$connection], which is the write the
     * state has to hand out by reference.
     */
    public function test_a_rolled_back_savepoint_drops_its_callbacks_and_keeps_the_outer_ones(): void
    {
        $conn = $this->makeMockConnection(AsyncMySqlConnection::class);
        $conn->bootCompleted();
        $conn->setTransactionManager(new AsyncDatabaseTransactionsManager());

        $fired = $this->inRequest(function () use ($conn) {
            $fired = [];

            $conn->beginTransaction();
            $conn->afterCommit(function () use (&$fired) {
                $fired[] = 'outer';
            });

            $conn->beginTransaction();
            $conn->afterCommit(function () use (&$fired) {
                $fired[] = 'inner';
            });
            $conn->rollBack();

            $conn->commit();

            return $fired;
        });

        $this->assertSame(['outer'], $fired);
    }
}
