<?php

namespace Spawn\Laravel\Session;

use Illuminate\Session\DatabaseSessionHandler;

/**
 * Session handler that uses a single upsert instead of INSERT + catch + UPDATE.
 *
 * In async environments multiple coroutines can race to write the same session:
 *   - Coroutine A sends the response (client gets the cookie) and is about to
 *     call terminate() / session write.
 *   - Before A's write completes, the client fires another request (coroutine B).
 *   - B reads the session (not found yet), then both A and B try to INSERT the
 *     same session ID → duplicate key.
 *
 * The stock DatabaseSessionHandler handles this with catch(QueryException)+UPDATE,
 * but that still requires a second round-trip and produces noisy error logs.
 *
 * This handler replaces the two-step INSERT/UPDATE with a single atomic upsert,
 * which is correct regardless of how many coroutines race to write the same ID.
 */
class AsyncDatabaseSessionHandler extends DatabaseSessionHandler
{
    /**
     * {@inheritdoc}
     *
     * Uses upsert() so concurrent writes to the same session ID are safe.
     */
    public function write($sessionId, $data): bool
    {
        $payload = $this->getDefaultPayload($data);
        $record  = array_merge(['id' => $sessionId], $payload);

        $this->getQuery()->upsert(
            [$record],
            ['id'],
            array_keys($payload),
        );

        return $this->exists = true;
    }
}
