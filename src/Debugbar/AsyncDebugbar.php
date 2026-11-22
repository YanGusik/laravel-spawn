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
use Spawn\Laravel\Foundation\RequestContext;

/**
 * One Debugbar instance per worker, but request state kept per request.
 *
 * The worker serves many coroutine-requests concurrently. Debugbar assumes
 * "one instance = one request", so we keep a single instance (one set of event
 * subscriptions, registered once) and move its per-request mutable state into
 * the request's context: accumulating collectors are context-backed, and the
 * transient injection flag is kept per request.
 */
class AsyncDebugbar extends LaravelDebugbar
{
    /**
     * What ResetDebugbar and a render set for one request on the one instance: the request,
     * whether the bar is on for it, the id it is filed under, the HTTP driver built from the
     * request, whether its storage is open, and whether its response was already injected — a
     * flag that spans reset() to handleResponse(), so a shared one would suppress another
     * request's injection. Each lives in the request's context; the declared properties are
     * unset so that every read of the parent classes reaches it. Each is only ever assigned
     * whole: a property the parent writes by key cannot be served this way.
     */
    private const PER_REQUEST = [
        'request', 'enabled', 'storageOpen', 'responseIsModified', 'requestId', 'httpDriver',
    ];

    private const CTX_PREFIX = 'debugbar.';

    /** Not one of PER_REQUEST: collect() writes into it by key, which an overloaded property cannot take. */
    private const CTX_DATA = 'debugbar.data';

    /**
     * The values outside any request (artisan, a queue job, boot): those the constructor left.
     *
     * @var array<string, mixed>
     */
    private array $outsideRequest = [];

    public function __construct(Application $app, Request $request)
    {
        parent::__construct($app, $request);

        foreach (self::PER_REQUEST as $property) {
            $this->outsideRequest[$property] = $this->$property;
            unset($this->$property);
        }

        // Accumulating collectors gather data across the request's I/O yields, so
        // their storage must be per-coroutine. These context-backed subclasses stay
        // real MessagesCollector/TimeDataCollector/ExceptionsCollector instances, so
        // Debugbar's instanceof checks and one-time subscriptions still work.
        $this->messagesCollector   = new AsyncMessagesCollector();
        $this->timeCollector       = new AsyncTimeDataCollector($this->timeCollector->getRequestStartTime());
        $this->exceptionsCollector = new AsyncExceptionsCollector();
    }

    /** Must not suspend: PHP guards __get against re-entry per object and property, not per coroutine. */
    public function __get(string $name): mixed
    {
        if (! in_array($name, self::PER_REQUEST, true)) {
            throw new \LogicException('Undefined property '.static::class.'::$'.$name);
        }

        if (! RequestContext::inRequest()) {
            return $this->outsideRequest[$name];
        }

        $context = RequestContext::current();
        $key = self::CTX_PREFIX.$name;

        return $context->has($key) ? $context->get($key) : $this->outsideRequest[$name];
    }

    public function __set(string $name, mixed $value): void
    {
        if (! in_array($name, self::PER_REQUEST, true)) {
            throw new \LogicException('Undefined property '.static::class.'::$'.$name);
        }

        if (! RequestContext::inRequest()) {
            $this->outsideRequest[$name] = $value;

            return;
        }

        RequestContext::current()->set(self::CTX_PREFIX.$name, $value, replace: true);
    }

    public function __isset(string $name): bool
    {
        return in_array($name, self::PER_REQUEST, true) && $this->__get($name) !== null;
    }

    /**
     * The renderer is one per worker and keeps the nonce it was given; the parent sets one only
     * while none is set, which leaves a second request rendering with the first one's nonce and
     * its own Content-Security-Policy refusing the bar. Rendering does not suspend.
     */
    public function injectDebugbar(SymfonyResponse $response): void
    {
        $this->getJavascriptRenderer()->setCspNonce($this->detectCspNonce());

        parent::injectDebugbar($response);
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
        RequestContext::current()->set(self::CTX_DATA, $data, replace: true);

        return $data;
    }

    public function getData(): array
    {
        $data = RequestContext::current()->find(self::CTX_DATA);

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
