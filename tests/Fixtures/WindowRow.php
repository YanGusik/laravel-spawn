<?php

namespace Spawn\Laravel\Tests\Fixtures;

use Closure;
use Illuminate\Database\Eloquent\BroadcastsEvents;
use Illuminate\Database\Eloquent\Model;

/**
 * A timestamped model that broadcasts, with an accessor a test can hold open.
 *
 * The four Eloquent windows it serves are each read through a different path: timestamps on a
 * save, touching through `isIgnoringTouch()`, broadcasting through the gate at the top of
 * `broadcastIfBroadcastChannelsExistForEvent()`, and the missing-attribute switch through
 * `isset()` on an accessor. The accessor runs `$onRead` before it answers, which is how a test
 * suspends a coroutine inside `offsetExists()`.
 */
class WindowRow extends Model
{
    use BroadcastsEvents;

    /**
     * The events `broadcastOn()` was asked about, in order: the broadcast gate lets a call
     * through to `broadcastOn()` only while broadcasting is on.
     *
     * @var list<string>
     */
    public static array $broadcastAsked = [];

    /** Run inside the `slow` accessor, before it answers. */
    public ?Closure $onRead = null;

    protected $table = 'window_rows';

    protected $fillable = ['name'];

    public function getSlowAttribute(): string
    {
        if ($this->onRead !== null) {
            ($this->onRead)();
        }

        return 'slow';
    }

    /**
     * A row as a query would return it: `exists` and not recently created, which is the only
     * kind of model strict mode refuses a missing attribute on.
     */
    public static function stored(): self
    {
        return (new self())->newFromBuilder(['id' => 1, 'name' => 'stored']);
    }

    /**
     * @return list<string>
     */
    public function broadcastOn($event): array
    {
        self::$broadcastAsked[] = $event;

        return [];
    }

    /**
     * Whether a model event of this coroutine would reach `broadcastOn()`.
     */
    public function probeBroadcast(): bool
    {
        $before = count(self::$broadcastAsked);
        $this->broadcastIfBroadcastChannelsExistForEvent(null, 'probe');

        return count(self::$broadcastAsked) > $before;
    }
}
