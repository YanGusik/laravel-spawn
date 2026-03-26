# Laravel Spawn

<p align="center"><img width="335" height="61" src="/logo.svg" alt="Logo Laravel Spawn"></p>

> ⚠️ **Early development.** Package name and namespace will be updated to `laravel-spawn` once the project reaches a stable state.

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
composer require yangusik/laravel-true-async
```

> ⚠️ **Not on Packagist yet.** Use one of the options below until the package is published.

**Via git repository:**

```json
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/yangusik/laravel-true-async"
    }
],
"require": {
    "yangusik/laravel-true-async": "dev-master"
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
    "yangusik/laravel-true-async": "*"
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

> ⚠️ Multi-worker mode (`--workers > 1` or `--buffer > 1`) is temporarily unstable due to a bug in the TrueAsync FrankenPHP integration. Use `--workers=1 --buffer=1` until fixed.

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

## Benchmark (FrankenPHP, 1 worker vs PHP-FPM 5 workers)

Load: 200 req/s pure JSON + 100 req/s with DB (`pg_sleep(10ms)`) via k6 `constant-arrival-rate`.

| Metric | Laravel Spawn (1 worker) | PHP-FPM (5 workers) |
|---|---|---|
| Target rate | 300 req/s | 300 req/s |
| Actual throughput | **300 req/s** | 164 req/s |
| Dropped iterations | **0** | 3,673 |
| avg latency | **5.52ms** | 2,150ms |
| p(95) latency | **14.68ms** | 2,690ms |
| Failed requests | **0%** | 0% |

At 4x lower load (75 req/s), FPM is stable but still **3x slower** on latency (p95: 45ms vs 14ms).

---

## TODO

- [ ] Fix multi-worker mode (waiting for TrueAsync FrankenPHP bug fix)
- [ ] RoadRunner adapter
- [ ] Filesystem watcher / hot reload for dev server
- [ ] More scoped services (cache, queue, mail)
- [ ] Octane compatibility layer
- [ ] Rename package to `laravel-spawn`
- [ ] Publish to Packagist

---

## License

MIT
