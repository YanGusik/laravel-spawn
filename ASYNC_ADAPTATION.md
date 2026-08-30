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
| **Auth** | `ScopedService::AUTH` + `ScopedServiceProxy` + [`AsyncAuthManager`](src/Auth/AsyncAuthManager.php) | Guards, authenticated user (driver registrations stay shared) |
| **Session** | `ScopedService::SESSION` + `ScopedService::SESSION_STORE` + `ScopedServiceProxy` | Session data, including the store the session guard authenticates from |
| **Cookie** | `ScopedService::COOKIE` | Queued cookies |
| **View / Blade** | [`AsyncViewFactory`](src/View/AsyncViewFactory.php) — one factory, per-request render state | `View::share()` data and the whole render state: `@section`, `@push`, components, slots, fragments, loops, `@once` |
| **Routing** | [`AsyncRouter`](src/Routing/AsyncRouter.php) | Current route and request |
| **Database** | [`CoroutineTransactions`](src/Database/CoroutineTransactions.php) | Transaction depth counter |
| **Translation** | [`AsyncTranslator`](src/Translation/AsyncTranslator.php) | Active locale (shared `$loaded` cache) |
| **Config** | [`AsyncConfig`](src/Config/AsyncConfig.php) | `config()->set()` overlay per coroutine |
| **Events** | [`AsyncDispatcher`](src/Events/AsyncDispatcher.php) | `defer()` state (deferring flag, deferred queue) |
| **Facades** | [`ScopedServiceProxy`](src/Foundation/ScopedServiceProxy.php) in the facade cache | Every per-request alias, including the ones a proxy cannot be returned for (`redirect`, `cookie`) |
| **URL** | `scopedSingleton` (in `AsyncServiceProvider`), cloned from the boot-time generator | The generator's request, cached root and scheme; the response factory's redirector |
| **Vite** | `scopedSingleton` (in `AsyncServiceProvider`) | CSP nonce and preloaded assets |
| **Terminating callbacks** | `AsyncApplication::terminating()` | The callbacks a request registers, run at its end and dropped with it |
| **Eloquent statics** | copies of `Concerns\GuardsAttributes` and `Concerns\HasEvents` (in [`EloquentOverrides`](src/Database/Eloquent/EloquentOverrides.php)) | The window `Model::unguarded()` opens and the dispatcher `Model::withoutEvents()` installs. `Model::unguard()` and `Model::setEventDispatcher()` stay process-wide |

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

Nothing in these keeps state a request writes, so one worker serving many requests changes
nothing about them:

Queue, Validation, Filesystem, Notifications, Encryption, Hashing, Pagination, Sanctum,
Passport, Scout, Cashier, Horizon.

## Shared per worker — one object, and no per-request reset

Each of these is memoised for the life of the process, so what one request sets on it, the
next request reads ([#65](https://github.com/YanGusik/laravel-spawn/issues/65)). They are
listed rather than adapted, and the fixes are open. The first three carry a reproducer under
`tests/proof/`; the mail entry is a reading of the framework source and nothing more.

| Component | The object | What crosses |
|---|---|---|
| **Log** | `LogManager::$channels` memoises one `Logger` per channel; `LogManager::$sharedContext` | `Log::withContext(['request_id' => …])` tags every other request's lines |
| **HTTP Client** | `Illuminate\Http\Client\Factory`, a worker singleton | `Http::fake()`, `preventStrayRequests()`, global middleware |
| **Mail** | `MailManager::$mailers` | `Mailer::alwaysTo()` and `alwaysFrom()` write to the memoised mailer. Not reproduced |

The rate limiter belongs to no row above, because nothing of a request is kept on the shared
object. `RateLimiter::tooManyAttempts()` reads the counter and `hit()` raises it, with nothing
atomic spanning the two, so callers whose read lands before the first write are all admitted
and a limit of N admits more. The window is between two calls into the store and no store
closes it: two FPM workers sharing one Redis overshoot exactly as two coroutines do, and what
concurrency sets is how many callers get in, not whether they can. `tests/proof/prove_rate_limiter.php`
shows both, and the hand-interleaved run in it needs no coroutines at all.

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

### Using scopedSeeder

A scoped service is rebuilt from its factory in every coroutine. Anything a provider
did to the object *after* it was constructed stays behind on the boot-time instance:

```php
// AuthServiceProvider::boot() — writes into the object, not into the factory
Auth::viaRequest('bot-user-token', $callback);
```

The coroutine gets a manager built by `new AuthManager($app)`, which knows nothing
about that driver. The symptom is a service behaving as if it had never been
configured — `Auth driver [bot-user-token] for guard [bot] is not defined`, a missing
macro, a handler that never fires.

Register how to carry the registrations over:

```php
$app->scopedSeeder(\SomePackage\Manager::class, function ($fresh, $bootTime) {
    $fresh->registerDrivers($bootTime->drivers());
});
```

The seeder receives the instance this coroutine has just built and the one bootstrap
left behind. Copy **registrations only**. Per-request state — resolved drivers, the
current user, the current request — is what scoping exists to keep apart, and copying
it reintroduces the very leak scoping prevents.

The boot-time instance is captured when async mode starts, after every provider has
booted, and never again. What follows from that:

- configuration done lazily, on first use inside a request, needs no seeder;
- a service the application never resolved during bootstrap has nothing to transfer —
  including one registered through `scopedSingleton()`, which has no boot-time instance
  by definition;
- a registration made *after* serving has begun reaches that coroutine and no other.
  A deferred provider loaded inside a request is the usual way to hit this: it is
  marked as loaded, never registers again, and every later coroutine is without it.
  Register such a driver eagerly, or from a provider that is not deferred.

A seeder only runs for an alias the container treats as scoped — one of
`ScopedService`, `scoped_services`, or `scopedSingleton()`. On any other alias it is
never called.

Set `'diagnostics' => true` in `config/async.php` to have the worker report at startup
which scoped services bootstrap configured and no seeder covers.

Anything built on `Illuminate\Support\Manager` — session, Socialite, and whatever an
application makes scoped — is served by
[`ManagerRegistrations`](src/Foundation/ManagerRegistrations.php), which adopts the
drivers registered through `extend()`:

```php
$app->scopedSeeder(SomePackage\Manager::class, ManagerRegistrations::seed(...));
```

`auth` has its own ([`AsyncAuthManager`](src/Auth/AsyncAuthManager.php)) because
`AuthManager` is not a `Manager`.

A service that is **not** scoped needs none of this. `Blade::directive()` and
`Blade::extend()` write into a shared compiler, `Cache::extend()` into a shared cache
manager: one object, configured once, seen by every coroutine.

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
