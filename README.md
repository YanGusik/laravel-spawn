<p align="center"><img width="335" height="61" src="/logo.svg" alt="Logo Laravel Spawn"></p>

Laravel adapter for [PHP TrueAsync](https://github.com/true-async) — a PHP fork with a native coroutine scheduler and async I/O. Think Laravel Octane, but instead of Swoole or RoadRunner the runtime is TrueAsync.

**One worker. Many requests. Zero threads.**
Each HTTP request runs in its own coroutine with isolated state — no shared memory, no leaks between requests.

---

## How it works

- Each request = a separate coroutine with its own `Scope`
- Request-scoped services (`auth`, `session`, `cookie`) are isolated via `coroutine_context()`
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

### Dev server (no FrankenPHP required)

```yaml
services:
  app:
    image: trueasync/php-true-async:latest
    working_dir: /app
    command: php artisan async:serve --host=0.0.0.0 --port=8080
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

```php
return [
    'db_pool' => [
        'enabled'              => env('ASYNC_DB_POOL_ENABLED', true),
        'min'                  => env('ASYNC_DB_POOL_MIN', 2),
        'max'                  => env('ASYNC_DB_POOL_MAX', 10),
        'healthcheck_interval' => env('ASYNC_DB_POOL_HEALTHCHECK', 30),
    ],
];
```

---

## Benchmarks

### Laravel — TrueAsync vs Swoole Octane (4 workers, real DB workload)

Endpoint: 5 real SQL queries per request. PostgreSQL 16. WSL2. k6 at 1,000 req/s for 30s.

| Metric | Swoole Octane | TrueAsync | Difference |
|---|---|---|---|
| Throughput | 206 req/s | **632 req/s** | **3.1x** |
| Avg latency | 4,300ms | **850ms** | 5x faster |
| Median latency | 4,820ms | **51ms** | **94x faster** |
| p95 latency | 5,020ms | **288ms** | 17x faster |

Swoole blocks one worker per DB query. TrueAsync yields the worker to the scheduler while waiting — a single worker handles hundreds of concurrent I/O operations.

Full benchmark: [ta_benchmark](https://github.com/YanGusik/ta_benchmark)

### Raw PHP — TrueAsync vs Swoole (no framework, no I/O)

On pure CPU-bound workloads both servers cap at the same throughput (~10k req/s). With optimal Swoole config (ZTS, 16 reactor threads) Swoole is ~1.6x faster on P95 latency due to FrankenPHP's Go↔PHP boundary overhead (futex synchronization). On I/O-bound workloads this overhead is negligible.

---

## License

MIT
