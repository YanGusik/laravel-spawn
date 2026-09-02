<?php

namespace Spawn\Laravel\Database;

use Async\Context;
use Illuminate\Database\DatabaseTransactionsManager;

use function Async\coroutine_context;

/**
 * A transactions manager whose pending and committed records belong to the coroutine that
 * opened them.
 *
 * Laravel's manager identifies a transaction by connection name and level. That is one
 * transaction per process under php-fpm and one per coroutine under a PDO pool, where every
 * coroutine on the same connection name reports level 1. On records the worker shares, one
 * coroutine's commit stages every other coroutine's pending records and runs their
 * afterCommit() callbacks, and a callback registered while another coroutine's transaction is
 * the last opened attaches to that one, so a job dispatched from afterCommit() goes out for a
 * transaction that rolls back.
 *
 * The manager stays one object per worker: DatabaseManager::configure() hands it to every
 * connection through setTransactionManager(), and the connection keeps what it was given, so
 * a per-request binding would never reach a connection configured before the request. The
 * state moves instead: the three declared properties are removed from this object in the
 * constructor, so every read and write in the inherited methods falls through to `__get()`
 * and `__set()` and lands in a {@see DatabaseTransactionsState} in the coroutine's context.
 * `__get()` returns by reference, which is what lets unmodified code run
 * `$this->currentTransaction[$connection] = $record` on an array this object does not hold.
 *
 * The coroutine's own context, not the request's: {@see CoroutineTransactions} keeps the
 * transaction counter there because the pooled PDO handle is bound per coroutine, and the
 * records have to agree with the counter. A coroutine spawned inside a request has a counter
 * of its own and gets records of its own to match.
 */
class AsyncDatabaseTransactionsManager extends DatabaseTransactionsManager
{
    /**
     * The properties of DatabaseTransactionsManager that belong to one coroutine.
     *
     * Checked against the framework by DatabaseTransactionsStateTest, which fails when an
     * upgrade adds a property that is in neither this list nor its list of worker-wide ones.
     */
    public const COROUTINE_STATE = [
        'committedTransactions',
        'pendingTransactions',
        'currentTransaction',
    ];

    private const CTX_KEY = 'db.transactions.state';

    /**
     * The context the last state came from, held so that its object handle cannot be handed
     * to another context, and the state that belongs to it.
     */
    private ?Context $memoContext = null;

    private ?DatabaseTransactionsState $memoState = null;

    public function __construct()
    {
        parent::__construct();

        foreach (self::COROUTINE_STATE as $property) {
            unset($this->$property);
        }
    }

    /**
     * @return mixed a reference into this coroutine's state, so that the inherited methods
     *   can modify its arrays in place
     */
    public function &__get(string $name)
    {
        $state = $this->state();

        return $state->$name;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->state()->$name = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->state()->$name);
    }

    public function __unset(string $name): void
    {
        unset($this->state()->$name);
    }

    private function state(): DatabaseTransactionsState
    {
        $context = coroutine_context();

        if ($this->memoContext === $context && $this->memoState !== null) {
            return $this->memoState;
        }

        $state = $context->findLocal(self::CTX_KEY);

        if (! $state instanceof DatabaseTransactionsState) {
            $state = new DatabaseTransactionsState();
            $context->set(self::CTX_KEY, $state, true);
        }

        $this->memoContext = $context;
        $this->memoState = $state;

        return $state;
    }
}
