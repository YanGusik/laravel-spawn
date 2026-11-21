<?php

namespace Spawn\Laravel\Database;

/**
 * What a Connection keeps per request: the coroutines of one request share it, another request never
 * sees it. PerRequestConnectionState keeps one per connection in the request's context.
 *
 * @internal
 */
final class ConnectionRequestState
{
    /** @var array{query: string, bindings: array, time: float|null, readWriteType: string|null}[] */
    public array $log = [];

    /** Set by the first write: reads go to the write PDO for the rest of the request when `sticky` is on. */
    public bool $modified = false;

    /** Milliseconds spent in queries so far. */
    public float $duration = 0.0;

    /** @var array<int, true> Keys of the connection's queryDurationHandlers that fired in this request. */
    public array $fired = [];

    /**
     * Duration handlers registered inside this request: they end with it.
     *
     * @var list<array{threshold: float|int, handler: callable, has_run: bool}>
     */
    public array $handlers = [];

    /**
     * @param  bool  $logging  Seeded from the connection, so a log switched on at boot is on in every request.
     * @param  bool  $readOnWrite  Seeded the same way, for useWriteConnectionWhenReading() at boot.
     */
    public function __construct(
        public bool $logging,
        public bool $readOnWrite,
    ) {
    }
}
