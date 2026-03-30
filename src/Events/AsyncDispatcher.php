<?php

namespace Spawn\Laravel\Events;

use Illuminate\Events\Dispatcher;

use function Async\current_context;

/**
 * Coroutine-safe Event Dispatcher.
 *
 * Before bootCompleted(): behaves like stock Dispatcher.
 * After bootCompleted(): defer() state is stored per-coroutine in current_context().
 *
 * The listener registry ($listeners, $wildcards, $wildcardsCache) remains shared —
 * listeners are the same for all requests, only defer state differs per coroutine.
 */
class AsyncDispatcher extends Dispatcher
{
    private const CTX_KEY = 'events.defer';

    private bool $async = false;

    public function bootCompleted(): void
    {
        $this->async = true;
    }

    public function defer(callable $callback, ?array $events = null)
    {
        if (! $this->async) {
            return parent::defer($callback, $events);
        }

        $ctx = current_context();
        $prev = $ctx->find(self::CTX_KEY);

        $ctx->set(self::CTX_KEY, [
            'deferring' => true,
            'deferred' => [],
            'events' => $events,
        ], replace: true);

        try {
            $result = $callback();

            $state = $ctx->find(self::CTX_KEY);
            $ctx->set(self::CTX_KEY, array_merge($state, ['deferring' => false]), replace: true);

            foreach ($state['deferred'] as $args) {
                $this->dispatch(...$args);
            }

            return $result;
        } finally {
            $ctx->set(self::CTX_KEY, $prev, replace: true);
        }
    }

    public function dispatch($event, $payload = [], $halt = false)
    {
        if ($this->async && $this->shouldDeferForContext($event)) {
            $ctx = current_context();
            $state = $ctx->find(self::CTX_KEY);
            $state['deferred'][] = func_get_args();
            $ctx->set(self::CTX_KEY, $state, replace: true);
            return null;
        }

        return parent::dispatch($event, $payload, $halt);
    }

    private function shouldDeferForContext($event): bool
    {
        $state = current_context()->find(self::CTX_KEY);

        if (! $state || ! $state['deferring']) {
            return false;
        }

        if (is_null($state['events'])) {
            return true;
        }

        $eventName = is_object($event) ? get_class($event) : (string) $event;

        return in_array($eventName, $state['events']);
    }
}
