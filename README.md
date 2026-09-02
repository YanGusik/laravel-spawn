<p align="center"><img width="335" height="61" src="/logo.svg" alt="Logo Laravel Spawn"></p>

Laravel adapter for [PHP TrueAsync](https://github.com/true-async) — a PHP fork with a native coroutine scheduler and async I/O. Think Laravel Octane, but instead of Swoole or RoadRunner the runtime is TrueAsync.

**One worker. Many requests. Zero threads.**
Each HTTP request runs in its own coroutine with isolated state — no shared memory, no leaks between requests.

---

## How it works

- Each request = a separate coroutine with its own `Scope`
- Request-scoped services (`auth`, `session`, `cookie`) are isolated via `coroutine_context()` and `request_context()` (if use True Async Server)
- PDO Pool transparently gives each coroutine its own database connection and returns it when the coroutine ends
- No container cloning — isolation is handled at the coroutine level, not by copying the entire app

---

## Requirements

- PHP TrueAsync fork 8.6+
- Laravel 12+
- For FrankenPHP mode: `trueasync/php-true-async:latest-frankenphp` Docker image

---

## Installation

```bash
composer require yangusik/laravel-spawn
```

**Via git repository:**

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/yangusik/laravel-spawn"
    }
],
"require": {
    "yangusik/laravel-spawn": "dev-master"
}
```

**Via local path:**

```json
"repositories": [
    {
        "type": "path",
        "url": "../laravel-true-async"
    }
],
"require": {
    "yangusik/laravel-spawn": "*"
}
```

Then run `composer update`.

The service provider is auto-discovered by Laravel.

**Replace the Application class in `bootstrap/app.php`:**

```diff
- $app = new Illuminate\Foundation\Application(
+ $app = new Spawn\Laravel\Foundation\AsyncApplication(
    $_ENV['APP_BASE_PATH'] ?? dirname(__DIR__)
);
```

This is required for per-coroutine isolation of `auth`, `session`, and `request`. Without it the service adapters register correctly but state isolation does not work.

Publish the config:

```bash
php artisan vendor:publish --tag=async-config
```

---

## Servers

### True Async Server

Production-ready adapter using [True Async Server](https://true-async.github.io/en/docs/server/) in async worker mode.

Check `config/async.php`!

```bash
php artisan async:serve --host=0.0.0.0 --port=8080 --workers=1
```

### Dev server

Simple TCP socket server for local development. Analogous to `php artisan serve`.

```bash
php artisan async:dev --host=0.0.0.0 --port=8080
```

### FrankenPHP

Production-ready adapter using [FrankenPHP](https://frankenphp.dev) in async worker mode.
Requires the `trueasync/php-true-async:latest-frankenphp` Docker image.

```bash
php artisan async:franken --host=0.0.0.0 --port=8080 --workers=1 --buffer=1
```

### A worker of your own

Any process that serves requests as coroutines calls one method to make the adapters
per-request:

```php
\Spawn\Laravel\Foundation\WorkerBootstrap::run($app);
```

It applies the `async.db_pool` options and purges the connections bootstrap already opened,
configures the Redis pool, tells the adapters that boot is over and switches the application
into async mode — the three servers above do nothing else to start. `TrueAsyncServer::initializeApp($app)` is the
same call for a thread of an application that has its own server.

An adapter that is never told boot is over is a no-op that reports nothing: `AsyncViewFactory`
keeps answering from its process-wide state, so two coroutines rendering Blade share
`@section` and `@push`. Under php-fpm that is the correct behaviour, since one process serves
one request; under coroutines it is state shared between requests.

---

## Docker quick start

### TrueAsyncServer (better)

```yaml
services:
  app:
    image: trueasync/php-true-async:latest
    working_dir: /app
    command: php artisan async:serve # check config/async.php!
    ports:
      - "8080:8080"
    volumes:
      - .:/app
    environment:
      APP_ENV: local
      DB_CONNECTION: pgsql
      DB_HOST: db
      DB_PORT: 5432
      DB_DATABASE: laravel
      DB_USERNAME: laravel
      DB_PASSWORD: secret
```

### Dev server

```yaml
services:
  app:
    image: trueasync/php-true-async:latest
    working_dir: /app
    command: php artisan async:dev --host=0.0.0.0 --port=8080
    ports:
      - "8080:8080"
    volumes:
      - .:/app
    environment:
      APP_ENV: local
      DB_CONNECTION: pgsql
      DB_HOST: db
      DB_PORT: 5432
      DB_DATABASE: laravel
      DB_USERNAME: laravel
      DB_PASSWORD: secret
```

### FrankenPHP

```yaml
services:
  app:
    image: trueasync/php-true-async:latest-frankenphp
    working_dir: /app
    command: php artisan async:franken --host=0.0.0.0 --port=8080 --workers=1 --buffer=1
    ports:
      - "8080:8080"
    volumes:
      - .:/app
    environment:
      APP_ENV: local
      DB_CONNECTION: pgsql
      DB_HOST: db
      DB_PORT: 5432
      DB_DATABASE: laravel
      DB_USERNAME: laravel
      DB_PASSWORD: secret
```

---

## Per-request services

A singleton lives as long as the worker and is shared by every request the worker is
serving at that moment, so a service holding request state has to be declared
per-request. The container gives every request its own instance, built on first resolve
and kept in that request's coroutine context; its facade resolves per request as well.

Three registrations declare one, and the container treats them alike.

**Laravel's own `scoped()`** — the same call Octane packages already use:

```php
$this->app->scoped(TenantContext::class, fn ($app) => new TenantContext($app['request']));
```

**The config list**, for a package whose registration you do not control:

```php
// config/async.php
'scoped_services' => [
    \SomePackage\Manager::class,
],
```

**`scopedSingleton()`**, when a shared binding has to stay in place: the factory here is
used for the per-request build only, and whatever bootstrap resolved through the original
binding stays reachable through `scopedPrototype()`. That is how this package scopes `url`
without losing `URL::forceScheme()` and the other setters a provider called at boot.

```php
$this->app->scopedSingleton('url', function ($app) {
    $url = clone $app->scopedPrototype('url');
    $url->setRequest($app->make('request'));

    return $url;
});
```

**Per-request does not fit everything.** A service the framework or a package captures —
kept in a static, taken in another singleton's constructor — must stay one object, or the
capture pins one request's copy for the life of the worker. The view factory is the case:
templates receive it as `$__env`, `Component::$factory` caches it, `MailManager` keeps it.
It stays shared, and its render state moves into the request instead.

**Boot-time configuration does not follow on its own.** A provider calling
`Auth::extend()` or `Session::extend()` configures the boot-time instance, and a fresh
per-request instance starts without it. Register a seeder to carry it across:

```php
$this->app->scopedSeeder('session', function ($fresh, $prototype) {
    // copy what the provider registered on $prototype onto $fresh
});
```

Turn on `async.diagnostics` to have the worker report, at start-up, every per-request
service that bootstrap configured and no seeder carries over — and every shared service
holding an object that belongs to one request.

## Configuration

`config/async.php`:

If you use TrueAsyncServer, pls read docs: [Configuration](https://true-async.github.io/en/docs/server/configuration.html)

```php
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

    /*
    |--------------------------------------------------------------------------
    | Async Server
    |--------------------------------------------------------------------------
    |
    | Configuration for the TrueAsync HTTP server. The server can listen
    | on multiple interfaces and protocols simultaneously.
    |
    */

    'server' => [

        /*
        |--------------------------------------------------------------------------
        | Listeners
        |--------------------------------------------------------------------------
        |
        | Define the TCP interfaces the server should bind to. Each listener
        | can use a specific HTTP protocol version and optional TLS.
        |
        | Available protocols: auto, http1, http2, http3
        |
        */

        'listeners' => [
            [
                'host'     => env('ASYNC_HOST', '0.0.0.0'),
                'port'     => (int) env('ASYNC_PORT', 8080),
                'tls'      => (bool) env('ASYNC_TLS', false),
                'protocol' => env('ASYNC_PROTOCOL', 'auto'), // auto, http1, http2, http3
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Workers
        |--------------------------------------------------------------------------
        |
        | Number of worker threads for the multi-threaded server command
        | (async:workers). 0 means auto-detect based on CPU core count.
        |
        */

        'workers' => (int) env('ASYNC_WORKERS', 0),

        /*
        |--------------------------------------------------------------------------
        | TLS Certificates
        |--------------------------------------------------------------------------
        |
        | Absolute paths to the TLS certificate and private key. Used when
        | at least one listener has 'tls' => true.
        |
        */

        'tls_cert' => env('ASYNC_TLS_CERT', '/certs/server.crt'),
        'tls_key'  => env('ASYNC_TLS_KEY', '/certs/server.key'),

        /*
        |--------------------------------------------------------------------------
        | Socket & HTTP Settings
        |--------------------------------------------------------------------------
        */

        'backlog'       => (int) env('ASYNC_BACKLOG', 2048),
        'compression'   => (bool) env('ASYNC_COMPRESSION', true),
        'max_body_size' => (int) env('ASYNC_MAX_BODY_SIZE', 32 * 1024 * 1024),
        'read_timeout'  => (int) env('ASYNC_READ_TIMEOUT', 60),
        'write_timeout' => (int) env('ASYNC_WRITE_TIMEOUT', 60),

        /*
        |--------------------------------------------------------------------------
        | Static File Handlers
        |--------------------------------------------------------------------------
        |
        | Map URL prefixes to local directories for direct static file serving
        | bypassing the Laravel kernel. Nothing is served that is not listed
        | here: an empty list means every request reaches the kernel.
        |
        | The first entry is Laravel's own document root, the files a web server
        | would have served before PHP ever saw the request. Behind nginx they
        | never reach this process; served by this process they have to be named.
        | 'on_missing' => 'next' is what makes a mount at "/" usable — a path
        | with no file behind it goes on to the kernel — and 'hide' keeps
        | public/index.php off the wire, since the front controller's source is
        | not a response. Delete the entry to serve nothing from disk.
        |
        | Requires true_async_server 0.14.0 for the mount at "/"; on an older
        | build it is skipped with a line on stderr.
        |
        | Example of a second mount:
        |   [
        |       'prefix' => '/assets/',
        |       'root'   => public_path('assets'),
        |       'etag'   => true,
        |       'hide'   => ['*.php'],
        |       'precompressed' => ['br', 'gzip'],
        |   ]
        |
        */

        'static_handlers' => [
            [
                'prefix'     => '/',
                'root'       => public_path(),
                'etag'       => true,
                'on_missing' => 'next',
                'hide'       => ['*.php'],
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | Hot Reload
        |--------------------------------------------------------------------------
        |
        | Configure hot reload for the TrueAsync server during development.
        | When enabled, the server watches the configured paths and automatically
        | reloads whenever a file changes.
        |
        | Paths are relative to the application base path.
        |
        */

        'hot_reload_paths' => [
            'app',
            'bootstrap',
            'config',
            'resources',
            'routes',
        ],
    ],

];
```

---

## Benchmarks (Obsolete use TrueAsyncServer)

Check results in [HttpArena](https://www.http-arena.com/leaderboard/#v=composite&res=mem)

**Load:** 840 req/s `/hello` + 360 req/s `/test` = 1 200 req/s total · constant-arrival-rate · 30s · 12 workers each · WSL2 (Linux 6.6 on Windows)

| Metric | PHP-FPM (12w) | Octane Swoole (12w) | TrueAsync-Franken (12w) |
|---|---|---|---|
| Target rate | 1 200 req/s | 1 200 req/s | 1 200 req/s |
| Actual throughput | ~200 req/s | ~752 req/s | **~1 118 req/s** |
| Dropped iterations | ~28 000 | ~5 000 | **20** |
| Avg latency | ~4 000ms | ~880ms | **13ms** |
| p95 latency | ~5 000ms | 2 320ms | **21ms** |
| p95 < 200ms | ✗ | ✗ | **✓** |
| Failed requests | 0% | 0% | 0% |
| DB connections (peak) | — | — | 120 |

### Why TrueAsync wins on DB-bound load

| | PHP-FPM | Octane Swoole | TrueAsync-Franken |
|---|---|---|---|
| Request model | Process per request | 1 process = 1 request at a time | 1 worker = N coroutines |
| DB I/O | Blocking (new conn each req) | Blocking (PDO synchronous) | Non-blocking (coroutine yield) |
| Memory model | Stateless | Long-lived process | Long-lived process + coroutine context isolation |
| App bootstrap | Every request | Once per worker | Once per worker |

Swoole keeps the app in memory (avoids bootstrap cost) but PDO is still synchronous — a worker blocked on a DB call cannot accept another request. TrueAsync yields the coroutine on every DB call, so one worker handles hundreds of concurrent DB-bound requests without blocking.

### Notes

- Each adapter has its own PostgreSQL instance on a separate port to avoid interference
- `APP_DEBUG=false` in all setups for fair comparison
- OPcache enabled in PHP-FPM
- `max_connections=500` in all PostgreSQL instances
- Absolute numbers will be higher on bare metal (benchmarks run on WSL2)

Full benchmark: [ta_benchmark](https://github.com/YanGusik/ta_benchmark)

### Raw PHP — TrueAsync vs Swoole (no framework, no I/O)

On pure CPU-bound workloads both servers cap at the same throughput (~10k req/s). With optimal Swoole config (ZTS, 16 reactor threads) Swoole is ~1.6x faster on P95 latency due to FrankenPHP's Go↔PHP boundary overhead (futex synchronization). On I/O-bound workloads this overhead is negligible.

---

## Metrics

The running server's counters — requests, responses by status class, live connections per
protocol, HTTP/2 and streaming traffic — are readable from the application under
`async:serve`:

```php
use Spawn\Laravel\Server\ServerMetrics;

Route::get('/metrics', fn () => response(
    app(ServerMetrics::class)->toPrometheus(),
    200,
    ['Content-Type' => 'text/plain; version=0.0.4'],
));
```

The package publishes no endpoint of its own, so the URL and who may read it stay yours.
`totals()`, `workers()` and `latency()` return the same numbers as arrays. The counters are
always incremented; `async.server.stats` decides whether the aggregate can be read, and it
is on by default. See [docs/METRICS.md](docs/METRICS.md).

---

## Sessions

### Database sessions (built-in fix)

The package automatically replaces Laravel's `DatabaseSessionHandler` with an async-safe version that uses `upsert` instead of `INSERT + catch + UPDATE`.

In a standard async server the HTTP response is sent *before* `kernel->terminate()` writes the session. If the client immediately sends the next request with the same cookie, two coroutines can race to INSERT the same session ID — causing duplicate-key warnings in the stock handler. The upsert is atomic, so this race is impossible regardless of concurrency.

No configuration needed. Works transparently when `SESSION_DRIVER=database`.

### Redis sessions (recommended for production)

For high-concurrency workloads Redis sessions have lower overhead than database sessions and avoid any persistence race entirely:

```env
SESSION_DRIVER=redis
REDIS_HOST=127.0.0.1
```

## Eloquent relations

Eloquent decides whether a relation adds its own `where foreign_key = ?` through
`Relation::$constraints`, a static property. Eager loading switches it off while it builds the
relation object and restores it afterwards from a captured value. A static property is one flag
per worker thread, shared by every coroutine of that worker, so under concurrent serving that
window belongs to whoever happens to be inside it: one request's relations come out unfiltered
because another request was eager loading at that moment, and two overlapping windows leave the
flag off for the rest of the worker's life. The queries stay valid and answer with the whole
table.

Nothing to do — the package puts its own copies of two Eloquent files in front of Laravel's,
`Relations\Relation` and `Concerns\HasRelationships`. The first keeps that window in the
coroutine that opened it; the second builds relation classes that read it. Every model is
covered, including the ones that come from other packages.

The copies live in `overrides/laravel-13/`, beside the one this package already keeps for
Telescope, and are frozen against the Laravel release they were taken from — 13.26.1, which is
why `composer.json` requires `~13.26.1` — so each carries the checksum of the file behind it. A release that touches either file leaves
the application on Laravel's own classes rather than on a copy that has fallen behind; the
worker writes the reason to stderr at start-up, and `EloquentOverrides::status()` answers it at
any time. `SPAWN_ELOQUENT_OVERRIDES=0` switches the copies off.

---

## License

MIT
