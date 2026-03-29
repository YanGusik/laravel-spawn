# Async Adaptation

Laravel components and third-party packages adapted for safe concurrent execution in TrueAsync coroutines.

## Core Laravel

| Component | Problem | Adapter | Isolated State |
|---|---|---|---|
| **Request** | Each coroutine needs its own request | `ScopedService::REQUEST` | Full request object |
| **Auth** | `AuthManager::$guards[]` caches user state | `ScopedService::AUTH` + `ScopedServiceProxy` | Guards, authenticated user |
| **Session** | `Store::$attributes` shared across requests | `ScopedService::SESSION` + `ScopedServiceProxy` | Session data |
| **Cookie** | `CookieJar::$queued` shared queue | `ScopedService::COOKIE` | Queued cookies |
| **View / Blade** | `View::share()` leaks between coroutines | [`AsyncViewFactory`](src/View/AsyncViewFactory.php) | Shared view data |
| **Routing** | `Router::$current` overwritten by concurrent requests | [`AsyncRouter`](src/Routing/AsyncRouter.php) | Current route, current request |
| **Database** | `Connection::$transactions` shared counter | [`CoroutineTransactions`](src/Database/CoroutineTransactions.php) trait | Transaction depth counter |
| **Translation** | `Translator::$locale` singleton overwritten per-request | [`AsyncTranslator`](src/Translation/AsyncTranslator.php) | Locale (shared `$loaded` cache for performance) |
| **Config** | `config()->set()` mutates shared repository | [`AsyncConfig`](src/Config/AsyncConfig.php) | Per-coroutine overlay, base items shared read-only |
| **Facades** | `Facade::$resolvedInstance` static cache | [`ScopedServiceProxy`](src/Foundation/ScopedServiceProxy.php) | Instance resolution |

## Third-Party Packages

| Package | Problem | Adapter | Isolated State |
|---|---|---|---|
| **spatie/laravel-permission** | `PermissionRegistrar` singleton caches team ID, wildcard index | [`AsyncPermissionRegistrar`](src/Permission/AsyncPermissionRegistrar.php) | Team ID, wildcard index |
| **inertiajs/inertia-laravel** | `ResponseFactory` singleton mutated per-request by middleware | [`AsyncResponseFactory`](src/Inertia/AsyncResponseFactory.php) | sharedProps, rootView, version, encryptHistory, urlResolver |

## Incompatible — Disable in Async Mode

| Package | Reason |
|---|---|
| **barryvdh/laravel-debugbar** | Singleton collectors accumulate per-request data, memory leak |
| **laravel/telescope** | Same pattern — `IncomingEntry` objects accumulate in memory |
| **livewire/livewire** | Deep per-request state in `LivewireManager`, `wire:stream` broken ([details](https://github.com/livewire/livewire/discussions/10009)) |

## Safe — No Adaptation Needed

Cache, Queue, Mail, Log, Validation, Filesystem, HTTP Client, Notifications, Encryption, Hashing, Pagination, Sanctum, Passport, Scout, Cashier, Horizon.

## Unsafe User-Space Patterns

| Pattern | Problem | Safe Alternative |
|---|---|---|
| `Number::useLocale()` per-request | Mutates global static | Pass locale as parameter: `Number::format($n, locale: 'de')` |
| `once()` on singleton services | WeakMap caches across coroutines | Don't use `once()` with per-request data on singletons |
| `Event::defer()` | Static flag shared across coroutines | Documented limitation |

## PHPStan Rule

[`MutableStaticPropertyRule`](src/PHPStan/MutableStaticPropertyRule.php) — scans for mutable static properties (potential coroutine state leaks).

```bash
phpstan analyse vendor/some/package/src --configuration=phpstan.neon
```

309 findings in Laravel framework — all classified as safe (boot-time config, cooperative multitasking, or documented unsafe patterns). See [adaptation.md](adaptation.md) for full analysis.
