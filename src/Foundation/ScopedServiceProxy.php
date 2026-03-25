<?php

namespace TrueAsync\Laravel\Foundation;

/**
 * Proxy returned to Laravel Facades for scoped services.
 *
 * Facades cache the resolved instance in a static array. In a concurrent
 * environment this cache becomes shared across coroutines, causing state
 * leaks. Instead of clearing the cache on every request (which races with
 * other coroutines), we cache this proxy once. Every facade call goes through
 * __call → resolver → coroutine_context() → the correct per-coroutine instance.
 *
 * DI injection (make / resolve) bypasses offsetGet and gets the real instance
 * directly, so type-hints work correctly.
 */
class ScopedServiceProxy
{
    public function __construct(
        private readonly \Closure $resolver,
    ) {}

    public function __call(string $method, array $args): mixed
    {
        return ($this->resolver)()->$method(...$args);
    }

    public function __get(string $property): mixed
    {
        return ($this->resolver)()->$property;
    }

    public function __set(string $property, mixed $value): void
    {
        ($this->resolver)()->$property = $value;
    }

    public function __isset(string $property): bool
    {
        return isset(($this->resolver)()->$property);
    }
}
