<?php

namespace Spawn\Laravel\Sentry;

use Sentry\SentrySdk;

/**
 * The request boundary of Sentry's runtime context, for the servers.
 *
 * start() before the kernel handles the request; end() after terminate(), since the tracing
 * transaction finishes and the events flush in terminate(). Both do nothing without Sentry.
 *
 * @internal
 */
final class SentryRequest
{
    public static function start(): void
    {
        if (class_exists(SentrySdk::class) && method_exists(SentrySdk::class, 'startContext')) {
            RequestRuntimeContextStorage::forgetReleased();
            SentrySdk::startContext();
        }
    }

    /** Flushes what the request buffered. */
    public static function end(): void
    {
        if (class_exists(SentrySdk::class) && method_exists(SentrySdk::class, 'endContext')) {
            SentrySdk::endContext();
        }
    }
}
