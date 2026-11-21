<?php

namespace Spawn\Laravel\Database;

use Closure;
use Illuminate\Database\LostConnectionException;
use PDO;
use Spawn\Laravel\Foundation\AsyncApplication;
use Throwable;

use function Async\coroutine_context;

/**
 * Stores the transaction counter in coroutine context instead of
 * the shared Connection instance property.
 *
 * PDO Pool gives each coroutine its own physical connection,
 * so the transaction counter must also be per-coroutine — and still per connection:
 * the context entry is keyed by the Connection object itself. A name would not do: a
 * connection may have none, two can share one, and a purged connection is replaced under it.
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
 *
 * A transaction stays on the PDO handle it began on. A sibling coroutine that loses its
 * connection reconnects the connection for the whole worker, and on the new handle the
 * transaction's next statement would autocommit and its rollBack() would do nothing: the
 * coroutine keeps the handle in its own state until its level returns to 0.
 *
 * The rest of the state Laravel keeps on a connection per request is PerRequestConnectionState's.
 */
trait CoroutineTransactions
{
    use PerRequestConnectionState;

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
            return coroutine_context()->find($this) ?? 0;
        }

        return $this->transactions;
    }

    private function setTransactionLevel(int $level): void
    {
        if ($this->isAsyncMode()) {
            // Level 0 removes the entry rather than storing a zero, so a coroutine that has
            // finished its transactions holds no reference to the connection.
            if ($level === 0) {
                coroutine_context()->unset($this);
                $this->releaseTransactionHandle();
            } else {
                coroutine_context()->set($this, $level, replace: true);
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
                $this->refuseLostTransaction();

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
            $this->beginOnPinnedHandle();
        } elseif ($this->transactionLevel() >= 1 && $this->queryGrammar->supportsSavepoints()) {
            $this->createSavepoint();
        }
    }

    /**
     * BEGIN on the handle the transaction will keep. Pinned before the statement: a sibling may
     * reconnect while BEGIN waits for the server, and the transaction is on the handle BEGIN ran on.
     */
    private function beginOnPinnedHandle(): void
    {
        $this->reconnectIfMissingConnection();
        $this->pinTransactionHandle();

        try {
            $this->executeBeginTransactionStatement();
        } catch (Throwable $e) {
            try {
                $this->handleBeginTransactionException($e);
            } catch (Throwable $e) {
                $this->releaseTransactionHandle();

                throw $e;
            }
        }
    }

    /**
     * Laravel's, but the reconnected handle is pinned before BEGIN is retried: the reconnect went
     * through setPdo(), which released the one pinned before.
     */
    protected function handleBeginTransactionException(Throwable $e)
    {
        if (! $this->causedByLostConnection($e)) {
            throw $e;
        }

        $this->reconnect();
        $this->pinTransactionHandle();

        $this->executeBeginTransactionStatement();
    }

    private function pinTransactionHandle(): void
    {
        if (! $this->isAsyncMode()) {
            return;
        }

        $state = $this->coroutineState();
        $state->pinned = null;
        $state->pinned = $this->getPdo();
    }

    private function releaseTransactionHandle(): void
    {
        $state = $this->coroutineState();
        $state->pinned = null;
        $state->lost = false;
    }

    /**
     * @throws LostConnectionException When the server dropped this coroutine's transaction with its
     *     connection. The message is one Laravel recognises as a lost connection, so the exception
     *     unwinds the transaction level wherever Laravel checks for one.
     */
    private function refuseLostTransaction(): void
    {
        if ($this->isAsyncMode() && $this->coroutineState()->lost) {
            throw new LostConnectionException(
                'Lost connection inside a transaction: the server rolled it back. Roll the transaction '
                .'back before running another statement on this connection.'
            );
        }
    }

    protected function run($query, $bindings, Closure $callback)
    {
        $this->refuseLostTransaction();

        return parent::run($query, $bindings, $callback);
    }

    protected function createSavepoint()
    {
        $this->refuseLostTransaction();

        try {
            $this->getPdo()->exec(
                $this->queryGrammar->compileSavepoint('trans'.($this->transactionLevel() + 1))
            );
        } catch (Throwable $e) {
            $this->markLostTransaction($e);

            throw $e;
        }
    }

    /** Remember that the server dropped this coroutine's open transaction, when $e says so. */
    private function markLostTransaction(?Throwable $e): void
    {
        if ($e === null || ! $this->isAsyncMode() || $this->transactionLevel() === 0) {
            return;
        }

        if ($this->causedByLostConnection($e)) {
            $this->coroutineState()->lost = true;
        }
    }

    /** The direct DB::commit() path; transaction() carries the same steps for its own. */
    public function commit()
    {
        $this->refuseLostTransaction();

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

        // Nothing is left to roll back on the server, and the savepoints went with the transaction.
        if ($this->isAsyncMode() && $this->coroutineState()->lost) {
            $toLevel = 0;
        } else {
            try {
                $this->performRollBack($toLevel);
            } catch (\Throwable $e) {
                $this->handleRollBackException($e);
            }
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
     * Other coroutines mid-transaction on the old handle keep their counters, and getPdo()
     * keeps handing them the old handle until their transactions end.
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
        if (! $this->isAsyncMode()) {
            return parent::getReadPdo();
        }

        if ($this->transactionLevel() > 0 || $this->readsFromWritePdo()) {
            return $this->getPdo();
        }

        $this->coroutineState()->latestPdoType = 'read';

        return $this->resolveHandle('readPdo') ?: $this->getPdo();
    }

    /**
     * The write PDO connection: inside a transaction, the handle the transaction began on.
     *
     * @return \PDO
     */
    public function getPdo()
    {
        if (! $this->isAsyncMode()) {
            return parent::getPdo();
        }

        $state = $this->coroutineState();
        $state->latestPdoType = 'write';

        return $state->pinned ?? $this->resolveHandle('pdo');
    }

    /** @return \PDO */
    public function getDirectPdo()
    {
        if (! $this->isAsyncMode()) {
            return parent::getDirectPdo();
        }

        $this->coroutineState()->latestPdoType = 'direct';

        return $this->resolveHandle('directPdo') ?: $this->getPdo();
    }

    protected function latestReadWriteTypeUsed()
    {
        if (! $this->isAsyncMode()) {
            return parent::latestReadWriteTypeUsed();
        }

        return $this->readWriteType ?? $this->coroutineState()->latestPdoType;
    }

    /**
     * The handle in one of the PDO properties, resolving a lazy one.
     *
     * Connecting suspends the coroutine, so two coroutines meeting the same closure both resolve it.
     * The first to finish stores its handle and the second adopts that one: a transaction may already
     * be open on it. A property replaced by something other than a handle meanwhile (a reconnect, a
     * disconnect) is left alone, and the handle just made serves this call only.
     *
     * @param  'pdo'|'readPdo'|'directPdo'  $property
     */
    private function resolveHandle(string $property): ?PDO
    {
        $handle = $this->{$property};

        if (! $handle instanceof Closure) {
            return $handle;
        }

        $resolved = $handle();
        $current = $this->{$property};

        if ($current === $handle) {
            return $this->{$property} = $resolved;
        }

        return $current instanceof PDO ? $current : $resolved;
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
            $this->markLostTransaction($e->getPrevious());

            throw $e;
        }

        return parent::handleQueryException($e, $query, $bindings, $callback);
    }
}
