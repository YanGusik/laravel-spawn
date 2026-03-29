# Coroutine Safety Adapters

Laravel components and third-party packages adapted for safe concurrent execution in TrueAsync coroutines.

## Core Laravel

| Component | Problem | Adapter | Isolated State |
|---|---|---|---|
| **Request** | Each coroutine needs its own request | `ScopedService::REQUEST` | Full request object |
| **Auth** | `AuthManager::$guards[]` caches user state | `ScopedService::AUTH` + `ScopedServiceProxy` | Guards, authenticated user |
| **Session** | `Store::$attributes` shared across requests | `ScopedService::SESSION` + `ScopedServiceProxy` | Session data |
| **Cookie** | `CookieJar::$queued` shared queue | `ScopedService::COOKIE` | Queued cookies |
| **View / Blade** | `View::share()` leaks between coroutines | `AsyncViewFactory` | Shared view data |
| **Routing** | `Router::$current` overwritten by concurrent requests | `AsyncRouter` | Current route, current request |
| **Database** | `Connection::$transactions` shared counter | `CoroutineTransactions` trait | Transaction depth counter |
| **Translation** | `Translator::$locale` overwritten per-request | `AsyncTranslator` | Locale (shared `$loaded` cache) |
| **Facades** | `Facade::$resolvedInstance` static cache | `ScopedServiceProxy` | Instance resolution |

## Third-Party Packages

| Package | Problem | Adapter | Isolated State |
|---|---|---|---|
| **spatie/laravel-permission** | `PermissionRegistrar` singleton caches team ID, wildcard index | `AsyncPermissionRegistrar` | Team ID, wildcard index |
| **inertiajs/inertia-laravel** | `ResponseFactory` singleton mutated per-request by middleware | `AsyncResponseFactory` | sharedProps, rootView, version, encryptHistory, urlResolver |

## Incompatible — Disable in Async Mode

| Package | Reason |
|---|---|
| **barryvdh/laravel-debugbar** | Singleton collectors accumulate per-request data, memory leak |
| **laravel/telescope** | Same pattern — `IncomingEntry` objects accumulate in memory |
| **livewire/livewire** | Deep per-request state in `LivewireManager`, `wire:stream` broken |

## Safe — No Adaptation Needed

Cache, Queue, Mail, Log, Validation, Filesystem, HTTP Client, Notifications, Config, Encryption, Hashing, Pagination, Sanctum, Passport, Scout, Cashier, Horizon.

## PHPStan Rule

`MutableStaticPropertyRule` — scans for mutable static properties (potential coroutine leaks).

```bash
phpstan analyse vendor/some/package/src --configuration=phpstan.neon
```
