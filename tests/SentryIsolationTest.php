<?php

namespace Spawn\Laravel\Tests;

use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Spawn\Laravel\Foundation\RequestContext;
use Spawn\Laravel\Sentry\RequestRuntimeContextStorage;
use Spawn\Laravel\Sentry\SentryRequest;

use function Async\delay;
use function Async\spawn;

/**
 * Sentry's scope — the user, tags, breadcrumbs, the transaction — per request (#85, Issue 12).
 * One hub for the process put one request's user on every other request's errors.
 */
class SentryIsolationTest extends AsyncTestCase
{
    use BootsAsyncApplication;

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(\Sentry\Laravel\ServiceProvider::class)) {
            $this->markTestSkipped('sentry/sentry-laravel is not installed');
        }
    }

    /** @param  array<string, mixed>  $sentry */
    private function worker(array $sentry = []): \Spawn\Laravel\Foundation\AsyncApplication
    {
        $app = $this->bootedApp([]);
        $app->instance('env', 'testing');
        $app['config']->set('sentry', $sentry + ['dsn' => null, 'tracing' => []]);
        $app->register(\Sentry\Laravel\ServiceProvider::class);
        $app->make(HubInterface::class);
        // LogManager builds a channel once and keeps it: the logger holds the hub it was given at boot.
        $app->instance('test.sentry-logger', (new \Sentry\Laravel\LogChannel($app))([]));
        $app->enableAsyncMode();

        return $app;
    }

    private static function user(HubInterface $hub): ?string
    {
        $id = null;
        $hub->configureScope(static function (Scope $scope) use (&$id): void {
            $id = $scope->getUser()?->getId();
        });

        return $id;
    }

    /**
     * A request served the way the servers serve one.
     */
    private static function served(callable $body): \Closure
    {
        return static function () use ($body) {
            RequestContext::begin();
            SentryRequest::start();

            try {
                return $body();
            } finally {
                SentryRequest::end();
            }
        };
    }

    /**
     * @return array<string, array{\Closure(\Spawn\Laravel\Foundation\AsyncApplication): HubInterface}>
     */
    public static function hubs(): array
    {
        return [
            'the container' => [static fn ($app) => $app->make(HubInterface::class)],
            'the facade' => [static fn ($app) => \Sentry\Laravel\Facade::getFacadeRoot()],
            'the global functions' => [static fn ($app) => \Sentry\SentrySdk::getCurrentHub()],
            'the sentry alias' => [static fn ($app) => $app->make('sentry')],
            'a type-hinted parameter' => [static fn ($app) => $app->call(static fn (HubInterface $hub) => $hub)],
            'the log channel' => [static fn ($app) => (fn () => $this->hub)->call(
                $app->make('test.sentry-logger')->getHandlers()[0],
            )],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hubs')]
    public function test_a_user_set_in_one_request_is_not_another_requests(\Closure $hub): void
    {
        $app = $this->worker();

        $results = $this->runParallel([
            'a' => self::served(function () use ($app, $hub) {
                $hub($app)->configureScope(fn (Scope $scope) => $scope->setUser(['id' => 'alice']));
                delay(40);

                return self::user($hub($app));
            }),
            'b' => self::served(function () use ($app, $hub) {
                delay(10);

                return self::user($hub($app));
            }),
        ]);

        $this->assertSame(['a' => 'alice', 'b' => null], $results);
        $this->assertNull($this->inRequest(self::served(fn () => self::user($hub($app)))), 'nor a later request\'s');
    }

    public function test_a_coroutine_that_outlives_its_request_does_not_reach_the_next_one(): void
    {
        $app = $this->worker();
        $done = false;

        $this->inRequest(self::served(static function () use ($app, &$done): void {
            spawn(static function () use ($app, &$done): void {
                delay(20);
                $app->make(HubInterface::class)->configureScope(fn (Scope $scope) => $scope->setUser(['id' => 'late']));
                $done = true;
            });
        }));

        delay(60);

        $this->assertTrue($done);
        $this->assertNull($this->inRequest(self::served(fn () => self::user($app->make(HubInterface::class)))));
    }

    public function test_ending_a_request_twice_flushes_nothing_twice(): void
    {
        $this->worker();

        $results = $this->inRequest(self::served(static function (): array {
            $storage = new RequestRuntimeContextStorage();
            SentryRequest::end();

            // endContext() flushes only what remove() hands it.
            return [$storage->remove(), $storage->get()];
        }));

        $this->assertNull($results[0], 'the second end gets no context to flush');
        $this->assertNotNull($results[1], 'a coroutine that outlives the request still reports into it');
    }

    /**
     * FrankenPHP may serve its next request in the scope of the last one: begin() marks the
     * same context again, which still holds the context the last request released.
     */
    public function test_a_request_served_in_a_reused_scope_gets_a_context_of_its_own(): void
    {
        $app = $this->worker();

        $results = $this->inRequest(static function () use ($app): array {
            $first = self::served(function () use ($app) {
                $app->make(HubInterface::class)->configureScope(fn (Scope $scope) => $scope->setUser(['id' => 'first']));

                return \Sentry\SentrySdk::getCurrentRuntimeContext();
            })();

            $second = self::served(fn () => [
                self::user($app->make(HubInterface::class)),
                \Sentry\SentrySdk::getCurrentRuntimeContext(),
                (new RequestRuntimeContextStorage())->remove(),
            ])();

            return [$first, ...$second];
        });

        [$first, $user, $second, $removed] = $results;

        $this->assertNull($user, 'the first request\'s user');
        $this->assertNotSame($first, $second);
        $this->assertSame($second, $removed, 'its end flushes its own context');
    }

    /** SetRequestMiddleware binds the request's PSR-7 request for the events of that request. */
    public function test_the_psr7_request_stays_in_its_request(): void
    {
        $app = $this->worker();
        $key = \Sentry\Laravel\Http\LaravelRequestFetcher::CONTAINER_PSR7_INSTANCE_KEY;

        $results = $this->runParallel([
            'a' => self::served(function () use ($app, $key) {
                $psr7 = new \stdClass();
                $app->instance($key, $psr7);
                delay(40);

                return $app->make($key) === $psr7;
            }),
            'b' => self::served(function () use ($app, $key) {
                delay(10);

                return $app->bound($key);
            }),
        ]);

        $this->assertSame(['a' => true, 'b' => false], $results);
    }

    public function test_a_transaction_the_middleware_started_in_one_request_is_not_another_requests(): void
    {
        $app = $this->worker(['traces_sample_rate' => 1.0]);
        $app->register(\Sentry\Laravel\Tracing\ServiceProvider::class);

        $transactionOf = static fn (object $middleware) => (fn () => $this->transaction)->call($middleware);

        $results = $this->runParallel([
            'a' => self::served(function () use ($app, $transactionOf) {
                $middleware = $app->make(\Sentry\Laravel\Tracing\Middleware::class);
                $middleware->handle(\Illuminate\Http\Request::create('/a'), static fn () => null);
                delay(40);

                return [$transactionOf($middleware), $app->make(HubInterface::class)->getTransaction()];
            }),
            'b' => self::served(function () use ($app, $transactionOf) {
                delay(10);

                return [
                    $transactionOf($app->make(\Sentry\Laravel\Tracing\Middleware::class)),
                    $app->make(HubInterface::class)->getTransaction(),
                ];
            }),
        ]);

        $this->assertNotNull($results['a'][0]);
        $this->assertSame($results['a'][0], $results['a'][1], 'the hub of its own request carries it');
        $this->assertSame([null, null], $results['b']);
    }

    public function test_each_request_has_its_own_tracing_middleware_and_transaction_finisher(): void
    {
        $app = $this->worker();
        $app->register(\Sentry\Laravel\Tracing\ServiceProvider::class);

        $objects = static fn () => [
            $app->make(\Sentry\Laravel\Tracing\Middleware::class),
            $app->make(\Sentry\Laravel\Tracing\TransactionFinisher::class),
        ];

        $results = $this->runParallel(['a' => $objects, 'b' => $objects]);

        $this->assertNotSame($results['a'][0], $results['b'][0], 'the middleware holds its request\'s transaction');
        $this->assertNotSame($results['a'][1], $results['b'][1]);
    }
}
