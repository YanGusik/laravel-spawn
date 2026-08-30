<?php

namespace Spawn\Laravel\Http\Client;

use Async\Context;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory;
use Spawn\Laravel\Foundation\RequestContext;

/**
 * An HTTP client factory whose stubs and recordings belong to the request that installed
 * them.
 *
 * There is one factory per worker, and this package is what makes it so: Laravel registers no
 * binding for it, and with facade caching off an unbound root is rebuilt on every call and
 * loses the global middleware and global options a provider set at boot. What must not be
 * shared is the other half of the object — the stub callbacks, the recorded pairs, the
 * response sequences and the stray-request guard — each of which belongs to the request that
 * installed it, and none of which the framework resets between requests.
 *
 * The state moves without touching the sixteen framework methods that write it: the declared
 * properties are removed from this object in the constructor,
 * so every read and write falls through to `__get()` and `__set()` and lands in a
 * {@see HttpClientState} taken from the request's context. `__get()` returns by reference,
 * which is what lets unmodified code run `$this->recorded[] = [$request, $response]`.
 *
 * Outside a request the coroutine's own context answers, which is what `RequestContext`
 * falls back to: `Http::fake()` in a test or in an artisan command keeps working, and lasts
 * as long as the coroutine that installed it.
 */
class AsyncHttpFactory extends Factory
{
    /**
     * The properties of Factory that belong to one request.
     *
     * Checked against the framework by HttpClientStateTest, which fails when an upgrade adds
     * a property that is in neither this list nor its list of worker-wide ones.
     */
    public const REQUEST_STATE = [
        'stubCallbacks',
        'recording',
        'recorded',
        'responseSequences',
        'preventStrayRequests',
        'allowedStrayRequestUrls',
    ];

    private const CTX_KEY = 'http-client.state';

    /**
     * The context the last state came from, held so that its object handle cannot be handed
     * to another context, and the state that belongs to it.
     */
    private ?Context $memoContext = null;

    private ?HttpClientState $memoState = null;

    public function __construct(?Dispatcher $dispatcher = null)
    {
        parent::__construct($dispatcher);

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

    private function state(): HttpClientState
    {
        $context = RequestContext::current();

        if ($this->memoContext === $context && $this->memoState !== null) {
            return $this->memoState;
        }

        $state = $context->findLocal(self::CTX_KEY);

        if (! $state instanceof HttpClientState) {
            $state = new HttpClientState();
            $context->set(self::CTX_KEY, $state, true);
        }

        $this->memoContext = $context;
        $this->memoState = $state;

        return $state;
    }
}
