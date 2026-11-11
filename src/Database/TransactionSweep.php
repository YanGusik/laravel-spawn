<?php

namespace Spawn\Laravel\Database;

use Illuminate\Database\ConnectionInterface;

/**
 * Rolls back what a request left open, once it has been answered and terminated.
 *
 * With the PDO pool on, the pool rolls a connection back when the coroutine that holds it ends,
 * so nothing reaches the next request; the sweep releases the locks earlier and says what
 * happened, which is otherwise a silent loss of writes. With the pool off the handle is shared,
 * and the sweep is all that keeps an open transaction from carrying into the next request.
 *
 * It sees the counters of the coroutine it runs in: run it in the coroutine that served the
 * request.
 */
final class TransactionSweep
{
    /**
     * Roll back every connection the database manager has opened that is still inside a
     * transaction, writing one line per connection to the log. A rollback that fails is
     * logged and the others go on.
     *
     * @param  mixed  $app  the container; nothing is done when it never resolved `db`
     * @return list<string>  the names of the connections that were rolled back
     */
    public static function afterRequest(mixed $app): array
    {
        if ($app === null || ! $app->resolved('db')) {
            return [];
        }

        return self::rollBackOpen($app->make('db')->getConnections());
    }

    /**
     * @param  iterable<ConnectionInterface>  $connections
     * @return list<string>  the names of the connections that were rolled back
     */
    public static function rollBackOpen(iterable $connections): array
    {
        $rolledBack = [];

        foreach ($connections as $connection) {
            $level = $connection->transactionLevel();

            if ($level <= 0) {
                continue;
            }

            $name = $connection->getName() ?? '(unnamed)';

            error_log("[async] the request left a transaction open on '{$name}' at level {$level}; rolled back");

            try {
                $connection->rollBack(0);
                $rolledBack[] = $name;
            } catch (\Throwable $e) {
                error_log("[async] rolling back '{$name}' failed: ".$e->getMessage());
            }
        }

        return $rolledBack;
    }
}
