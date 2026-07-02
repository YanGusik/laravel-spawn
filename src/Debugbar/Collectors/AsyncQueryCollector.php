<?php

namespace Spawn\Laravel\Debugbar\Collectors;

use Fruitcake\LaravelDebugbar\DataCollector\QueryCollector;
use Illuminate\Database\Events\QueryExecuted;

/**
 * Context-backed query collector. Queries accumulate across the request's I/O
 * (DB) yields, so their storage must be per-coroutine.
 *
 * The per-request delegate is a fresh QueryCollector, so it needs the same
 * configuration the provider applied. Instead of reflecting over private state,
 * the provider hands us a configurator closure that the factory runs on each
 * new delegate.
 * @see DelegatesToContext
 */
class AsyncQueryCollector extends QueryCollector
{
    use DelegatesToContext;

    /** @var callable(QueryCollector):void */
    private $configurator;

    public function __construct()
    {
        // QueryCollector has no constructor to call; this shared wrapper only routes
        // to per-context delegates, so its own base collector state stays unused.
        $this->configurator = static fn (QueryCollector $c): null => null;

        $this->bindContextDelegate('debugbar.collector.queries', function (): QueryCollector {
            $delegate = new QueryCollector();
            ($this->configurator)($delegate);

            return $delegate;
        });
    }

    /** @param callable(QueryCollector):void $configurator */
    public function configureDelegate(callable $configurator): void
    {
        $this->configurator = $configurator;
    }

    private function d(): QueryCollector
    {
        return $this->delegate();
    }

    public function addQuery(QueryExecuted $query): void
    {
        $this->d()->addQuery($query);
    }

    public function collectTransactionEvent(string $event, mixed $connection): void
    {
        $this->d()->collectTransactionEvent($event, $connection);
    }

    public function collect(): array
    {
        return $this->d()->collect();
    }

    public function reset(): void
    {
        $this->d()->reset();
    }
}
