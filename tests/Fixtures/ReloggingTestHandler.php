<?php

namespace Spawn\Laravel\Tests\Fixtures;

use Closure;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;

/**
 * A `TestHandler` that logs through the logger it serves, once per record it records: the
 * loop Monolog's detection exists for. `$relog` is the call that logs, set once the channel
 * that built the handler is resolved; while it is null the handler only records.
 */
class ReloggingTestHandler extends TestHandler
{
    public ?Closure $relog = null;

    protected function write(LogRecord $record): void
    {
        parent::write($record);

        if ($this->relog !== null) {
            ($this->relog)();
        }
    }
}
