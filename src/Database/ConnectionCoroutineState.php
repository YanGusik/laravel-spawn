<?php

namespace Spawn\Laravel\Database;

use PDO;

/**
 * What a Connection keeps per coroutine: the state set around a callback, which a sibling coroutine of
 * the same request must not see, and the handle of the coroutine's transaction.
 *
 * @internal
 */
final class ConnectionCoroutineState
{
    /** Whether statements are pretended; withoutPretending() clears it inside pretend(). */
    public bool $pretending = false;

    /**
     * The queries pretend() returns, null outside pretend().
     *
     * @var array{query: string, bindings: array, time: float|null, readWriteType: string|null}[]|null
     */
    public ?array $pretended = null;

    /** Set inside usingWritePdo(). */
    public bool $usingWritePdo = false;

    /** The handle the coroutine's transaction began on, null outside one. */
    public ?PDO $pinned = null;

    /** The server dropped the open transaction with its connection; cleared when the level returns to 0. */
    public bool $lost = false;

    /** @var 'read'|'write'|'direct'|null */
    public ?string $latestPdoType = null;
}
