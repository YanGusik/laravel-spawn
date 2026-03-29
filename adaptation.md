# Адаптация стандартных Laravel-пакетов для TrueAsync (корутины)

## Контекст

В TrueAsync каждый HTTP-запрос выполняется в отдельной корутине внутри одного PHP-воркера.
Это означает, что **все синглтоны, статические свойства и глобальное состояние разделяются между конкурентными запросами**.

Пакет `laravel-spawn` (ветка `feature/scope-per-request`) решает часть проблем через:
- `AsyncApplication` — перехват резолва сервисов с проверкой `current_context()`
- `ScopedServiceProxy` — прокси для фасадов, чтобы каждый вызов шёл в контекст текущей корутины
- `ScopedService` enum — список сервисов, изолированных по корутинам (`request`, `session`, `auth`, `auth.driver`, `cookie`)
- PDO Pool на уровне C — изоляция соединений к БД без скоупинга `DatabaseManager`

---

## Основные проблемы в корутинном окружении

| Тип проблемы | Описание |
|---|---|
| **Статические свойства / синглтоны** | Хранят состояние запроса → утечка между корутинами |
| **Кэш фасадов** | `Facade::$resolvedInstance` — статический массив, общий для всех корутин |
| **Глобальные переменные** | `$_GET`, `$_POST`, `$_SERVER`, `$_SESSION` — не изолированы (решается на уровне сервера) |
| **Блокирующий I/O** | Блокирует весь планировщик корутин, если не использовать async I/O |
| **Счётчик транзакций** | `Connection::$transactions` — общий между корутинами |

---

## Анализ пакетов

### Критические — требуют изоляции per-coroutine

| Пакет | Статус в spawn | Проблема | Решение |
|---|---|---|---|
| **Auth** | ✅ Scoped | `AuthManager::$guards[]` кэширует guard-ы с состоянием пользователя. `SessionGuard::$user` хранит аутентифицированного пользователя | `ScopedService::AUTH` + `ScopedServiceProxy` |
| **Session** | ✅ Scoped | `SessionManager` / `Store::$attributes` хранят данные сессии конкретного запроса | `ScopedService::SESSION` + `ScopedServiceProxy` |
| **Cookie** | ✅ Scoped | `CookieJar::$queued` — очередь cookies per-request | `ScopedService::COOKIE` (без прокси, т.к. type-hint `QueueingFactory`) |
| **Request** | ✅ Scoped | Каждый запрос — уникальный объект с URL, заголовками, телом | `ScopedService::REQUEST` через `current_context()` |

### Адаптированные — решены в этом PR

| Пакет | Проблема | Решение |
|---|---|---|
| **Database / Eloquent** | `Connection::$transactions` — общий счётчик при изолированных PDO-соединениях | Трейт `CoroutineTransactions` — счётчик в `coroutine_context()` |
| **View / Blade** | `View::share('errors')` из middleware утекает между корутинами | `AsyncViewFactory` — `share()`/`getShared()` через `current_context()` после `bootCompleted()` |
| **Routing** | `Router::$current` / `$currentRequest` перезаписываются конкурентными запросами | `AsyncRouter` — хранение в `current_context()` после `bootCompleted()` |
| **Events / Dispatcher** | `Event::defer()` флаг утекает между корутинами | Документировано как ограничение — user-space API, не вызывается в Laravel internals |
| **Broadcasting** | `BroadcastManager` и драйверы stateless | Не требует фикса |
| **Middleware** | Lifecycle — переиспользуются ли экземпляры? | Создаются заново через `make()` на каждый запрос. Не проблема |

### Безопасные — не требуют адаптации

| Пакет | Почему безопасен |
|---|---|
| **Cache** | `CacheManager` — синглтон, но store-бэкенды (Redis, File, Memcached) stateless. Подтверждено тестами |
| **Queue** | `QueueManager` кэширует соединения, но dispatch stateless. Подтверждено тестами |
| **Mail** | `MailManager` кэширует mailer-ы, но они не хранят per-request состояние. Подтверждено тестами |
| **Log** | Логгирование — side-effect, состояние не хранится |
| **Validation** | `Validator` создаётся заново для каждой валидации, `Factory::$extensions` — read-only |
| **Filesystem / Storage** | `FilesystemManager` кэширует disk-и, но они stateless |
| **HTTP Client** | `PendingRequest` создаётся заново каждый раз |
| **Notifications** | Использует Queue/Mail под капотом (безопасны) |
| **Config** | Загружается при старте, immutable в runtime |
| **Encryption** | Stateless операции с ключом приложения |
| **Hashing** | Stateless алгоритмы |
| **Pagination** | Создаётся заново per-request |

---

## TODO

- [ ] **Документация**: описать unsafe-паттерны для пользователей (static state, `Event::defer()`, singleton с mutable state)
- [x] **Линтер**: PHPStan-правило `MutableStaticPropertyRule` — обнаружение mutable static properties (`src/PHPStan/`)
- [x] **Адаптеры для сторонних пакетов**: spatie/laravel-permission (`AsyncPermissionRegistrar`)
- [ ] **Адаптеры для сторонних пакетов**: inertia, socialite, livewire

---

## Сторонние пакеты

Популярные third-party пакеты, отсортированные по степени риска.

### Несовместимы — отключать в async-режиме

| Пакет | Установок/мес | Проблема |
|---|---|---|
| **barryvdh/laravel-debugbar** | ~25M | Singleton-коллекторы (QueryCollector, RouteCollector) копят per-request данные. Memory leak. Данные одного запроса видны в другом |
| **laravel/telescope** | ~7M | Аналогично — `IncomingEntry` объекты копятся в памяти. `Telescope::$shouldRecord` — static флаг, влияет на все корутины |

### Требуют скоупинга — тот же паттерн что View::share()

| Пакет | Установок/мес | Проблема | Решение |
|---|---|---|---|
| **spatie/laravel-permission** | ~30M | ✅ `AsyncPermissionRegistrar` — team ID и wildcard index в `current_context()`, `clearPermissionsCollection()` no-op в async mode | `src/Permission/AsyncPermissionRegistrar.php` |
| **livewire/livewire** | ~25M | `LivewireManager` — singleton с per-request state. Множество Octane-багов: asset injection, data hydration, `wire:stream`. Сильно завязан на традиционный request lifecycle | Глубокая адаптация или замена на Inertia в async-режиме |
| **inertiajs/inertia-laravel** | ~12M | `Inertia::share()` — аналог `View::share()`, данные (`auth.user`, flash messages) в singleton factory. `HandleInertiaRequests` middleware вызывает на каждый запрос | Тот же подход что `AsyncViewFactory` — `share()` в `current_context()` после `bootCompleted()` |
| **laravel/socialite** | ~15M | `SocialiteManager` кэширует driver-ы в `$drivers[]` со stale конфигом от предыдущего запроса | Flush `$drivers` per-request или скоупить manager |

### Безопасны — уже покрыты существующим скоупингом или stateless

| Пакет | Установок/мес | Почему безопасен |
|---|---|---|
| **laravel/sanctum** | ~40M | Использует auth guards (уже scoped). `$personalAccessTokenModel` — static, read-only |
| **laravel/passport** | ~15M | Auth guards scoped. Static свойства — boot-time конфигурация |
| **laravel/scout** | ~8M | `EngineManager` кэширует engines (Algolia, Meilisearch) — stateless HTTP-клиенты |
| **laravel/cashier-stripe** | ~7M | Stateless вызовы Stripe API через Eloquent-модели |
| **spatie/laravel-medialibrary** | ~10M | Model-based, конверсии через queue. Нет singleton state |
| **spatie/laravel-activitylog** | ~8M | Trait-based логирование. Static конфигурация read-only. Ручной `activity()` API — создаёт новый logger |
| **laravel/horizon** | ~10M | Отдельный процесс (`horizon:work`). Dashboard — stateless чтение из Redis |
| **laravel/breeze** | ~8M | Scaffolding, не runtime. Риск от Livewire/Inertia под капотом |

---

## Результаты автоматического сканирования (PHPStan MutableStaticPropertyRule)

309 находок в `vendor/laravel/framework`. Классификация:

### Безопасные через существующий скоупинг — resolvers используют `$app['request']`

| Класс | Свойство | Почему безопасно |
|---|---|---|
| `AbstractPaginator` | `$currentPageResolver` | Замыкание вызывает `$app['request']->input()` — request уже scoped |
| `AbstractPaginator` | `$currentPathResolver` | `$app['request']->url()` — scoped |
| `AbstractPaginator` | `$queryStringResolver` | `$app['request']->query()` — scoped |
| `AbstractCursorPaginator` | `$currentCursorResolver` | `$app['request']->input()` — scoped |
| `AbstractPaginator` | `$viewFactoryResolver` | `$app['view']` — one view factory, safe |

### Требуют внимания — потенциально опасны

| Класс | Свойство | Сценарий |
|---|---|---|
| **`ManagesLayouts`** | `$parentPlaceholder` | Параллельный рендеринг Blade `@section`/`@yield` — маловероятно, но возможно |

### Требуют внимания — опасны в специфичных сценариях

| Класс | Свойство | Сценарий |
|---|---|---|
| `Model` | `$unguarded` | `Model::unguard()` в seeders — глобальный флаг. Опасен если seeder параллельно с запросами |
| `Model` | `$recursionCache` | Static WeakMap, потенциальный cross-coroutine leak |

### Безопасные — boot-time конфиг (read-only в runtime)

76× `$macros` (Macroable trait), `Model::$resolver`, `$dispatcher`, `$booted`, `$bootedCallbacks`,
`$traitInitializers`, `$globalScopes`, `$mutatorCache`, `$attributeMutatorCache`, `$castTypeCache`,
`$classAttributes`, `$snakeAttributes`, `$primitiveCastTypes`, `$collectionClass`,
`$modelsShouldPreventLazyLoading` и подобные стратегии, `Facade::$app`/`$resolvedInstance`/`$cached` (решено через `ScopedServiceProxy`),
`Router::$verbs`, `Route::$validators`, `Encrypter::$supportedCiphers`, `Connection::$resolvers`,
`Cookie/Middleware::$neverEncrypt`/`$serialize`, `Queue::$createPayloadCallbacks`,
`$redirectToCallback` (Auth middleware — ставится в `bootstrap/app.php`).

---

## Архитектурные заметки

### Почему `db` нельзя скоупить
`DatabaseServiceProvider::boot()` устанавливает `Model::$resolver = $app['db']` как статическое свойство.
Если `db` скоупить — после завершения корутины статическая ссылка будет указывать на уничтоженный объект → **segfault**.
Решение: `db` остаётся синглтоном, физическая изоляция через PDO Pool.

### Почему Cookie не проксируется
`AuthManager` передаёт `$app['cookie']` в `setCookieJar(QueueingFactory $cookie)`.
`ScopedServiceProxy` не реализует `QueueingFactory` → `TypeError`.
Решение: Cookie скоупится напрямую через `resolve()`, без прокси через `offsetGet()`.

### Паттерн для сторонних пакетов
```php
// config/async.php
'scoped_services' => [
    \SomePackage\Manager::class,
],

// Или программно
$app->scopedSingleton(\SomePackage\Manager::class, fn($app) => new Manager());
```
