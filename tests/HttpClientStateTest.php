<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Spawn\Laravel\Foundation\AsyncApplication;
use Spawn\Laravel\Http\Client\AsyncHttpFactory;

/**
 * Stubs, recordings and the stray-request guard belong to the request that installed them;
 * global middleware and global options belong to the worker.
 *
 * The factory is one object per worker on purpose — Laravel registers no binding for it, so
 * a rebuilt root would lose whatever a provider configured at boot — and that is exactly why
 * the other half of the object has to move: nothing resets it between requests, so
 * `Http::fake()` in one request answered every later one.
 */
class HttpClientStateTest extends AsyncTestCase
{
    use BootsAsyncApplication;

    /**
     * The properties that stay on the shared object. Everything else Factory declares must be
     * in AsyncHttpFactory::REQUEST_STATE, or a Laravel upgrade has added state nobody placed.
     */
    private const WORKER_STATE = [
        'dispatcher',
        'globalMiddleware',
        'globalOptions',
        // Macroable's own static: macros are registered once, for the class.
        'macros',
    ];

    public function test_every_property_of_the_framework_factory_is_placed(): void
    {
        $declared = array_map(
            static fn ($property) => $property->getName(),
            (new ReflectionClass(Factory::class))->getProperties()
        );

        $placed = array_merge(AsyncHttpFactory::REQUEST_STATE, self::WORKER_STATE);

        $this->assertSame([], array_values(array_diff($declared, $placed)),
            'Laravel added a property to Http\Client\Factory; decide whether it is the request\'s or the worker\'s');
    }

    public function test_a_stub_installed_by_one_request_does_not_answer_another(): void
    {
        $this->worker();

        $answers = $this->runParallel([
            'a' => function () {
                Http::fake(['*' => Http::response('from-A')]);

                return Http::get('https://example.invalid/a')->body();
            },
            'b' => function () {
                try {
                    return Http::preventStrayRequests()->get('https://example.invalid/b')->body();
                } catch (\Throwable) {
                    return 'no stub answered';
                }
            },
        ]);

        $this->assertSame('from-A', $answers['a']);
        $this->assertSame('no stub answered', $answers['b']);
    }

    public function test_a_later_request_does_not_inherit_the_stub(): void
    {
        $this->worker();

        $this->inRequest(function () {
            Http::fake(['*' => Http::response('from-A')]);

            return Http::get('https://example.invalid/a')->body();
        });

        $answer = $this->inRequest(function () {
            try {
                return Http::preventStrayRequests()->get('https://example.invalid/b')->body();
            } catch (\Throwable) {
                return 'no stub answered';
            }
        });

        $this->assertSame('no stub answered', $answer);
    }

    public function test_recorded_requests_stay_in_the_request_that_made_them(): void
    {
        $this->worker();

        $recorded = $this->runParallel([
            'a' => function () {
                Http::fake(['*' => Http::response('from-A')]);
                Http::get('https://example.invalid/a');

                return Http::recorded()->count();
            },
            'b' => function () {
                Http::fake(['*' => Http::response('from-B')]);
                Http::get('https://example.invalid/b');

                return Http::recorded()->count();
            },
        ]);

        $this->assertSame([1, 1], array_values($recorded), 'each request records its own call and no other');
    }

    /**
     * Global middleware is what the shared object exists for: a provider adds it at boot, and
     * every request's outbound calls must carry it.
     */
    public function test_global_middleware_reaches_every_request(): void
    {
        $app = $this->worker();
        $factory = $app->make(Factory::class);
        $factory->globalRequestMiddleware(fn ($request) => $request->withHeader('X-Worker', 'yes'));

        $headers = $this->runParallel([
            'a' => function () {
                Http::fake(['*' => Http::response('ok')]);
                Http::get('https://example.invalid/a');

                return Http::recorded()->first()[0]->header('X-Worker');
            },
            'b' => function () {
                Http::fake(['*' => Http::response('ok')]);
                Http::get('https://example.invalid/b');

                return Http::recorded()->first()[0]->header('X-Worker');
            },
        ]);

        $this->assertSame([['yes'], ['yes']], array_values($headers));
    }

    private function worker(): AsyncApplication
    {
        $app = $this->bootedApp([]);
        $app->enableAsyncMode();

        return $app;
    }
}
