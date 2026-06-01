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

### Dev server

Simple TCP socket server for local development. Analogous to `php artisan serve`.

```bash
php artisan async:serve --host=0.0.0.0 --port=8080
```

### FrankenPHP

Production-ready adapter using [FrankenPHP](https://frankenphp.dev) in async worker mode.
Requires the `trueasync/php-true-async:latest-frankenphp` Docker image.

```bash
php artisan async:franken --host=0.0.0.0 --port=8080 --workers=1 --buffer=1
```

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

### Dev server (no FrankenPHP required)

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

## Configuration

`config/async.php`:

If you use TrueAsyncServer, pls read docs: [Configuration](https://true-async.github.io/en/docs/server/configuration.html)

```php
return [
    'db_pool' => [
        'enabled'              => env('ASYNC_DB_POOL_ENABLED', true),
        'min'                  => env('ASYNC_DB_POOL_MIN', 2),
        'max'                  => env('ASYNC_DB_POOL_MAX', 10),
        'healthcheck_interval' => env('ASYNC_DB_POOL_HEALTHCHECK', 30),
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
        | bypassing the Laravel kernel.
        |
        | Example:
        |   [
        |       'prefix' => '/assets/',
        |       'root'   => public_path('assets'),
        |       'etag'   => true,
        |       'precompressed' => ['br', 'gzip'],
        |   ]
        |
        */

        'static_handlers' => [],
    ],
];
```

---

## Benchmarks (Obsolete use TrueAsyncServer)

Check results in [HttpArena](https://www.http-arena.com/leaderboard/#v=composite&res=mem)

**Load:** 840 req/s `/hello` + 360 req/s `/test` = 1 200 req/s total · constant-arrival-rate · 30s · 12 workers each · WSL2 (Linux 6.6 on Windows)

| Metric | PHP-FPM (12w) | Octane Swoole (12w) | TrueAsync (12w) |
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

| | PHP-FPM | Octane Swoole | TrueAsync |
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

---

## License

MIT
