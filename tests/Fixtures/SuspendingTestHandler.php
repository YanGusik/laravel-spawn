<?php

namespace Spawn\Laravel\Tests\Fixtures;

use Monolog\Handler\TestHandler;
use Monolog\LogRecord;

use function Async\suspend;

/**
 * A `TestHandler` whose write yields to the scheduler before it records, the way a write to
 * a file does: every other coroutine with a record to write runs while this one is inside
 * the handler.
 */
class SuspendingTestHandler extends TestHandler
{
    protected function write(LogRecord $record): void
    {
        suspend();

        parent::write($record);
    }
}
