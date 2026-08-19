# Known issues and limitations under async serving

Findings from the review of #29 (issue #24, auth registrations). Everything here was
verified by reading the vendored framework source or by running the suite; where a
claim is reasoning rather than an observed failure, it says so.

What #29 fixes is in `CHANGELOG.md`. What it does not fix is below.

---

## 0. Where things stand

- Work is on `fix/24-scoped-boot-registrations`, which carries this change on top of
  `master`; the branch's earlier work went in as PR #29 and the branch was rebuilt after
  that merge. 206 tests, green, end-to-end included.
- **Issues #30 to #35 are fixed** (table in §1), each with a test that fails without the
  fix.
- Of the strategy in §6: moves 3, 4 and 6 are done, move 5 was rejected in the shape the
  plan gave it and each of its three items fixed separately, and move 1 stays rejected.
- `php artisan async:audit` drives every parameterless GET route through a worker and
  reports what each request leaves captured; `phpstan-framework.neon` points the static
  half at `vendor/laravel`, where it reports four captures and nothing else — the URL
  generator, the redirector, the response factory and `StartSession`, which is the
  acceptance §6.3 asked for.

### Next, in order

1. The container contract gaps (§3) — only after the shape in §6.1 has a concurrency
   harness behind it.
2. §2 lists what the design cannot do. Removing one needs a different design rather than
   a fix; three of the eight are not pinned by a test and say so.

### Working notes, so a cold start does not repeat today

- Suite: `php vendor/bin/phpunit --colors=never`, with `PHP_INI_SCAN_DIR` pointing at an
  ini directory that loads `true_async_server.so` — without it the three end-to-end tests
  fail on a missing `TrueAsync\HttpServerConfig`.
- Benchmark: `php tests/bench/bench_resolve.php 200000`
- **The local `/usr/local/bin/php` runs the whole suite.** It is PHP 8.6.0-dev ZTS DEBUG
  with dom, xml, libxml and Phar, so PHPUnit and PHPStan both work; the older note saying
  the build was `--disable-all` is wrong. Docker is not available in WSL here.
- **`true_async_server.so` under the extension directory is stale** and dies with
  `undefined symbol: _php_stream_cast` the moment it is used. Rebuild from
  `/home/edmond/true-async-server`: `make clean && phpize && ./configure
  --with-php-config=/usr/local/bin/php-config && make -j$(nproc)`, then point
  `extension_dir` at `modules/`. Installing over the stale copy needs root.
- **If `tests/StreamingE2ETest.php` fails with `Call to undefined function trueasync_response()`,
  the autoloader is stale, not the extension.** That function is defined in this package's own
  `src/helpers.php` and reaches PHP through composer's `autoload.files`. Regenerate:
  `composer dump-autoload` (CI installs from scratch, which is why CI never saw it).
- PHPStan on `src` reports 34 errors without `true_async_server` loaded and 9 with it: 4
  `class.notFound` for `FrankenPHP\*`, 4 `classConstant.notFound` for `PDO::ATTR_POOL_*`,
  and one Telescope `staticMethod.notFound`. They predate this work; CI does not run
  PHPStan. Neither custom rule reports anything on `src`.
- **Which coroutine reads the root context, measured.** `Async\spawn()` and
  `Scope::inherit()` taken from the root both reach it; a coroutine of a `new Scope()`
  does not. A request under `TrueAsyncServer` does not either — probed against a live
  one-worker server, where the handler's `request_context()->find()` and
  `current_context()->find()` both answer null for a value written at worker start. So a
  value written into the root context is read by worker-level coroutines and by nothing
  that serves a request, and `new Scope()` is what a test uses to stand in for a request.
  `AsyncApplication::tryResolveScoped()` guards against writing to the root for the
  opposite reason — an inherited scope does reach it — and both statements are true of
  their own case.
- **A test that binds a stock `Illuminate\Config\Repository` cannot see a mode bug.**
  `WorkerBootstrap` recognises the adapters by `instanceof`, so a stock repository skips
  `bootCompleted()` and writes to the shared array whatever the order of start-up. That is
  how #45 passed its own test for two releases. The same goes for reading back: a value
  written in async mode is visible to the coroutine that wrote it, and a test that asserts
  from there asserts nothing — read from a coroutine in a scope of its own.
- `Async\request_context()` is always `null` under PHPUnit — the server extension sets it.
  Anything that depends on it can only be checked end to end.
- A proxy must never be returned from `offsetGet()`: `RoutingServiceProvider` passes
  `$app['redirect']` to `ResponseFactory::__construct(Redirector $redirector)` and
  `AuthManager` passes `$app['cookie']` to `setCookieJar(QueueingFactory)`. The proxies
  live in the facade cache instead, which nothing type-hints.
- `AsyncTestCase::runParallel()` returns results in the order the coroutines were given,
  not the order they finished. A test comparing whole arrays would otherwise flake.
- `Async\suspend()` is what makes an interleaving test deterministic. Two coroutines that
  never suspend run one after the other, and a test written without it passes on shared
  state as happily as on isolated state.
- **A load stand is not the same as the suite, and it proves less than a clean run
  suggests.** Sixty concurrent requests against a real `async:serve`, each carrying its own
  token through Blade, the cookie jar, `Vite`, the log context and a terminating callback,
  found a leak every unit test had missed: the request facade after a suspension point.
  Nothing else came back mixed — but the stand's only suspension was *before* the render,
  so the renders ran atomically and it could not have distinguished shared Blade state
  from isolated. A stand that tests the render needs the suspension **inside** it: a view
  composer that yields within an `@include`, a component with a slot, `@once` in two
  includes. It also has to read what it collects — the terminating-callback log was
  written by the route and never checked by the script.

---

## 1. Filed as issues, and how each was closed

| # | What | Fix | Test |
|---|---|---|---|
| [#30](https://github.com/YanGusik/laravel-spawn/issues/30) | Facades of scoped services pin the first coroutine's instance (`Cookie`, `Socialite`, `Request`) | Caching switched off with async mode, so a facade always asks the container and the container answers per coroutine; a `ScopedServiceProxy` per per-request alias on top, as the fast path | `CookieIsolationTest`, `FacadeRestoreTest` |
| [#31](https://github.com/YanGusik/laravel-spawn/issues/31) | Blade render state (`@section`, `@push`, components) is shared | The factory stays one object and its sixteen render properties move into the request's context, so unmodified framework code writes per request. Tests cover sections, `@push`, `@once` and a suspended `@include`; `<x-component>` and slots go through the same properties but are not driven by a test | `BladeRenderE2ETest`, `BladeRenderIsolationTest`, `ViewRenderStateTest` |
| [#32](https://github.com/YanGusik/laravel-spawn/issues/32) | `UrlGenerator` is shared and overwritten per request | `url` and the response factory are per-request; `rebinding()` inside a per-request factory is dropped instead of accumulating | `UrlIsolationTest` |
| [#33](https://github.com/YanGusik/laravel-spawn/issues/33) | Laravel's own `scoped()` singletons were never flushed | Container half in #29; a seeder now carries boot-time log context into each request | `RequestLifecycleIsolationTest` |
| [#34](https://github.com/YanGusik/laravel-spawn/issues/34) | Terminating callbacks accumulate and re-run | The list belongs to the request, in its context; the container keeps only what bootstrap registered | `RequestLifecycleIsolationTest` |
| [#35](https://github.com/YanGusik/laravel-spawn/issues/35) | `Vite` holds CSP nonce and preloaded assets on a shared singleton | Per-request clone of the boot-time object, render state emptied | `RequestLifecycleIsolationTest` |
| [#44](https://github.com/YanGusik/laravel-spawn/issues/44) | Every uploaded file is rejected by Symfony's file bag: the extension's `TrueAsync\UploadedFile` is not the class it accepts | `UploadedFiles::convert()` wraps each upload over the extension's own temporary file, so nothing is copied, and returns Laravel's `UploadedFile` | `UploadedFilesTest` |
| [#45](https://github.com/YanGusik/laravel-spawn/issues/45) | The PDO pool is never enabled: worker start-up wrote the pool options through an already switched `AsyncConfig`, so requests read the base configuration and shared one connection | Start-up has two phases — configure, then switch — and the pool options are written in the first; the purge covers every connection bootstrap opened, not the default alone | `DatabasePoolOptionsTest`, `WorkerBootstrapOrderTest` |

### The one pattern behind half of them

A **shared singleton that captures a scoped service in its constructor**. `StartSession`
was one — fixed in #29 after two concurrent logins were found leaving with the same
session cookie. `Redirector` was another. The same shape, unaudited:

- `Illuminate\Cookie\Middleware\EncryptCookies` and `AddQueuedCookiesToResponse` take the
  `CookieJar` in their constructors;
- `VerifyCsrfToken` takes the encrypter and the app;
- any application middleware registered as a singleton.

Middleware resolved per request is safe; middleware registered as a singleton is not.
**An audit of every singleton whose constructor resolves a scoped alias is worth more
than any single fix here.**

---

## 2. Limitations inside the design, pinned by tests

These are consequences of how scoping works, not oversights. Where a test asserts the
current behaviour it is named, and a future change that removes the limitation fails
visibly; where none is named, nothing will notice the change but a reader.

1. **`use`-captured state in an adopted registration.** Re-binding fixes `$this` and
   nothing else. A creator written as `Auth::extend('x', function () use ($manager) {…})`
   keeps resolving against the manager it captured, in every coroutine.
   `tests/AuthLimitationsTest.php` asserts the broken behaviour, and the re-bound form
   beside it, so the difference between the two is visible.
   Registrations made through `Auth::resolved()` — the form real Sanctum uses — are
   correctly isolated, because `afterResolving` runs after the seeder.
2. **The configured list of per-request services is read for the last time at
   `enableAsyncMode()`.** A deferred provider that merges its own entries into
   `async.scoped_services` while serving is never seen; before the latch it was, at the
   cost of a config read on every resolve that missed. Not pinned by a test.
3. **A registration made after serving begins reaches one coroutine.** Prototypes are
   captured once, at `enableAsyncMode()`. A deferred provider loaded inside a request is
   the usual way to hit this: it is marked loaded and never registers again.
4. **`scopedSingleton()` services have no prototype**, so a seeder for one never runs —
   there is no boot-time instance by definition.
5. **A seeder only runs for an alias the container treats as scoped.** On any other
   alias it is silently never called.
6. **A facade of an alias that becomes per-request after start-up** is proxied from the
   moment the container learns of it, not from the moment the facade first resolved. An
   instance the facade cached before that — a deferred provider calling `scoped()` on an
   alias a facade had already touched — stays until something overwrites it.
7. **A facade root of a per-request alias answers `__call`, `__get`, `__set` and
   `__isset`, and nothing else.** `ScopedServiceProxy` is not callable, not stringable,
   not countable and not traversable, so `(Vite::getFacadeRoot())(…)` or `count(Foo::…)`
   through such a facade fails. Nothing in the per-request set is used that way — the
   `@vite` directive resolves through the container, not the facade — and the methods can
   be added when something needs them.
8. **`Facade::clearResolvedInstances()` while serving removes the proxies.** Nothing in
   the package calls it after start-up. The singular `clearResolvedInstance('request')`,
   which `Illuminate\Foundation\Http\Kernel` calls on every request, is put back by
   `RestorePerRequestFacades`, the first middleware in the pipeline — including when a
   coroutine got in first and left its own instance in the slot, which is why the check
   is for the proxy rather than for the slot being occupied. A test harness that clears
   the whole array has to enable async mode again, or its facades go back to pinning the
   first coroutine's instance.
9. **The tracing JIT loses `isset()` on a moved property, and the factory works around
   it.** `isset($this->sections[$name])` reads a dimension of a property the factory does
   not have, so the engine goes through `__isset()` and `__get()`; under
   `opcache.jit=tracing` that path answers false for a key the array holds. Measured in
   `trueasync/php-true-async:latest`: four of sixty concurrent pages emitted an `@once`
   block twice, and a fifty-line script without Laravel reproduces it eight times a run
   (`opcache.jit=off` and opcache off are both clean). Filed as
   [true-async/php-async#223](https://github.com/true-async/php-async/issues/223). The
   eight framework methods that ask this way are overridden in `AsyncViewFactory` to fetch
   the array first, which always answers correctly; the price is eight method bodies
   copied from upstream, which an upgrade can silently make stale.
10. **`Cookie::shouldReceive()` in async mode mocks the proxy, not the service.**
   `Facade::getMockableClass()` returns `get_class(static::getFacadeRoot())`, and the root
   of a per-request facade is a `ScopedServiceProxy`, so the mock carries that class and
   satisfies no type-hint the real service would. It reaches tests only: a worker is the
   only thing that turns async mode on, and `artisan test` does not. Not pinned by a
   test — Mockery is not installed here, and pulling it in for this alone is not worth a
   dependency.
11. **A connection the pool cannot take throws on its first query, and that is the
   intended outcome.** `PDO::ATTR_POOL_ENABLED` is refused for `PDO::ATTR_PERSISTENT`
   (`ext/pdo/pdo_dbh.c`), for a driver that does not implement pooling — `odbc`, `dblib`
   and `firebird` do not, which covers `sqlsrv` on most builds — and for a private
   in-memory SQLite database. Start-up puts the options on every configured connection
   without asking, so such a connection reports the refusal instead of quietly serving
   one shared handle to every coroutine. Persistent connections are not supported under
   async serving in the first place: one connection shared across coroutines is the
   defect the pool exists to prevent.
12. **Two Eloquent files are copies, and copies fall behind.** `overrides/laravel-13/` holds
   `Relations\Relation` and `Concerns\HasRelationships` with one edit each, and
   `EloquentOverrides` puts them in front of Laravel's through Composer's class map. Neither
   works without the other: a `Relation` that no longer switches the flag off, next to Laravel's
   own relation classes, would have eager loading add a `where` on a key that is not there yet.
   Each copy carries the checksum of the file it was taken from. A Laravel release that touches
   either one leaves the application on Laravel's classes — with the defect — rather than on a
   copy that has quietly fallen behind; the worker writes the reason to stderr at start-up and
   `test_the_copies_still_match_the_laravel_files_behind_them` fails, which is where the copies
   are meant to be brought forward by hand — `php bin/refresh-eloquent-overrides.php` re-copies
   both files, re-applies the edits and prints the new checksums. Only Laravel 13.26.1 is
   copied, and `composer.json` requires `~13.26.1` so that an untested release is refused at
   install rather than silently falling back. The churn is real rather than theoretical:
   `Relation` gained `withConstraints()` and a second flag between 13.2.0 and 13.26.1, and the
   CI of this branch went red on exactly that. The `Laravel drift` workflow installs the newest
   Laravel 13 every night, so a release that moves past the copies is found here first.
   Three things the copies do not reach. A coroutine spawned **inside** a window does not inherit
   it — the window lives in the opener's own context — so a relation built there is constrained
   where the opener wanted it bare. A package shipping its own `Relation` subclass with its own
   `addConstraints()` reads the shared property, which is now permanently true, and adds
   constraints during an eager load. And `opcache.preload` declaring either class before Composer
   includes `src/bootstrap.php` leaves the application on Laravel's own.
   `tests/EloquentOverridesTest.php` pins the behaviour; six of its cases fail with
   `SPAWN_ELOQUENT_OVERRIDES=0`.
---

## 3. Container contract gaps in `tryResolveScoped()`

The scoped path bypasses `Container::resolve()`, so parts of the container contract do
not apply to scoped services. None of these is silent-and-severe like the above, but
each will surprise somebody:

- contextual bindings (`when()->needs()->give()`) are ignored, and `$this->buildStack` is
  not populated, so contextual bindings *inside* a scoped factory do not fire either;
- `beforeResolving` callbacks never fire;
- `$this->resolved[$alias]` is never set, so `$app->resolved('session')` answers `false`
  after any number of resolves;
- `$app->extend()` **after** the alias was resolved and while not scoped follows the
  container's own rule and mutates only the instance.

`makeWith()` with parameters and `instance()` are handled: parameters bypass the context,
and an explicit `instance()` outranks scoping.

---

## 4. What the test harness cannot reach

- **`request_context()`** is set by the server extension's C code. Under PHPUnit it is
  always `null`, so the suite only exercises the `?? current_context()` fallback. The
  behaviour it guards — a `Scope::inherit()` inside a handler sharing one auth manager
  and one session with its parent — was verified by hand against a real `TrueAsyncServer`
  and needs an e2e runner to be verified automatically.
- **Pool mode (`workers > 1`)** cannot be stopped from the parent (upstream
  true-async/server#117), so a test would leave a process behind. The failure mode it
  would catch is static state, which the two-applications-in-one-process test covers.
- **Whether `TrueAsyncServer` should warm the PDO pool.** `DevServer` calls
  `warmUpDatabasePool()` from inside its server coroutine, `FrankenPhpServer` documents why
  it must not — before the scheduler runs, warming hangs — and `TrueAsyncServer` calls it
  nowhere, so its pool is created lazily in whichever request coroutine touches the database
  first. `ManagesDatabasePool` says such a pool is scoped to a short-lived coroutine and
  destroyed between requests; the changelog says a worker must warm it. Which side
  `TrueAsyncServer`'s bootloader falls on is unestablished: it runs in the worker thread
  before `HttpServer::start()`, and telling a warm-up from a hang there needs a database and
  concurrent requests. There is no database on this machine.
- **Object lifetime** — "a guard does not outlive its request" — is unobservable:
  `runParallel()` never disposes its scopes, so a `WeakReference` stays alive on any
  branch. Accumulation is asserted through the container's own counters instead.

---

## 5. Environment

`tests/StreamingE2ETest.php` fails locally against `trueasync/php-true-async:0.8.4-php8.6-alpine`:
the image loads `true_async_server` but has no `trueasync_response()`, which
`src/Sse/Sse.php:19` calls. CI uses `:latest`, where it exists. Not a code defect, and it
fails identically on `master`.

### `LOCK_EX` from five coroutines stops the worker

`file_put_contents($path, $data, FILE_APPEND | LOCK_EX)` from five or more coroutines at
once ends the process's useful life: nothing is written, no call returns, and nothing else
is served either. Four at once always finish. The threshold is `UV_THREADPOOL_SIZE`, which
defaults to 4: `flock()` was offloaded to the libuv pool and a waiting task held its
thread, and reads and writes of a regular file are `uv_fs` requests on the same pool, so
the holder's own write had no thread left and the lock was never released.

**Fixed in the runtime**, [true-async/php-async#221](https://github.com/true-async/php-async/issues/221)
by [php-src#18](https://github.com/true-async/php-src/pull/18): the wait happens on the
coroutine now, `flock(LOCK_NB)` and a timer. The note stays because the fix is on
`true-async-stable` and in no release yet — a worker built before it still stops, and the
twenty-line reproduction in the issue says in a second which build is in use.

**It reaches an application through the shipped defaults.** `Filesystem::put($path, $data,
true)` passes `LOCK_EX`, and both `FileSessionHandler::write()` and `FileStore::put()` call
it that way, so a worker on `SESSION_DRIVER=file` or `CACHE_STORE=file` stops under five
concurrent writes. Read from the framework source, not reproduced through a server. Log
channels are safe: `single` and `daily` default to `'locking' => false`.

The render-load stand appends without a lock for this reason, and one short append per
request is atomic on Linux anyway.

### Uploads obey the extension's limits, not `php.ini`

The extension parses the multipart body itself, so `upload_max_filesize`, `post_max_size`
and `upload_tmp_dir` are never read. Its own limits are compiled in: 20 files a request,
100 MiB a file, 100 fields, and every temporary file is written to `/tmp`. A file over the
size cap is delivered as an invalid upload with `UPLOAD_ERR_INI_SIZE` and no content; files
past the twentieth, and a filename the parser refuses, carry `UPLOAD_ERR_EXTENSION`.
Multipart is parsed by the HTTP/1 parser only: over HTTP/2 and HTTP/3 `getFiles()` returns
an empty array whatever the body carries.

A field name is taken literally except for a trailing `[]`. `photos[]` becomes a list, and
`user[avatar]` becomes one key spelled `user[avatar]`, so `$request->file('user.avatar')`
finds nothing where a PHP-SAPI request would.

The temporary file belongs to the request: it is unlinked once the request and its upload
objects are released, which is the end of the handler. The Laravel `UploadedFile` the
conversion returns holds a path and nothing more, so an upload passed to a queued job or
held in a static property points at a file that has already been unlinked. A handler that
needs the bytes afterwards has to move or read them while the request is alive.

---

## 6. Strategy

### The one cause

Laravel's container knows two lifetimes: transient, and singleton for the life of the
process. An async worker needs a third — for the life of a request. Every item above is
a place where state belonging to one request ended up in an object living for the life
of the process, or the reverse.

That is why fixing them one alias at a time does not converge. `StartSession` and
`Redirector` were both found by accident; the list of services nobody has looked at yet
is not shorter for having found those two.

### The moves, in order

**1. Do not relocate storage into `$this->instances` — rejected, with reasons.** The
obvious refactor is to stop reproducing `Container::resolve()` and instead seed
`$this->instances[$alias]` from the context, delegate, and move the result back. Two
things kill it. The container publishes the instance and *then* fires the resolving
callbacks, which may suspend — and `$this->instances` is shared by every coroutine, so
one request can read or erase another's object in that window. And the publication order
inverts: today the instance reaches the context *before* the callbacks run, which is what
stops a callback resolving its own service from recursing for ever. Relocation cannot
express that order at all.

If the reproduction is to be reduced, the shape worth trying is different: make
`isShared()` answer false for per-request aliases so the container never touches the
shared slot, delegate, and publish the *returned* value under the existing
first-one-wins rule. That still has to solve publication-before-callbacks, and it needs a
concurrency harness — two coroutines in one scope, a factory that suspends — before any
of it is worth writing.

**2. One registry of lifetime, not four — done.** The `ScopedService` enum, the config
list, `scopedBindings` and Laravel's own `scopedInstances` now answer through a single
map of alias to context key, kept current rather than snapshotted. Measured on the
benchmark below: a per-request resolve went from 212 ns to 117 ns, because the check
cost more than the work it guarded.

**3. Make "a singleton captured a per-request object" impossible to miss — done.** Two
halves, because neither covers the other. `SingletonCapturesPerRequestRule` reads
`singleton()` registrations before the code runs and needs no application; pointed at
`vendor/laravel` through `phpstan-framework.neon` it reports the URL generator taking the
request, the redirector taking the generator, the response factory taking the redirector
and `StartSession` taking the session manager — four, all real, nothing else in the whole
of Illuminate. It sees only what a constructor signature says.

`PerRequestCaptureAudit` reads objects instead, so it catches a capture made by a setter,
an array element or an `extend()`. It can only report objects that exist, and at worker
start almost nothing per-request does — Laravel builds `url`, the response factory and
every singleton middleware on the first request that needs one. `async:audit` is
therefore the delivery: it puts the application in a worker's state, drives every
parameterless GET route, and collects the findings inside each request's own scope. The
report at `enableAsyncMode()` stays as well, because it is free and it catches a capture
made during bootstrap.

The walk sees properties and array elements. Statics, closures and anything behind a
resource are outside it by construction, so an empty result means clean as far as it
looks, not safe.

**4. Facades: a proxy in the cache, not a shorter list — done.** `FACADE_PROXIED_MAP` was
deleted. Every per-request alias gets a `ScopedServiceProxy` written into
`Facade::$resolvedInstance` at start-up and whenever the container learns of a new one, so
the completeness problem disappears: the list is the container's own map of per-request
aliases. `offsetGet()` returns real objects again, which is what makes it safe for
`redirect` and `cookie` — both are passed to typed constructor parameters, and neither
was proxyable under the old shape.

**5. One per-request reset hook (#33, #34, #35) — rejected, and why.** A hook that resets
process state between requests is correct only where requests do not overlap. Here they
do: clearing the terminating callbacks would drop the callbacks of a request still in
flight, `Vite::flush()` would empty the preloaded assets another coroutine is collecting,
and `forgetScopedInstances()` would destroy another request's log context. Octane can
reset because it serves one request at a time per worker. Each of the three was made
per-request instead: the callbacks live in the request's context, `Vite` is a clone of the
boot-time object, and Laravel's `scoped()` already answers per request, with a seeder now
carrying the boot-time log context in.

**6. Blade render state (#31) — done, and the factory stayed shared after all.** The first
attempt made `view` per-request by cloning the boot-time factory. It was wrong twice over.
`Factory::__construct` does `share('__env', $this)`, so every compiled template renders
against whatever object was constructed — the prototype — while the clone was only reached
by direct calls on `$app->make('view')`, which is all the first tests did. And a
per-request factory creates the very pattern this package exists to remove:
`Component::$factory` is a process static filled on first use, `MailManager` and
`Markdown` take the factory in their constructors, so the first request to render a
component or send a mail pins its copy for the life of the worker.

What ships is the plan's original shape, with an implementation that does not cost fifty
method overrides. The sixteen render properties are `unset()` from the object in the
constructor, so every read and write the inherited traits make falls through `&__get()`
and `__set()` into a `BladeRenderState` held in the request's context. `&__get()` returns
by reference, which is what lets unmodified code run `array_pop($this->sectionStack)`.
An upgrade adding a *method* is handled automatically; an upgrade adding a *property* is
caught by `ViewRenderStateTest`, which fails on any property of `Illuminate\View\Factory`
that is in neither the moved list nor the configuration list.

**The rule this leaves behind.** Clone-per-request is correct for a service nothing
long-lived captures — `Vite` is resolved through the container at each use, so it is a
clone. A service the ecosystem captures by reference must stay one object with its state
moved into the request. That is why `Vite` and `view` are treated differently.

Next: the two halves of 3 that are not yet pointed at anything (§0). The container
contract gaps in §3 stay until the shape in 1 is proven on a concurrency harness.

**Not on this list:** the design limitations in §2. They are pinned by tests and
documented; removing them needs a different design, not a fix.
