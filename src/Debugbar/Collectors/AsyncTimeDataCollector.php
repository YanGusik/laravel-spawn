<?php

namespace Spawn\Laravel\Debugbar\Collectors;

use DebugBar\DataCollector\TimeDataCollector;

/**
 * Context-backed timeline collector. Measures accumulate across the request's
 * I/O yields, so their storage must be per-coroutine.
 * @see DelegatesToContext
 */
class AsyncTimeDataCollector extends TimeDataCollector
{
    use DelegatesToContext;

    public function __construct(?float $requestStartTime = null)
    {
        parent::__construct($requestStartTime);

        $this->bindContextDelegate('debugbar.collector.time', function (): TimeDataCollector {
            $delegate = new TimeDataCollector($this->getRequestStartTime());

            // Carry the shared configuration onto the per-request collector.
            if ($this->memoryMeasure) {
                $delegate->showMemoryUsage();
            }

            if ($this->mergeMeasures) {
                $delegate->mergeRepeatedMeasures();
            }

            return $delegate;
        });
    }

    /** @param TimeDataCollector $d */
    private function d(): TimeDataCollector
    {
        return $this->delegate();
    }

    public function startMeasure(string $name, ?string $label = null, ?string $collector = null, ?string $group = null): void
    {
        $this->d()->startMeasure($name, $label, $collector, $group);
    }

    public function hasStartedMeasure(string $name): bool
    {
        return $this->d()->hasStartedMeasure($name);
    }

    public function stopMeasure(string $name, array $params = []): void
    {
        $this->d()->stopMeasure($name, $params);
    }

    public function addMeasure(string $label, ?float $start = null, ?float $end = null, array $params = [], ?string $collector = null, ?string $group = null): void
    {
        $this->d()->addMeasure($label, $start, $end, $params, $collector, $group);
    }

    public function measure(string $label, \Closure $closure, ?string $collector = null, ?string $group = null): mixed
    {
        return $this->d()->measure($label, $closure, $collector, $group);
    }

    public function getMeasures(): array
    {
        return $this->d()->getMeasures();
    }

    public function getRequestEndTime(): ?float
    {
        return $this->d()->getRequestEndTime();
    }

    public function getRequestDuration(): float
    {
        return $this->d()->getRequestDuration();
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
