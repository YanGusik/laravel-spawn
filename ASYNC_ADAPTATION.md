# Async Adaptation

Laravel components and third-party packages adapted for safe concurrent execution in TrueAsync coroutines.

## How It Works

In async mode, multiple HTTP requests execute concurrently inside a single PHP worker process. Each request runs in its own coroutine with an isolated `Scope`. All singletons, static properties, and global state are **shared** between concurrent requests.

`laravel-spawn` adapts Laravel's core services so that per-request state is automatically isolated via `current_context()`, while shared read-only state (config values, translation caches, route definitions) remains shared for performance.

**You don't need to change your application code.** Standard Laravel patterns (middleware, controllers, Eloquent, Blade) work as expected. This document describes what to **avoid** and what has been adapted.

---

## Adapted Components

### Core Laravel

| Component | Adapter | What's Isolated |
|---|---|---|
| **Request** | `ScopedService::REQUEST` | Full request object per coroutine |
| **Auth** | `ScopedService::AUTH` + `ScopedServiceProxy` | Guards, authenticated user |
| **Session** | `ScopedService::SESSION` + `ScopedServiceProxy` | Session data |
| **Cookie** | `ScopedService::COOKIE` | Queued cookies |
| **View / Blade** | [`AsyncViewFactory`](src/View/AsyncViewFactory.php) | `View::share()` data |
| **Routing** | [`AsyncRouter`](src/Routing/AsyncRouter.php) | Current route and request |
| **Database** | [`CoroutineTransactions`](src/Database/CoroutineTransactions.php) | Transaction depth counter |
| **Translation** | [`AsyncTranslator`](src/Translation/AsyncTranslator.php) | Active locale (shared `$loaded` cache) |
| **Config** | [`AsyncConfig`](src/Config/AsyncConfig.php) | `config()->set()` overlay per coroutine |
| **Events** | [`AsyncDispatcher`](src/Events/AsyncDispatcher.php) | `defer()` state (deferring flag, deferred queue) |
| **Facades** | [`ScopedServiceProxy`](src/Foundation/ScopedServiceProxy.php) | `Facade::$resolvedInstance` cache |

### Third-Party Packages

| Package | Adapter | What's Isolated |
|---|---|---|
| **spatie/laravel-permission** | [`AsyncPermissionRegistrar`](src/Permission/AsyncPermissionRegistrar.php) | Team ID, wildcard permission index |
| **inertiajs/inertia-laravel** | [`AsyncResponseFactory`](src/Inertia/AsyncResponseFactory.php) | sharedProps, rootView, version, encryptHistory, urlResolver |
| **laravel/socialite** | `scopedSingleton` (in `AsyncServiceProvider`) | Fresh manager per coroutine (drivers cache stale request) |
| **laravel/telescope** | [`CoroutineSafeRecording`](src/Telescope/CoroutineSafeRecording.php) trait + class substitution | entriesQueue, updatesQueue, shouldRecord per coroutine |
| **barryvdh/laravel-debugbar** | `scopedSingleton` (in `AsyncServiceProvider`) | Fresh debugbar + all collectors per coroutine |

---

## Safe — No Adaptation Needed

These components are stateless or create new instances per request:

Cache, Queue, Mail, Log, Validation, Filesystem, HTTP Client, Notifications, Encryption, Hashing, Pagination, Sanctum, Passport, Scout, Cashier, Horizon.

---

## Incompatible Packages

Disable these in async mode. They accumulate per-request data in singletons, causing memory leaks and data leakage between requests.

| Package | Issue |
|---|---|
| **livewire/livewire** | Deep per-request state in `LivewireManager`, `wire:stream` broken. Use Inertia instead |

---

## Writing Async-Safe Code

### Safe patterns (no changes needed)

```php
// Controllers — new instance per request
class UserController extends Controller
{
    public function show(User $user) { ... }  // ✅
}

// Eloquent — models are per-request objects
$user = User::find(1);           // ✅
$user->update(['name' => '...']);  // ✅

// Dependency injection — resolved per request for scoped services
public function __construct(Request $request) { ... }  // ✅

// Middleware setting locale — AsyncTranslator handles this
App::setLocale($user->locale);   // ✅

// View::share() in middleware — AsyncViewFactory handles this
View::share('user', auth()->user());  // ✅

// Inertia::share() in middleware — AsyncResponseFactory handles this
Inertia::share('auth', fn() => ['user' => auth()->user()]);  // ✅

// config()->set() in middleware — AsyncConfig handles this
config(['app.locale' => 'ru']);  // ✅

// Queue dispatch — stateless
dispatch(new ProcessOrder($order));  // ✅

// Cache operations — stateless backends
Cache::put('key', 'value', 60);  // ✅
Cache::get('key');                // ✅
```

### Unsafe patterns (avoid these)

```php
// ❌ Static mutable property in a service
class MyService
{
    private static array $cache = [];

    public function process($data)
    {
        // This cache is shared across ALL concurrent requests!
        self::$cache[$data->id] = $result;
    }
}
// ✅ Use instance property instead (service is created per request or use current_context())

// ❌ Number::useLocale() — mutates global static
Number::useLocale('de');
$price = Number::format(1234.5);  // Other request may change locale before this runs
// ✅ Pass locale as parameter
$price = Number::format(1234.5, locale: 'de');

// ❌ once() on a singleton with per-request data
class AuthService  // registered as singleton
{
    public function currentUser()
    {
        return once(fn() => auth()->user());  // Caches first request's user for ALL requests
    }
}
// ✅ Don't use once() with per-request data on singletons
// ✅ Use once() on per-request objects (models, controllers) — that's safe

// ❌ Storing request state in a singleton property
class Analytics  // registered as singleton
{
    private array $events = [];

    public function track(string $event)
    {
        $this->events[] = $event;  // Accumulates across ALL requests — memory leak
    }
}
// ✅ Register as scoped binding instead of singleton
// $app->scoped(Analytics::class);

// ❌ Global variable or superglobal
$_SERVER['CUSTOM_HEADER'] = 'value';  // Shared across all coroutines
// ✅ Use the Request object
$request->headers->get('custom-header');
```

### Rules of thumb

1. **Don't write to static properties** during request handling. Static properties are shared across all coroutines. Read is fine if they're set at boot time.

2. **Don't store per-request data in singletons.** Use scoped bindings (`$app->scoped()`) or pass data through method arguments.

3. **Don't use `once()` on singletons** with per-request data. `once()` is safe on per-request objects (Eloquent models, controllers).

4. **Don't use superglobals** (`$_GET`, `$_POST`, `$_SERVER`, `$_SESSION`). Use Laravel's `Request` object.

5. **Don't use `sleep()` / `usleep()`** — they block the entire event loop. Use `Async\delay()` instead.

6. **Closures are safe** if they resolve dependencies lazily: `fn() => $app['request']->url()` works because `$app['request']` is scoped per coroutine.

---

## Adapting Third-Party Packages

### Using config-based scoping

For simple cases where a singleton just needs to be per-request:

```php
// config/async.php
'scoped_services' => [
    \SomePackage\Manager::class,
],
```

### Using scopedSingleton

For packages that need a custom factory:

```php
$app->scopedSingleton(\SomePackage\Manager::class, function ($app) {
    return new Manager($app['config']['some-package']);
});
```

### Writing a custom adapter

For packages where only some properties need isolation (like `AsyncViewFactory`):

1. Extend the original class
2. Add `bootCompleted()` to switch to async mode
3. Override methods that read/write per-request state to use `current_context()`
4. Keep shared state (caches, config) in the parent class

See [`AsyncTranslator`](src/Translation/AsyncTranslator.php) for a minimal example.

---

## PHPStan Rule

[`MutableStaticPropertyRule`](src/PHPStan/MutableStaticPropertyRule.php) scans for mutable static properties — the #1 source of coroutine state leaks.

```bash
# Scan a vendor package
phpstan analyse vendor/some/package/src --configuration=phpstan.neon

# Scan your own code
phpstan analyse app/ --configuration=phpstan.neon
```

309 findings in Laravel framework — all classified as safe (boot-time config, cooperative multitasking safe, or documented unsafe patterns). See [adaptation.md](adaptation.md) for the full analysis.
