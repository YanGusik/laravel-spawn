<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Session;
use Spawn\Laravel\Foundation\AsyncApplication;

class ScopedSeederTest extends AsyncTestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    /**
     * A container that has not started serving yet, with 'widgets' declared scoped.
     *
     * @param  string[]  $scoped
     */
    private function container(array $scoped = ['widgets']): AsyncApplication
    {
        $app = new AsyncApplication(sys_get_temp_dir());
        $app->instance('config', new Repository(['async' => ['scoped_services' => $scoped]]));

        return $app;
    }

    public function test_extend_decorates_the_instance_each_coroutine_receives(): void
    {
        $app = $this->container();
        $app->bind('widgets', fn () => new Widget());
        $app->extend('widgets', function (Widget $widget) {
            $widget->decorated = true;

            return $widget;
        });

        $app->enableAsyncMode();

        $results = $this->runParallel(['a' => fn () => $app->make('widgets')]);

        $this->assertTrue($results['a']->decorated, 'extend() is part of how a service is built');
    }

    public function test_extend_registered_after_the_first_resolve_still_decorates(): void
    {
        $app = $this->container();
        $app->singleton('widgets', fn () => new Widget());

        /* A provider that resolves the service and only then decorates it. */
        $app->make('widgets');
        $app->extend('widgets', function (Widget $widget) {
            $widget->decorated = true;

            return $widget;
        });

        $app->enableAsyncMode();

        $results = $this->runParallel(['a' => fn () => $app->make('widgets')]);

        $this->assertTrue($results['a']->decorated, 'a scoped service is resolved again after extend()');
    }

    public function test_extend_registered_while_serving_reaches_later_coroutines(): void
    {
        $app = $this->container();
        $app->singleton('widgets', fn () => new Widget());
        $app->make('widgets');
        $app->enableAsyncMode();

        /* A deferred provider loaded inside the first request decorates the service. */
        $this->runParallel(['first' => function () use ($app) {
            $app->extend('widgets', function (Widget $widget) {
                $widget->decorated = true;

                return $widget;
            });
        }]);

        $results = $this->runParallel(['later' => fn () => $app->make('widgets')]);

        $this->assertTrue($results['later']->decorated);
    }

    public function test_scoped_bindings_of_the_framework_are_resolved_per_coroutine(): void
    {
        $app = $this->container([]);

        /* Laravel's own request-scoped registration, which only a queue worker flushes. */
        $app->scoped('widgets', fn () => new Widget());
        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => fn () => $app->make('widgets'),
            'b' => fn () => $app->make('widgets'),
        ]);

        $this->assertNotSame($results['a'], $results['b']);
    }

    public function test_a_class_marked_scoped_by_attribute_is_resolved_per_coroutine(): void
    {
        $app = $this->container([]);
        $app->enableAsyncMode();

        /* The container discovers the attribute on first resolve, long after startup. */
        $results = $this->runParallel([
            'a' => fn () => $app->make(AttributeScopedWidget::class),
            'b' => fn () => $app->make(AttributeScopedWidget::class),
        ]);

        $this->assertNotSame($results['a'], $results['b']);
    }

    public function test_a_scoped_binding_given_as_a_closure_does_not_break_startup(): void
    {
        $app = $this->container([]);
        $app->scoped(fn (): Widget => new Widget());

        $app->enableAsyncMode();

        $results = $this->runParallel(['a' => fn () => $app->make('config')]);

        $this->assertNotNull($results['a'], 'a closure in scopedInstances must not take the worker down');
    }

    public function test_a_scoped_binding_given_as_a_closure_is_resolved_per_coroutine(): void
    {
        $app = $this->container([]);
        $app->scoped(fn (): Widget => new Widget());

        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => fn () => [$app->make(Widget::class), $app->make(Widget::class)],
            'b' => fn () => $app->make(Widget::class),
        ]);

        $this->assertSame($results['a'][0], $results['a'][1], 'one object within a request');
        $this->assertNotSame($results['a'][0], $results['b'], 'the return type names a per-request service');
    }

    public function test_a_closure_with_a_union_return_type_marks_every_type_per_coroutine(): void
    {
        $app = $this->container([]);
        $app->scoped(fn (): Widget|Gadget => new Widget());

        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => fn () => [$app->make(Widget::class), $app->make(Gadget::class)],
            'b' => fn () => [$app->make(Widget::class), $app->make(Gadget::class)],
        ]);

        $this->assertNotSame($results['a'][0], $results['b'][0]);
        $this->assertNotSame($results['a'][1], $results['b'][1]);
    }

    public function test_a_service_replaced_with_instance_survives_a_later_extend(): void
    {
        $app = $this->container();
        $app->singleton('widgets', fn () => new Widget());
        $app->make('widgets');
        $app->enableAsyncMode();

        $replacement = new Widget('replacement');
        $app->instance('widgets', $replacement);
        $app->extend('widgets', fn (Widget $widget) => $widget);

        $results = $this->runParallel(['a' => fn () => $app->make('widgets')]);

        $this->assertSame($replacement, $results['a'], 'instance() names the object to use');
    }

    public function test_a_resolve_outside_a_request_is_not_inherited_by_later_ones(): void
    {
        $app = $this->container();
        $app->singleton('widgets', fn () => new Widget());
        $app->enableAsyncMode();

        /* The worker resolving something for itself, outside any request. */
        $atRoot = $app->make('widgets');

        $results = $this->runParallel([
            'a' => fn () => $app->make('widgets'),
            'b' => fn () => $app->make('widgets'),
        ]);

        $this->assertNotSame($atRoot, $results['a'], 'the root must not hand its object to a request');
        $this->assertNotSame($results['a'], $results['b']);
    }

    public function test_a_service_declared_scoped_by_class_name_is_scoped(): void
    {
        $app = $this->container([Widget::class]);
        $app->bind('widgets', fn () => new Widget());
        $app->alias('widgets', Widget::class);

        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => fn () => $app->make(Widget::class),
            'b' => fn () => $app->make(Widget::class),
        ]);

        $this->assertNotSame($results['a'], $results['b'], 'config/async.php is written in class names');
    }

    public function test_a_build_with_parameters_is_not_served_from_the_context(): void
    {
        $app = $this->container();
        $app->bind('widgets', fn ($container, array $parameters) => new Widget($parameters['mark'] ?? null));

        $app->enableAsyncMode();

        $results = $this->runParallel(['a' => function () use ($app) {
            $app->make('widgets');

            return $app->makeWith('widgets', ['mark' => 'built-for-me']);
        }]);

        $this->assertSame('built-for-me', $results['a']->mark, 'parameters must reach the factory');
    }

    public function test_after_resolving_callback_fires_once_per_instance(): void
    {
        $app = $this->container();
        $app->bind('widgets', fn () => new Widget());

        $calls = 0;
        $app->afterResolving('widgets', function () use (&$calls) {
            $calls++;
        });

        $app->enableAsyncMode();

        $this->runParallel(['a' => fn () => $app->make('widgets')]);
        $this->assertSame(1, $calls);

        $this->runParallel(['b' => fn () => $app->make('widgets')]);
        $this->assertSame(2, $calls, 'one call per instance handed out, no more and no fewer');
    }

    public function test_a_callback_may_resolve_the_service_it_is_called_for(): void
    {
        $app = $this->container();
        $app->bind('widgets', fn () => new Widget());

        $resolvedFromCallback = null;
        $app->afterResolving('widgets', function ($widget, $container) use (&$resolvedFromCallback) {
            $resolvedFromCallback = $container->make('widgets');
        });

        $app->enableAsyncMode();

        $results = $this->runParallel(['a' => fn () => $app->make('widgets')]);

        $this->assertSame($results['a'], $resolvedFromCallback, 're-entrant resolution must reach the same instance');
    }

    public function test_seeder_carries_boot_time_registrations_into_every_coroutine(): void
    {
        $app = $this->container();
        $app->singleton('widgets', fn () => new Widget());
        $app->scopedSeeder('widgets', fn (Widget $fresh, Widget $bootTime) => $fresh->register($bootTime->registered()));

        $bootTime = $app->make('widgets');
        $bootTime->register(['at-boot']);

        $app->enableAsyncMode();

        $use = fn (string $mark) => function () use ($app, $mark) {
            $widget = $app->make('widgets');
            $widget->register([$mark]);

            return $widget->registered();
        };

        $results = $this->runParallel(['a' => $use('per-request-a'), 'b' => $use('per-request-b')]);

        $this->assertSame(['at-boot', 'per-request-a'], $results['a']);
        $this->assertSame(['at-boot', 'per-request-b'], $results['b'], 'one coroutine must not see another one');
        $this->assertSame(['at-boot'], $bootTime->registered(), 'serving must not write back into the template');
    }

    public function test_seeder_is_skipped_when_bootstrap_left_nothing_behind(): void
    {
        $app = $this->container();
        $app->singleton('widgets', fn () => new Widget());

        $seederCalls = 0;
        $app->scopedSeeder('widgets', function () use (&$seederCalls) {
            $seederCalls++;
        });

        $app->enableAsyncMode();

        $results = $this->runParallel(['a' => fn () => $app->make('widgets')]);

        $this->assertInstanceOf(Widget::class, $results['a']);
        $this->assertSame(0, $seederCalls, 'a service never resolved at boot carries no configuration');
    }

    /**
     * A decorator is what the coroutine gets, and it is what the seeder configures.
     *
     * An extender is free to return a different object — Laravel's own docs show
     * exactly that. The registrations bootstrap made have to land on the object the
     * coroutine will actually use, which is the replacement and not the object the
     * factory built and the extender threw away.
     */
    public function test_a_replacing_extender_is_the_object_the_seeder_configures(): void
    {
        $app = $this->container();
        $app->singleton('widgets', fn () => new Widget('from-factory'));
        $app->extend('widgets', fn (Widget $widget) => new DecoratedWidget($widget));
        $app->scopedSeeder('widgets', fn (Widget $fresh, Widget $bootTime) => $fresh->register($bootTime->registered()));

        $app->make('widgets')->register(['at-boot']);

        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => fn () => $app->make('widgets'),
            'b' => fn () => $app->make('widgets'),
        ]);

        $this->assertInstanceOf(DecoratedWidget::class, $results['a'], 'the replacement is the service');
        $this->assertSame('from-factory', $results['a']->inner->mark, 'the factory still builds what it wraps');
        $this->assertSame(
            ['at-boot'],
            $results['a']->registered(),
            'the seeder must configure the replacement, not the object it replaced',
        );
        $this->assertSame([], $results['a']->inner->registered(), 'the discarded object is nobody service');
        $this->assertNotSame($results['a'], $results['b'], 'a replacement is built per coroutine like any other');
    }

    /**
     * An extender registered while serving replaces the template too.
     *
     * A seeder recognises what it is handed by type — ManagerRegistrations does exactly
     * that. Leave the template undecorated and it is handed an object of the old shape,
     * while every coroutine holds one of the new.
     */
    public function test_an_extender_registered_while_serving_replaces_the_seeding_template(): void
    {
        $app = $this->container();
        $app->singleton('widgets', fn () => new Widget('from-factory'));

        $seededFrom = [];
        $app->scopedSeeder('widgets', function (Widget $fresh, Widget $bootTime) use (&$seededFrom) {
            $seededFrom[] = $bootTime::class;
        });

        $app->make('widgets');
        $app->enableAsyncMode();

        /* A deferred provider loaded inside the first request replaces the service. */
        $this->runParallel(['first' => function () use ($app) {
            $app->extend('widgets', fn (Widget $widget) => new DecoratedWidget($widget));
        }]);

        $results = $this->runParallel(['later' => fn () => $app->make('widgets')]);

        $this->assertInstanceOf(DecoratedWidget::class, $results['later']);
        $this->assertSame(
            [DecoratedWidget::class],
            $seededFrom,
            'the template a coroutine is seeded from carries the same decoration it does',
        );
    }

    /**
     * config/async.php is written in class names; the seeder may be too.
     *
     * Registered under a name the container never resolves by, the seeder is simply
     * never found, and the service is served unconfigured with nothing to show for it.
     */
    public function test_a_seeder_registered_under_a_class_name_still_runs(): void
    {
        $app = $this->container([Widget::class]);
        $app->singleton('widgets', fn () => new Widget());
        $app->alias('widgets', Widget::class);
        $app->scopedSeeder(Widget::class, fn (Widget $fresh, Widget $bootTime) => $fresh->register($bootTime->registered()));

        $app->make('widgets')->register(['at-boot']);

        $app->enableAsyncMode();

        $results = $this->runParallel(['a' => fn () => $app->make(Widget::class)]);

        $this->assertSame(['at-boot'], $results['a']->registered());
    }

    /**
     * The same, for the scoped list itself, with a service that is genuinely shared.
     *
     * The existing class-name test binds its service with bind(), which hands out a new
     * object on every resolve whether the container scopes it or not; only a singleton
     * can tell the two apart.
     */
    public function test_a_singleton_declared_scoped_by_class_name_is_resolved_per_coroutine(): void
    {
        $app = $this->container([Widget::class]);
        $app->singleton('widgets', fn () => new Widget());
        $app->alias('widgets', Widget::class);

        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => fn () => $app->make(Widget::class),
            'b' => fn () => $app->make(Widget::class),
        ]);

        $this->assertNotSame($results['a'], $results['b'], 'config/async.php is written in class names');
    }

    /**
     * Two coroutines of one request see one instance, even when both build it.
     *
     * A request is not one coroutine: a handler spawns more, and they all share the
     * request's services. A factory that yields — every real one does, it talks to the
     * database — lets a sibling reach the same alias before the first has published
     * anything, and the loser of that race must be dropped rather than handed out.
     */
    public function test_siblings_of_one_request_share_the_instance_built_first(): void
    {
        $app = $this->container();

        $built = 0;
        $app->bind('widgets', function () use (&$built) {
            /* Stands in for the I/O a real factory does while building. */
            \Async\delay(10);
            $built++;

            return new Widget();
        });

        $resolved = 0;
        $app->afterResolving('widgets', function () use (&$resolved) {
            $resolved++;
        });

        $app->enableAsyncMode();

        $results = $this->runSiblings([
            'a' => fn () => $app->make('widgets'),
            'b' => fn () => $app->make('widgets'),
        ]);

        $this->assertSame(2, $built, 'the test is worthless unless both coroutines really raced');
        $this->assertSame($results['a'], $results['b'], 'one request, one instance');
        $this->assertSame(1, $resolved, 'the instance nobody receives must not be announced as resolved');
    }

    /**
     * Coroutines of one and the same scope — siblings within a request, as opposed to
     * {@see AsyncTestCase::runParallel}, which gives each closure a scope of its own
     * and so models separate requests.
     */
    private function runSiblings(array $coroutines): array
    {
        $results = [];
        $scope = new \Async\Scope();

        foreach ($coroutines as $key => $fn) {
            $scope->spawn(function () use ($key, $fn, &$results) {
                $results[$key] = $fn();
            });
        }

        $scope->awaitCompletion(\Async\timeout(5000));

        return $results;
    }

    public function test_facade_is_not_pinned_to_the_boot_time_instance(): void
    {
        $app = $this->container([]);
        $app->bind('session', fn () => new Widget());

        Facade::setFacadeApplication($app);
        $bootId = Session::identify();

        $app->enableAsyncMode();

        $results = $this->runParallel([
            'a' => fn () => Session::identify(),
            'b' => fn () => Session::identify(),
        ]);

        $this->assertNotSame($results['a'], $results['b'], 'the facade must reach each coroutine own instance');
        $this->assertNotSame($bootId, $results['a']);
    }
}

/**
 * A class the container has never been told about, marked per-request the way an
 * application marks its own.
 */
#[\Illuminate\Container\Attributes\Scoped]
class AttributeScopedWidget
{
}

/**
 * Stand-in for a manager: something registered once at boot, something written per
 * request, and an identity a test can compare.
 */
class Widget
{
    public bool $decorated = false;

    private array $registered = [];

    public function __construct(public readonly ?string $mark = null)
    {
    }

    public function register(array $names): void
    {
        $this->registered = array_merge($this->registered, $names);
    }

    public function registered(): array
    {
        return $this->registered;
    }

    public function identify(): int
    {
        return spl_object_id($this);
    }
}

/**
 * What a replacing extender returns: a different object, of a different type, wrapping
 * the one the factory built.
 */
class DecoratedWidget extends Widget
{
    public function __construct(public readonly Widget $inner)
    {
        parent::__construct('decorated');
    }
}

class Gadget
{
}
