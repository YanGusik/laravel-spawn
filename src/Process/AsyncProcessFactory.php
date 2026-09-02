<?php

namespace Spawn\Laravel\Process;

use Async\Context;
use Illuminate\Process\Factory;
use Spawn\Laravel\Foundation\RequestContext;

/**
 * A process factory whose fakes and recordings belong to the request that installed them.
 *
 * There is one factory per worker, and this package is what makes it so: Laravel registers no
 * binding for it, and with facade caching off an unbound root is rebuilt on every call and
 * loses a stray guard a provider switched on at boot. Every property the framework declares
 * on it — the fake handlers, the recording flag, the recorded pairs and the stray-process
 * guard — belongs to the request that wrote it, and the framework resets none of them between
 * requests. A `PendingProcess` copies the handlers when it is built and asks the factory
 * whether to record and whether to refuse a real process when it runs, so the object the
 * request reads at run time is this one.
 *
 * The state moves without touching the framework methods that write it: the declared
 * properties are removed from this object in the constructor, so every read and write falls
 * through to `__get()` and `__set()` and lands in a {@see ProcessState} taken from the
 * request's context. `__get()` returns by reference, which is what lets unmodified code run
 * `$this->recorded[] = [$process, $result]`.
 *
 * The request's context, not the coroutine's own: a fake installed by a request has to answer
 * the coroutines that request spawns, the way an HTTP stub does. Outside a request the
 * coroutine's own context answers, which is what `RequestContext` falls back to:
 * `Process::fake()` in a test or in an artisan command keeps working, and lasts as long as
 * the coroutine that installed it.
 */
class AsyncProcessFactory extends Factory
{
    /**
     * The properties of Factory that belong to one request.
     *
     * Checked against the framework by ProcessStateTest, which fails when an upgrade adds a
     * property that is in neither this list nor its list of worker-wide ones.
     */
    public const REQUEST_STATE = [
        'recording',
        'recorded',
        'fakeHandlers',
        'preventStrayProcesses',
    ];

    private const CTX_KEY = 'process.state';

    /**
     * The context the last state came from, held so that its object handle cannot be handed
     * to another context, and the state that belongs to it.
     */
    private ?Context $memoContext = null;

    private ?ProcessState $memoState = null;

    public function __construct()
    {
        foreach (self::REQUEST_STATE as $property) {
            unset($this->$property);
        }
    }

    /**
     * @return mixed a reference into this request's state, so that the inherited methods can
     *   modify its arrays in place
     */
    public function &__get(string $name)
    {
        $state = $this->state();

        return $state->$name;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->state()->$name = $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->state()->$name);
    }

    public function __unset(string $name): void
    {
        unset($this->state()->$name);
    }

    private function state(): ProcessState
    {
        $context = RequestContext::current();

        if ($this->memoContext === $context && $this->memoState !== null) {
            return $this->memoState;
        }

        $state = $context->findLocal(self::CTX_KEY);

        if (! $state instanceof ProcessState) {
            $state = new ProcessState();
            $context->set(self::CTX_KEY, $state, true);
        }

        $this->memoContext = $context;
        $this->memoState = $state;

        return $state;
    }
}
