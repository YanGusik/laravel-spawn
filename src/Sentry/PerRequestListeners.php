<?php

namespace Spawn\Laravel\Sentry;

use Illuminate\Events\Dispatcher;
use Spawn\Laravel\Foundation\RequestContext;

/**
 * sentry-laravel's event listeners, one copy per request.
 *
 * Each listener object is one per worker and keeps the spans it opened on a stack until the
 * closing event pops them: a database transaction, an HTTP client call, a cache command, a
 * queued job. Between the two the request does I/O, so concurrent requests pushed onto one
 * stack and popped each other's spans. Every request calls a copy of the boot-time object
 * with empty stacks; outside a request the boot-time object answers, as before.
 *
 * Only the listeners in the event dispatcher when the application has booted are covered;
 * Livewire's hooks are registered on Livewire's own manager and are not.
 *
 * @internal
 */
final class PerRequestListeners
{
    private const KEY = 'sentry.listeners';

    /** The state a copy starts without: the open spans and pushed scopes of another request. */
    private const STACKS = ['parentSpanStack', 'currentSpanStack', 'pushedScopeCount'];

    public static function wrap(Dispatcher $events): void
    {
        $wrapped = [];

        foreach ($events->getRawListeners() as $event => $listeners) {
            foreach ($listeners as $i => $listener) {
                if (self::isSentryListener($listener)) {
                    [$object, $method] = $listener;
                    $wrapped[$event][$i] = static fn (mixed ...$payload): mixed
                        => self::of($object)->{$method}(...$payload);
                }
            }
        }

        (function () use ($wrapped): void {
            foreach ($wrapped as $event => $listeners) {
                $this->listeners[$event] = array_replace($this->listeners[$event], $listeners);
            }
        })->call($events);
    }

    /** Drops the copies an earlier request made in the context being marked now. */
    public static function forget(): void
    {
        if (RequestContext::inRequest()) {
            RequestContext::current()->unset(self::KEY);
        }
    }

    /** The current request's copy of $listener; $listener itself outside a request. */
    public static function of(object $listener): object
    {
        if (! RequestContext::inRequest()) {
            return $listener;
        }

        $context = RequestContext::current();
        $copies = $context->find(self::KEY);

        if ($copies === null) {
            $copies = new \WeakMap();
            $context->set(self::KEY, $copies);
        }

        return $copies[$listener] ??= self::copy($listener);
    }

    private static function isSentryListener(mixed $listener): bool
    {
        return is_array($listener)
            && is_object($listener[0] ?? null)
            && is_string($listener[1] ?? null)
            && str_starts_with($listener[0]::class, 'Sentry\\Laravel\\');
    }

    private static function copy(object $listener): object
    {
        $copy = clone $listener;

        for ($class = new \ReflectionClass($copy); $class !== false; $class = $class->getParentClass()) {
            foreach (self::STACKS as $name) {
                if ($class->hasProperty($name)) {
                    $property = $class->getProperty($name);

                    if ($property->getDeclaringClass()->getName() === $class->getName()) {
                        $property->setValue($copy, $property->getDefaultValue());
                    }
                }
            }
        }

        return $copy;
    }
}
