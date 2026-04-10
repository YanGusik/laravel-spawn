<?php

namespace Spawn\Laravel\Foundation;

/**
 * String-backed enum keys for TrueAsync Context storage.
 *
 * Each case maps a Laravel service alias to a unique object key.
 * ScopedService::tryFrom($alias) resolves alias → key in one call.
 *
 * NOTE: 'db' is intentionally absent. DatabaseServiceProvider::boot() sets
 * Model::setConnectionResolver($app['db']) as a static property. A scoped
 * DatabaseManager tied to a specific scope context gets GC'd after the scope
 * finishes, leaving Model::$resolver pointing to a destroyed object → segfault.
 * Physical connection isolation is handled by PDO Pool at the C level instead.
 * Per-coroutine transaction counter isolation is handled by CoroutineTransactions trait
 * in Async*Connection subclasses registered via Connection::resolverFor().
 */
enum ScopedService: string
{
    case REQUEST     = 'request';
    case SESSION     = 'session';
    case AUTH        = 'auth';
    case AUTH_DRIVER = 'auth.driver';
    case COOKIE      = 'cookie';
}
