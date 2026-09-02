<?php

namespace Spawn\Laravel\Database;

use Spawn\Laravel\Foundation\AsyncApplication;

use function Async\coroutine_context;

/**
 * Stores the transaction counter in coroutine context instead of
 * the shared Connection instance property.
 *
 * PDO Pool gives each coroutine its own physical connection,
 * so the transaction counter must also be per-coroutine.
 *
 * Usage: `use CoroutineTransactions;` in a Connection subclass. If that subclass
 * inherits a transaction() of its own — SqlServerConnection has one — the trait's
 * takes precedence over it silently, and the parent's has to be reached through an
 * alias. AsyncSqlServerConnection shows the shape.
 *
 * Every method of Connection that reads or writes $this->transactions is
 * overridden here to go through transactionLevel() instead. A method left
 * behind reads a counter that is always 0 under async serving; besides
 * ManagesTransactions' own accessors, Laravel 13 touches the property in
 * transaction(), getReadPdo(), handleQueryException() and setPdo().
 *
 * The levels reported to the transactions manager interleave between coroutines
 * the same way, and Laravel's manager identifies a transaction by connection name
 * and level alone. AsyncDatabaseTransactionsManager keeps its records in the same
 * coroutine context as this counter, so a coroutine's afterCommit callbacks wait
 * for that coroutine's commit.
 */
trait CoroutineTransactions
{
    private const CTX_TRANSACTIONS = 'db.transactions';

    /**
     * Forced async mode — used in tests when no AsyncApplication is available.
     * In production, async mode is detected via AsyncApplication::isAsyncModeEnabled().
     */
    private bool $asyncTransactions = false;

    public function bootCompleted(): void
    {
        $this->asyncTransactions = true;
    }

    private function isAsyncMode(): bool
    {
        if ($this->asyncTransactions) {
            return true;
        }

        $app = app();

        return $app instanceof AsyncApplication && $app->isAsyncModeEnabled();
    }

    public function transactionLevel()
    {
        if ($this->isAsyncMode()) {
            return coroutine_context()->find(self::CTX_TRANSACTIONS) ?? 0;
        }

        return $this->transactions;
    }

    private function setTransactionLevel(int $level): void
    {
        if ($this->isAsyncMode()) {
            $ctx = coroutine_context();
            if ($ctx->find(self::CTX_TRANSACTIONS) === null) {
                $ctx->set(self::CTX_TRANSACTIONS, $level);
            } else {
                $ctx->set(self::CTX_TRANSACTIONS, $level, replace: true);
            }
        } else {
            $this->transactions = $level;
        }
    }

    /**
     * Run the callback inside a transaction, retrying it up to $attempts times
     * on a deadlock, and return whatever the callback returned.
     *
     * Laravel's version commits inline against $this->transactions, which is always 0
     * under async serving, so PDO::commit() is never reached.
     *
     * Do not fold the commit below into a call to commit(): the transaction manager and
     * the 'committed' event belong outside the try, or a callback throwing from either
     * sends the retry loop back over writes that are already committed.
     *
     * @template TReturn of mixed
     *
     * @param  (\Closure(static): TReturn)  $callback
     * @param  int  $attempts
     * @return TReturn
     *
     * @throws \Throwable
     */
    public function transaction(\Closure $callback, $attempts = 1)
    {
        for ($currentAttempt = 1; $currentAttempt <= $attempts; $currentAttempt++) {
            $this->beginTransaction();

            try {
                $callbackResult = $callback($this);
            } catch (\Throwable $e) {
                $this->handleTransactionException($e, $currentAttempt, $attempts);

                continue;
            }

            // Same shape as ManagesTransactions::transaction(), and commit() below carries
            // the same steps for the direct DB::commit() path: a Laravel upgrade that
            // changes one of them has to be carried into both.
            $levelBeingCommitted = $this->transactionLevel();

            try {
                if ($this->transactionLevel() == 1) {
                    $this->fireConnectionEvent('committing');
                    $this->getPdo()->commit();
                }

                $this->setTransactionLevel(max(0, $this->transactionLevel() - 1));
            } catch (\Throwable $e) {
                $this->handleCommitTransactionException($e, $currentAttempt, $attempts);

                continue;
            }

            $this->transactionsManager?->commit(
                $this->getName(), $levelBeingCommitted, $this->transactionLevel()
            );

            $this->fireConnectionEvent('committed');

            return $callbackResult;
        }
    }

    public function beginTransaction()
    {
        foreach ($this->beforeStartingTransaction as $callback) {
            $callback($this);
        }

        $this->createTransaction();

        $this->setTransactionLevel($this->transactionLevel() + 1);

        $this->transactionsManager?->begin(
            $this->getName(), $this->transactionLevel()
        );

        $this->fireConnectionEvent('beganTransaction');
    }

    protected function createTransaction()
    {
        if ($this->transactionLevel() == 0) {
            $this->reconnectIfMissingConnection();

            try {
                $this->executeBeginTransactionStatement();
            } catch (\Throwable $e) {
                $this->handleBeginTransactionException($e);
            }
        } elseif ($this->transactionLevel() >= 1 && $this->queryGrammar->supportsSavepoints()) {
            $this->createSavepoint();
        }
    }

    protected function createSavepoint()
    {
        $this->getPdo()->exec(
            $this->queryGrammar->compileSavepoint('trans'.($this->transactionLevel() + 1))
        );
    }

    /** The direct DB::commit() path; transaction() carries the same steps for its own. */
    public function commit()
    {
        if ($this->transactionLevel() == 1) {
            $this->fireConnectionEvent('committing');
            $this->getPdo()->commit();
        }

        $levelBeingCommitted = $this->transactionLevel();
        $this->setTransactionLevel(max(0, $this->transactionLevel() - 1));

        $this->transactionsManager?->commit(
            $this->getName(), $levelBeingCommitted, $this->transactionLevel()
        );

        $this->fireConnectionEvent('committed');
    }

    public function rollBack($toLevel = null)
    {
        $toLevel = is_null($toLevel)
            ? $this->transactionLevel() - 1
            : $toLevel;

        if ($toLevel < 0 || $toLevel >= $this->transactionLevel()) {
            return;
        }

        try {
            $this->performRollBack($toLevel);
        } catch (\Throwable $e) {
            $this->handleRollBackException($e);
        }

        $this->setTransactionLevel($toLevel);

        $this->transactionsManager?->rollback(
            $this->getName(), $this->transactionLevel()
        );

        $this->fireConnectionEvent('rollingBack');
    }

    protected function handleRollBackException(\Throwable $e)
    {
        if ($this->causedByLostConnection($e)) {
            $this->setTransactionLevel(0);

            $this->transactionsManager?->rollback(
                $this->getName(), $this->transactionLevel()
            );
        }

        throw $e;
    }

    protected function handleCommitTransactionException(\Throwable $e, $currentAttempt, $maxAttempts)
    {
        $this->setTransactionLevel(max(0, $this->transactionLevel() - 1));

        if ($this->causedByConcurrencyError($e) && $currentAttempt < $maxAttempts) {
            $pdo = $this->getPdo();

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return;
        }

        if ($this->causedByLostConnection($e)) {
            $this->setTransactionLevel(0);
        }

        throw $e;
    }

    protected function handleTransactionException(\Throwable $e, $currentAttempt, $maxAttempts)
    {
        if ($this->causedByConcurrencyError($e) &&
            $this->transactionLevel() > 1) {
            $this->setTransactionLevel($this->transactionLevel() - 1);

            $this->transactionsManager?->rollback(
                $this->getName(), $this->transactionLevel()
            );

            throw new \Illuminate\Database\DeadlockException(
                $e->getMessage(), is_int($e->getCode()) ? $e->getCode() : 0, $e
            );
        }

        $this->rollBack();

        if ($this->causedByConcurrencyError($e) &&
            $currentAttempt < $maxAttempts) {
            return;
        }

        throw $e;
    }

    /**
     * Replace the PDO handle, clearing the transaction counter of the coroutine that
     * replaces it — a fresh handle carries no transaction. disconnect() reaches this
     * through setPdo(null).
     *
     * Other coroutines mid-transaction on the old handle keep their counters and wake up
     * at level 1 with a link that never saw their BEGIN. That needs the pool, not a counter.
     *
     * @param  \PDO|\Closure|null  $pdo
     * @return $this
     */
    public function setPdo($pdo)
    {
        $this->setTransactionLevel(0);

        return parent::setPdo($pdo);
    }

    /**
     * The PDO connection that reads go through: the write one while a transaction is open,
     * because a replica cannot see rows the transaction has not committed yet.
     *
     * @return \PDO
     */
    public function getReadPdo()
    {
        if ($this->transactionLevel() > 0) {
            return $this->getPdo();
        }

        return parent::getReadPdo();
    }

    /**
     * A statement that failed inside a transaction is not retried: reconnecting would
     * run it on a fresh connection, outside the transaction the caller opened, and the
     * rest of the block would then commit or roll back without it.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @return mixed
     *
     * @throws \Illuminate\Database\QueryException
     */
    protected function handleQueryException(
        \Illuminate\Database\QueryException $e,
        $query,
        $bindings,
        \Closure $callback
    ) {
        if ($this->transactionLevel() > 0) {
            throw $e;
        }

        return parent::handleQueryException($e, $query, $bindings, $callback);
    }
}
