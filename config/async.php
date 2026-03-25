<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Scoped Services
    |--------------------------------------------------------------------------
    |
    | Services listed here will be resolved per-coroutine instead of shared
    | as singletons. Use this for third-party packages that hold request state.
    |
    | Example:
    |   \SomePackage\Manager::class,
    |
    */

    'scoped_services' => [],

    /*
    |--------------------------------------------------------------------------
    | Database Connection Pool
    |--------------------------------------------------------------------------
    |
    | When the async server is running, each coroutine gets its own
    | DatabaseManager instance. The underlying PDO connections are managed
    | by TrueAsync's built-in pool, so physical connections are reused
    | across coroutines instead of creating a new one per request.
    |
    */

    'db_pool' => [
        'enabled' => true,
        'min'     => 2,
        'max'     => 10,
        'healthcheck_interval' => 30, // seconds, 0 = disabled
    ],

];
