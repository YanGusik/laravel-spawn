<?php

namespace Spawn\Laravel\Tests;

use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Spawn\Laravel\Foundation\RequestContext;
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

    private function worker(): \Spawn\Laravel\Foundation\AsyncApplication
    {
        $app = $this->bootedApp([]);
        $app->instance('env', 'testing');
        $app['config']->set('sentry', ['dsn' => null, 'tracing' => []]);
        $app->register(\Sentry\Laravel\ServiceProvider::class);
        $app->make(HubInterface::class);
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

        $this->assertNull($this->inRequest(self::served(static function (): void {
            SentryRequest::end();
        })));
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
