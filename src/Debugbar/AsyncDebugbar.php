<?php

namespace Spawn\Laravel\Debugbar;

use DebugBar\DebugBar;
use Fruitcake\LaravelDebugbar\LaravelDebugbar;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Spawn\Laravel\Debugbar\Collectors\AsyncExceptionsCollector;
use Spawn\Laravel\Debugbar\Collectors\AsyncMessagesCollector;
use Spawn\Laravel\Debugbar\Collectors\AsyncTimeDataCollector;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

use function Async\current_context;

/**
 * One Debugbar instance per worker, but request state kept per-coroutine.
 *
 * The worker serves many coroutine-requests concurrently. Debugbar assumes
 * "one instance = one request", so we keep a single instance (one set of event
 * subscriptions, registered once) and move its per-request mutable state into
 * current_context(): accumulating collectors are context-backed, and the
 * transient injection flag is hydrated per context.
 */
class AsyncDebugbar extends LaravelDebugbar
{
    private const CTX_RESPONSE_MODIFIED = 'debugbar.responseIsModified';
    private const CTX_DATA = 'debugbar.data';

    public function __construct(Application $app, Request $request)
    {
        parent::__construct($app, $request);

        // Accumulating collectors gather data across the request's I/O yields, so
        // their storage must be per-coroutine. These context-backed subclasses stay
        // real MessagesCollector/TimeDataCollector/ExceptionsCollector instances, so
        // Debugbar's instanceof checks and one-time subscriptions still work.
        $this->messagesCollector   = new AsyncMessagesCollector();
        $this->timeCollector       = new AsyncTimeDataCollector($this->timeCollector->getRequestStartTime());
        $this->exceptionsCollector = new AsyncExceptionsCollector();
    }

    public function handleResponse(Request $request, SymfonyResponse $response): SymfonyResponse
    {
        // responseIsModified guards double-injection of one response. It spans the
        // gap between reset() and here, so it must be per-context — otherwise a flag
        // set while serving one request would suppress injection in another.
        // handleResponse itself does not yield, so hydrating around the parent call is safe.
        $this->responseIsModified = (bool) current_context()->find(self::CTX_RESPONSE_MODIFIED);

        $result = parent::handleResponse($request, $response);

        current_context()->set(self::CTX_RESPONSE_MODIFIED, $this->responseIsModified);

        return $result;
    }

    /**
     * No persistent storage under async serving. collect() saves to storage
     * inline, and that I/O yields the coroutine — letting a concurrently-served
     * request overwrite the shared collected snapshot before it is rendered.
     * The inline widget needs no storage; only the historical "open handler" does.
     */
    protected function selectStorage(DebugBar $debugbar): void
    {
        // intentionally left blank — keep $this->storage null
    }

    public function isStorageOpen(Request $request): bool
    {
        return false;
    }

    /**
     * The collected snapshot is per-request. The base class caches it in a shared
     * $this->data and getData() reuses it while non-null — so without this a
     * concurrently-served request would render another request's snapshot. With
     * storage disabled collect() no longer yields, so it is safe to key by context.
     */
    public function collect(): array
    {
        $data = parent::collect();
        current_context()->set(self::CTX_DATA, $data);

        return $data;
    }

    public function getData(): array
    {
        $data = current_context()->find(self::CTX_DATA);

        if ($data === null) {
            $data = $this->collect();
        }

        return $data;
    }

    /**
     * The query collector is created inside its provider (not via a Debugbar
     * getter), so swap the provider for one that instantiates the context-backed
     * AsyncQueryCollector.
     */
    protected function registerCollectorProviders(array $providers): void
    {
        if (isset($providers['db'])) {
            $providers['db'] = \Spawn\Laravel\Debugbar\CollectorProviders\AsyncDatabaseCollectorProvider::class;
        }

        parent::registerCollectorProviders($providers);
    }
}
