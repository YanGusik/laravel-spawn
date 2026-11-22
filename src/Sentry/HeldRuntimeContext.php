<?php

namespace Spawn\Laravel\Sentry;

use Sentry\State\RuntimeContext;

/**
 * A request's Sentry runtime context, and whether the request has ended it.
 *
 * @internal
 */
final class HeldRuntimeContext
{
    public bool $released = false;

    public function __construct(public readonly RuntimeContext $context)
    {
    }
}
