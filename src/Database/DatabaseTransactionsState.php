<?php

namespace Spawn\Laravel\Database;

use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Support\Collection;

/**
 * The transaction records one coroutine holds on the transactions manager.
 *
 * A pooled PDO handle is bound to one coroutine, so the transaction a record stands for
 * is that coroutine's, and a callback registered against the record has to wait for that
 * coroutine's commit. The manager itself stays one object per worker, because every
 * connection is handed it once, when the connection is configured.
 */
final class DatabaseTransactionsState
{
    /** @var Collection<int, DatabaseTransactionRecord> */
    public Collection $committedTransactions;

    /** @var Collection<int, DatabaseTransactionRecord> */
    public Collection $pendingTransactions;

    /** @var array<string, DatabaseTransactionRecord|null> the innermost open transaction, by connection name */
    public array $currentTransaction = [];

    public function __construct()
    {
        $this->committedTransactions = new Collection();
        $this->pendingTransactions = new Collection();
    }
}
