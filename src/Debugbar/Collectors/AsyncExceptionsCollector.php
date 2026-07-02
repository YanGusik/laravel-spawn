<?php

namespace Spawn\Laravel\Debugbar\Collectors;

use DebugBar\DataCollector\ExceptionsCollector;

/**
 * Context-backed exceptions collector. One shared instance, per-coroutine data.
 * collectWarnings()'s global error handler stays on this shared instance; the
 * warnings it reports route to the current coroutine via addWarning() → delegate().
 * @see DelegatesToContext
 */
class AsyncExceptionsCollector extends ExceptionsCollector
{
    use DelegatesToContext;

    public function __construct(string $name = 'exceptions', string $icon = 'bug')
    {
        parent::__construct($name, $icon);
        $this->bindContextDelegate('debugbar.collector.' . $name, fn () => new ExceptionsCollector($name, $icon));
    }

    /** @param ExceptionsCollector $d */
    private function d(): ExceptionsCollector
    {
        return $this->delegate();
    }

    public function addException(\Throwable $e): void
    {
        $this->d()->addException($e);
    }

    public function addThrowable(\Throwable $e): void
    {
        $this->d()->addThrowable($e);
    }

    public function addWarning(int $errno, string $errstr, string $errfile = '', int $errline = 0): void
    {
        $this->d()->addWarning($errno, $errstr, $errfile, $errline);
    }

    public function getExceptions(): array
    {
        return $this->d()->getExceptions();
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
