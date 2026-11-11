<?php

namespace Spawn\Laravel\Http\Kernel;

use Spawn\Laravel\Foundation\RequestContext;

/**
 * Keeps the HTTP kernel's `$requestStartedAt` in the request's context instead of the kernel.
 *
 * The kernel is one object per worker. `handle()` writes the start time into it, `terminate()`
 * reads it to fire the `whenRequestLifecycleIsLongerThan()` handlers and then clears it, so under
 * concurrent serving a request that overlaps another overwrites the other's start, and the first
 * to finish clears it: the slower request's handlers never fire, and `requestStartedAt()` answers
 * another request's time.
 *
 * The declared property is unset, and every read and write the framework's own methods make
 * reaches `__get`/`__set` here, which answer from `RequestContext::current()`; nothing of the
 * kernel is copied. A kernel of the application's own adds this trait; the package swaps it in
 * for Laravel's stock kernel itself ({@see AsyncHttpKernel}).
 */
trait PerRequestStartTime
{
    /**
     * Route `$requestStartedAt` through the request's context from now on. Called once, after
     * construction; the package calls it on a kernel that uses this trait.
     */
    public function keepRequestStartPerRequest(): void
    {
        unset($this->requestStartedAt);
    }

    public function __get(string $name): mixed
    {
        if ($name === 'requestStartedAt') {
            return RequestContext::current()->find(self::requestStartKey());
        }

        trigger_error('Undefined property: '.static::class.'::$'.$name, E_USER_WARNING);

        return null;
    }

    public function __set(string $name, mixed $value): void
    {
        if ($name !== 'requestStartedAt') {
            $this->$name = $value;

            return;
        }

        if ($value === null) {
            RequestContext::current()->unset(self::requestStartKey());

            return;
        }

        RequestContext::current()->set(self::requestStartKey(), $value, replace: true);
    }

    public function __isset(string $name): bool
    {
        return $name === 'requestStartedAt' && RequestContext::current()->find(self::requestStartKey()) !== null;
    }

    public function __unset(string $name): void
    {
        if ($name === 'requestStartedAt') {
            RequestContext::current()->unset(self::requestStartKey());
        }
    }

    private static function requestStartKey(): string
    {
        return 'http.kernel.request_started_at';
    }
}
