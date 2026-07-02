<?php

namespace Spawn\Laravel\Debugbar\CollectorProviders;

use Fruitcake\LaravelDebugbar\CollectorProviders\AbstractCollectorProvider;
use Fruitcake\LaravelDebugbar\DataCollector\QueryCollector;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Routing\Router;
use Spawn\Laravel\Debugbar\Collectors\AsyncQueryCollector;

/**
 * Registers a context-backed query collector. Lean variant of Debugbar's stock
 * DatabaseCollectorProvider: covers the common options (params, source, limits,
 * timeline, transactions). Explain/show-result/memory-usage are intentionally
 * omitted (they depend on storage, which async serving disables anyway).
 */
class AsyncDatabaseCollectorProvider extends AbstractCollectorProvider
{
    public function __invoke(Dispatcher $events, Router $router, array $options): void
    {
        $collector = new AsyncQueryCollector();

        $timeCollector = ($options['timeline'] ?? false) ? $this->debugbar->getTimeCollector() : null;

        // Applied to every per-request delegate, so each renders SQL identically.
        $collector->configureDelegate(function (QueryCollector $c) use ($options, $router, $timeCollector): void {
            if ($timeCollector !== null) {
                $c->setTimeDataCollector($timeCollector);
            }

            $c->setLimits($options['soft_limit'] ?? 100, $options['hard_limit'] ?? 500);
            $c->setDurationBackground($options['duration_background'] ?? true);

            if ($options['with_params'] ?? true) {
                $c->setRenderSqlWithParams(true);
            }

            if ($backtrace = ($options['backtrace'] ?? true)) {
                $c->setFindSource($backtrace, $router->getMiddleware());
            }

            if ($excludePaths = ($options['exclude_paths'] ?? [])) {
                $c->mergeExcludePaths($excludePaths);
            }

            if ($excludeBacktracePaths = ($options['backtrace_exclude_paths'] ?? [])) {
                $c->mergeBacktraceExcludePaths($excludeBacktracePaths);
            }
        });

        $this->addCollector($collector);

        $threshold     = $options['slow_threshold'] ?? false;
        $onlyThreshold = $options['only_slow_queries'] ?? true;

        $events->listen(function (QueryExecuted $query) use ($collector, $threshold, $onlyThreshold): void {
            if (! $this->debugbar->shouldCollect('db', true) || ! $this->debugbar->isEnabled()) {
                return;
            }

            if (! $onlyThreshold || ! $threshold || $query->time > $threshold) {
                $collector->addQuery($query);
            }
        });

        $events->listen(TransactionBeginning::class, fn ($t) => $collector->collectTransactionEvent('Begin Transaction', $t->connection));
        $events->listen(TransactionCommitted::class, fn ($t) => $collector->collectTransactionEvent('Commit Transaction', $t->connection));
        $events->listen(TransactionRolledBack::class, fn ($t) => $collector->collectTransactionEvent('Rollback Transaction', $t->connection));
    }
}
