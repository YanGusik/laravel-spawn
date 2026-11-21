<?php

namespace Spawn\Laravel\Database;

use Carbon\CarbonInterval;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Spawn\Laravel\Foundation\RequestContext;

use function Async\coroutine_context;

/**
 * Keeps the state Laravel writes on a Connection per request out of the Connection, which is one object
 * per worker shared by every coroutine of every request.
 *
 * Request scope, in ConnectionRequestState: the query log and whether it is on, the sticky "records
 * modified" flag, useWriteConnectionWhenReading(), the total query duration and which duration handlers
 * fired. The coroutines of one request share it: a child coroutine's write makes the request's reads
 * sticky, its queries land in the request's log. Coroutine scope, in ConnectionCoroutineState: pretend()
 * and usingWritePdo(), which hold around one callback, and a sibling coroutine running meanwhile must
 * write for real and read where it would.
 *
 * Outside async mode every method is Laravel's, on the connection's own properties. Before async mode
 * starts those properties are also what boot writes: a query log or write-on-read switched on at boot
 * seeds every request's state.
 *
 * Laravel reads some of the properties directly rather than through their accessors (logQuery(),
 * withoutPretending(), getReadPdo(), the whenQueryingForLongerThan() listener), so each such method is
 * overridden too. A Laravel upgrade that adds a direct read of one of these properties has to be carried
 * here.
 *
 * Used through CoroutineTransactions, which provides isAsyncMode() and the PDO accessors.
 */
trait PerRequestConnectionState
{
    /** Context key of this connection's ConnectionRequestState; an object, unlike a name it never collides. */
    private ?object $requestStateKey = null;

    /** Context key of this connection's ConnectionCoroutineState. */
    private ?object $coroutineStateKey = null;

    /** Whether the one listener running request-registered duration handlers is registered. */
    private bool $listensForRequestHandlers = false;

    abstract private function isAsyncMode(): bool;

    /**
     * A clone is another connection: sharing the keys would share the state with the original.
     */
    public function __clone()
    {
        parent::__clone();

        $this->requestStateKey = null;
        $this->coroutineStateKey = null;
        $this->listensForRequestHandlers = false;
    }

    private function requestState(): ConnectionRequestState
    {
        $key = $this->requestStateKey ??= new \stdClass();

        // Outside a request (a queue job, a command) the coroutine stands for one: a scope context there
        // could be the worker's own and would keep the state for good.
        $context = RequestContext::inRequest() ? RequestContext::current() : coroutine_context();

        $state = $context->find($key);

        if ($state === null) {
            $state = new ConnectionRequestState($this->loggingQueries, $this->readOnWriteConnection);
            $context->set($key, $state, replace: true);
        }

        return $state;
    }

    private function coroutineState(): ConnectionCoroutineState
    {
        $key = $this->coroutineStateKey ??= new \stdClass();
        $context = coroutine_context();

        $state = $context->find($key);

        if ($state === null) {
            $state = new ConnectionCoroutineState();
            $context->set($key, $state, replace: true);
        }

        return $state;
    }

    // ── pretend() ────────────────────────────────────────────────────────────

    /**
     * Run the callback without touching the database and return the queries it would have run. Only the
     * calling coroutine pretends; its queries are not added to the request's query log.
     *
     * @return array{query: string, bindings: array, time: float|null}[]
     */
    public function pretend(Closure $callback)
    {
        if (! $this->isAsyncMode()) {
            return parent::pretend($callback);
        }

        $state = $this->coroutineState();
        [$pretending, $outer] = [$state->pretending, $state->pretended];

        $state->pretending = true;
        $state->pretended = [];

        try {
            $callback($this);

            return $state->pretended;
        } finally {
            // A nested pretend()'s queries are the outer one's too.
            $state->pretended = $outer === null ? null : [...$outer, ...$state->pretended];
            $state->pretending = $pretending;
        }
    }

    public function withoutPretending(Closure $callback)
    {
        if (! $this->isAsyncMode()) {
            return parent::withoutPretending($callback);
        }

        $state = $this->coroutineState();

        if (! $state->pretending) {
            return $callback();
        }

        $state->pretending = false;

        try {
            return $callback();
        } finally {
            $state->pretending = true;
        }
    }

    public function pretending()
    {
        if (! $this->isAsyncMode()) {
            return parent::pretending();
        }

        return $this->coroutineState()->pretending;
    }

    protected function withFreshQueryLog($callback)
    {
        if (! $this->isAsyncMode()) {
            return parent::withFreshQueryLog($callback);
        }

        $state = $this->requestState();
        $logging = $state->logging;

        $state->logging = true;
        $state->log = [];

        try {
            return $callback();
        } finally {
            $state->logging = $logging;
        }
    }

    // ── The query log ────────────────────────────────────────────────────────

    /**
     * Count the query's time towards the request, dispatch QueryExecuted and record the query: in the
     * coroutine's pretend() buffer inside pretend(), in the request's log when logging is on.
     *
     * Checklist: Connection::logQuery(); a Laravel upgrade that changes it is carried here.
     */
    public function logQuery($query, $bindings, $time = null)
    {
        if (! $this->isAsyncMode()) {
            parent::logQuery($query, $bindings, $time);

            return;
        }

        $request = $this->requestState();
        $request->duration += $time ?? 0.0;

        $readWriteType = $this->latestReadWriteTypeUsed();

        $this->event(new QueryExecuted($query, $bindings, $time, $this, $readWriteType));

        $coroutine = $this->coroutineState();

        $query = $coroutine->pretending
            ? $this->queryGrammar?->substituteBindingsIntoRawSql($query, $bindings) ?? $query
            : $query;

        $entry = ['query' => $query, 'bindings' => $bindings, 'time' => $time, 'readWriteType' => $readWriteType];

        if ($coroutine->pretended !== null) {
            $coroutine->pretended[] = $entry;
        } elseif ($request->logging) {
            $request->log[] = $entry;
        }
    }

    public function getQueryLog()
    {
        return $this->isAsyncMode() ? $this->requestState()->log : parent::getQueryLog();
    }

    public function flushQueryLog()
    {
        if (! $this->isAsyncMode()) {
            parent::flushQueryLog();

            return;
        }

        $this->requestState()->log = [];
    }

    public function enableQueryLog()
    {
        if (! $this->isAsyncMode()) {
            parent::enableQueryLog();

            return;
        }

        $this->requestState()->logging = true;
    }

    public function disableQueryLog()
    {
        if (! $this->isAsyncMode()) {
            parent::disableQueryLog();

            return;
        }

        $this->requestState()->logging = false;
    }

    public function logging()
    {
        return $this->isAsyncMode() ? $this->requestState()->logging : parent::logging();
    }

    // ── Records modified and reads from the write PDO ──────────────────────

    public function hasModifiedRecords()
    {
        return $this->isAsyncMode() ? $this->requestState()->modified : parent::hasModifiedRecords();
    }

    public function recordsHaveBeenModified($value = true)
    {
        if (! $this->isAsyncMode()) {
            parent::recordsHaveBeenModified($value);

            return;
        }

        $state = $this->requestState();

        if (! $state->modified) {
            $state->modified = $value;
        }
    }

    public function setRecordModificationState(bool $value)
    {
        if (! $this->isAsyncMode()) {
            return parent::setRecordModificationState($value);
        }

        $this->requestState()->modified = $value;

        return $this;
    }

    public function forgetRecordModificationState()
    {
        if (! $this->isAsyncMode()) {
            parent::forgetRecordModificationState();

            return;
        }

        $this->requestState()->modified = false;
    }

    /** For the rest of the request; PreventStaleDatabaseReads sets it once per request. */
    public function useWriteConnectionWhenReading($value = true)
    {
        if (! $this->isAsyncMode()) {
            return parent::useWriteConnectionWhenReading($value);
        }

        $this->requestState()->readOnWrite = $value;

        return $this;
    }

    /** For the calling coroutine, while the callback runs. */
    public function usingWritePdo(Closure $callback): mixed
    {
        if (! $this->isAsyncMode()) {
            return parent::usingWritePdo($callback);
        }

        $state = $this->coroutineState();
        $usingWritePdo = $state->usingWritePdo;

        $state->usingWritePdo = true;

        try {
            return $callback($this);
        } finally {
            $state->usingWritePdo = $usingWritePdo;
        }
    }

    /** Whether reads of the calling coroutine go to the write PDO for a reason other than a transaction. */
    private function readsFromWritePdo(): bool
    {
        if ($this->coroutineState()->usingWritePdo) {
            return true;
        }

        $request = $this->requestState();

        return $request->readOnWrite || ($request->modified && $this->getConfig('sticky'));
    }

    // ── Query duration ───────────────────────────────────────────────────────

    public function totalQueryDuration()
    {
        return $this->isAsyncMode() ? $this->requestState()->duration : parent::totalQueryDuration();
    }

    public function resetTotalQueryDuration()
    {
        if (! $this->isAsyncMode()) {
            parent::resetTotalQueryDuration();

            return;
        }

        $this->requestState()->duration = 0.0;
    }

    /**
     * Call the handler once the queries of a request have taken longer than the threshold in total, once
     * per request. Registered before async mode (at boot), the handler serves every request; registered
     * inside a request, that request alone.
     *
     * @param  \DateTimeInterface|\Carbon\CarbonInterval|float|int  $threshold
     * @param  (callable(\Illuminate\Database\Connection, \Illuminate\Database\Events\QueryExecuted): mixed)  $handler
     */
    public function whenQueryingForLongerThan($threshold, $handler)
    {
        $threshold = $threshold instanceof DateTimeInterface
            ? $this->secondsUntil($threshold) * 1000
            : $threshold;

        $threshold = $threshold instanceof CarbonInterval
            ? $threshold->totalMilliseconds
            : $threshold;

        if ($this->isAsyncMode()) {
            $this->requestState()->handlers[] = ['threshold' => $threshold, 'handler' => $handler, 'has_run' => false];
            $this->listenForRequestHandlers();

            return;
        }

        // 'threshold' is what inheritQueryDurationHandlers() re-registers a handler with.
        $this->queryDurationHandlers[] = ['has_run' => false, 'handler' => $handler, 'threshold' => $threshold];

        $key = array_key_last($this->queryDurationHandlers);

        $this->listen(fn ($event) => $this->runQueryDurationHandler($key, $event));
    }

    private function runQueryDurationHandler(int $key, QueryExecuted $event): void
    {
        // Gone when the handler moved to the connection that replaced this one.
        if (! isset($this->queryDurationHandlers[$key])) {
            return;
        }

        ['handler' => $handler, 'threshold' => $threshold] = $this->queryDurationHandlers[$key];

        if (! $this->isAsyncMode()) {
            if (! $this->queryDurationHandlers[$key]['has_run'] && $this->totalQueryDuration() > $threshold) {
                $handler($this, $event);

                $this->queryDurationHandlers[$key]['has_run'] = true;
            }

            return;
        }

        $state = $this->requestState();

        // Marked before the call: a query the handler runs reaches this listener again.
        if (! isset($state->fired[$key]) && $state->duration > $threshold) {
            $state->fired[$key] = true;

            $handler($this, $event);
        }
    }

    /** One listener per connection, however many requests register handlers. */
    private function listenForRequestHandlers(): void
    {
        if ($this->listensForRequestHandlers || $this->events === null) {
            return;
        }

        $this->listensForRequestHandlers = true;

        $this->listen(function ($event) {
            if (! $this->isAsyncMode()) {
                return;
            }

            $state = $this->requestState();

            foreach ($state->handlers as $index => $entry) {
                if (! $entry['has_run'] && $state->duration > $entry['threshold']) {
                    $state->handlers[$index]['has_run'] = true;

                    ($entry['handler'])($this, $event);
                }
            }
        });
    }

    public function allowQueryDurationHandlersToRunAgain()
    {
        if (! $this->isAsyncMode()) {
            parent::allowQueryDurationHandlersToRunAgain();

            return;
        }

        $state = $this->requestState();
        $state->fired = [];

        foreach (array_keys($state->handlers) as $index) {
            $state->handlers[$index]['has_run'] = false;
        }
    }

    /** Whether handlers registered on this connection would be lost with it. */
    public function hasQueryDurationHandlers(): bool
    {
        return $this->queryDurationHandlers !== [];
    }

    /**
     * Take over the duration handlers of a connection this one replaces, and silence them there.
     *
     * The worker purges the connections boot opened, and a handler a service provider registered at boot
     * is bound to the purged one, which no query reaches again. Called before async mode starts.
     */
    public function inheritQueryDurationHandlers(Connection $from): void
    {
        foreach ($from->queryDurationHandlers as $entry) {
            if (isset($entry['threshold'])) {
                $this->whenQueryingForLongerThan($entry['threshold'], $entry['handler']);
            } else {
                error_log("[async] a query duration handler of connection '{$from->getName()}' was registered "
                    .'by a class other than the async connection and is dropped with that connection');
            }
        }

        $from->queryDurationHandlers = [];
    }
}
