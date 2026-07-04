# Changelog

## [Unreleased]

### Added
- Laravel Debugbar compatibility (#14) — Debugbar now renders under async serving, with per-coroutine data isolation
  - `AsyncDebugbar` — one instance per worker; request state (collected snapshot, `responseIsModified`) kept per-coroutine via `current_context()`; persistent storage disabled (its inline I/O in `collect()` would break render atomicity under concurrency)
  - Context-backed collectors (`messages`, `time`, `exceptions`, `query`) via `DelegatesToContext` — one shared instance, per-coroutine data, so concurrent requests never mix debug data (`events`/`models` are a follow-up)
  - `AsyncApplication::runningInConsole()` returns `false` while serving, so Debugbar (and other web-only packages) detect web context instead of the CLI SAPI
  - `ResetDebugbar` — per-request reset + boot, mirroring Laravel Octane's `ResetDebugbar`
- `AsyncApplication` — extends Laravel's `Application` with per-coroutine service isolation
  - `enableAsyncMode()` — must be called before the HTTP server starts; artisan commands run as normal Laravel
  - `LARAVEL_SCOPED` — services that get a fresh instance per coroutine: `session`, `auth`, `auth.driver`, `cookie`
  - `FACADE_PROXIED` — subset of scoped services returned as `ScopedServiceProxy` via `offsetGet()` so Laravel Facades always resolve the correct coroutine-local instance
  - `scopedSingleton()` — register custom per-coroutine services programmatically
  - `scoped_services` config key — register scoped services via `config/async.php`
- `ScopedServiceProxy` — lightweight proxy cached by Facades; delegates every call to `coroutine_context()` so concurrent requests never share state
- `config/async.php` — publishable config with `scoped_services` list
- `AsyncServiceProvider` — merges config, registers `serve` command, publishes config via `vendor:publish`
- `DevServer` — minimal TCP server for local development only (`async:serve`), analogous to `php artisan serve`
- `FrankenPhpServer` — production adapter for TrueAsync FrankenPHP (`async:franken`); uses `FrankenPHP\HttpServer::onRequest()`, generates Caddyfile + worker file in `storage/app/trueasync/` and starts the `frankenphp` binary as a subprocess
- `ServerInterface` — contract for all server adapters (`prepareApp()`, `start()`)
- `ManagesDatabasePool` trait — shared PDO Pool logic extracted from servers; used by both `DevServer` and `FrankenPhpServer`

- PDO Pool integration for async-safe database access
  - `ManagesDatabasePool::configureDatabasePool()` — injects `PDO::ATTR_POOL_ENABLED` and related options into all database connection configs when `async.db_pool.enabled = true`
  - `ManagesDatabasePool::warmUpDatabasePool()` — forces the `DatabaseManager` to establish its connection inside the server coroutine before the accept loop starts, so the pool is created in the correct coroutine scope and shared across all request coroutines
  - `config/async.php` — extended with `db_pool` section: `enabled`, `min`, `max`, `healthcheck_interval`
- PHPUnit test suite under `tests/` running inside `trueasync/php-true-async:latest` Docker image
  - `CoroutineContextIsolationTest` — verifies `coroutine_context()` isolation
  - `ScopedServiceIsolationTest` — verifies scoped vs singleton behavior, session/auth isolation
  - `RequestIsolationTest` — verifies `app('request')` isolation per coroutine
  - `DatabaseIsolationTest` — documents that `db` is intentionally a singleton; PDO Pool handles physical connection isolation at the C level
  - `HttpE2ETest` + `tests/e2e/run.php` — in-process HTTP end-to-end suite: boots the bench fixture in a real worker thread (`spawn_thread`) and drives it over a loopback socket with concurrent coroutine clients (routing, per-request isolation across an I/O yield, error resilience) — no external server process, teardown via the DevServer's own SIGTERM handler

### Fixed
- `DevServer` request exception handler had a 1-arg `Throwable` signature but the scope invokes it with `(Scope, Coroutine, Throwable)` — every request error was swallowed by a `TypeError`; signature corrected
- `RequestParser` did not set `REMOTE_ADDR`, so `Request::getClientIp()` returned `null` — `DevServer` now passes the socket peer address (fixes Debugbar and anything else reading the client IP)

### Verified
- `cache`, `mail`, `queue` managers do not hold per-request state — no scoping needed, confirmed via parallel isolation tests

### Notes
- `flock(LOCK_EX)` was blocking the entire event loop on concurrent requests — reported to TrueAsync team, fixed in TrueAsync v0.6.2 (thanks @EdmondDantes)
- `cookie` and `auth.driver` are scoped but not proxied via `offsetGet`: `AuthManager` passes `$app['cookie']` directly to `setCookieJar(QueueingFactory $cookie)`, so returning a proxy there causes a `TypeError`
- `db` cannot be scoped: `DatabaseServiceProvider::boot()` stores the `DatabaseManager` in `Model::$resolver` (static). A scoped instance would be GC'd after its coroutine finishes, leaving the static pointing to a destroyed object → segfault. Physical connection isolation is handled by PDO Pool instead.
- `Connection::$transactions` counter is shared across coroutines (known limitation). In practice this only matters if two coroutines call `DB::beginTransaction()` simultaneously on the same connection name — PDO Pool ensures physical DB transactions are isolated, but Laravel may create a `SAVEPOINT` instead of `BEGIN` if the counter is non-zero.
- `PDO::ATTR_POOL_ENABLED` caused a segfault when `PDOStatement::execute()` was called from inside class methods — reported to TrueAsync team, fixed (thanks @EdmondDantes)
- PDO Pool must be initialized in the server coroutine scope (not lazily inside a request coroutine) — hence `warmUpDatabasePool()` runs before the accept loop
- `proc_close()` double `waitpid` bug caused `Async\AsyncException: Failed to monitor process` with multi-worker FrankenPHP — reported to TrueAsync team, fixed (thanks @EdmondDantes)
- TrueAsync dynamic fiber pool added (+10% on synthetic benchmarks)
- TrueAsync PDO Pool connection spawning fix (+20% throughput)
- Hot reload (`--watch`) for `async:serve` is not feasible: PHP cannot reload already-loaded class definitions within the same process, and restarting TrueAsync scheduler in-process causes `zend_mm_heap corrupted`. FrankenPHP hot reload pending watcher support in the Docker image.
