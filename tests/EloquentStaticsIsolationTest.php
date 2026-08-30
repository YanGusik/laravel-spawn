<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Spawn\Laravel\Tests\Fixtures\GuardedRow;
use stdClass;

use function Async\suspend;

/**
 * Eloquent's two scoped statics — the mass-assignment switch and the event dispatcher — must
 * hold for the coroutine that opened them and for no other.
 *
 * `Model::unguarded()` and `Model::withoutEvents()` set a class static, run a callback and
 * restore it in a `finally`. A class static is one value per worker thread, so a callback that
 * suspends hands its state to every request served in the meantime: the first drops
 * mass-assignment protection, the second routes another request's model events into a
 * `NullDispatcher`, which answers every call and delivers nothing. The copies under
 * `overrides/` keep the value in the coroutine's own context instead.
 *
 * The unscoped `Model::unguard()` and `Model::setEventDispatcher()` still write the static,
 * because a caller that never restores it means the process, and bootstrap is such a caller.
 * The last two cases pin that half.
 *
 * The interleaves are driven by handshakes rather than by sleeping for long enough — a
 * timing-based test that misses its interleave passes, and passes for the wrong reason.
 */
class EloquentStaticsIsolationTest extends AsyncTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Model::reguard();
        Model::unsetEventDispatcher();

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        Capsule::schema()->create('guarded_rows', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->integer('admin')->nullable();
        });
    }

    protected function tearDown(): void
    {
        GuardedRow::flushEventListeners();
        Model::reguard();
        Model::unsetEventDispatcher();

        parent::tearDown();
    }

    public function test_a_sibling_is_still_guarded(): void
    {
        $seen = $this->insideAForeignWindow(
            static fn () => Model::unguarded(...),
            ['sibling' => static fn () => Model::isUnguarded()],
        );

        $this->assertFalse($seen['sibling'], 'the window belongs to the coroutine that opened it');
    }

    public function test_a_sibling_still_drops_an_attribute_it_may_not_fill(): void
    {
        $seen = $this->insideAForeignWindow(
            static fn () => Model::unguarded(...),
            ['sibling' => static fn () => self::fillablePart(['name' => 'ok', 'admin' => 1])],
        );

        $this->assertSame(['name' => 'ok'], $seen['sibling'], 'guarded attributes must stay guarded');
    }

    public function test_the_coroutine_that_opened_the_window_is_unguarded(): void
    {
        $inside = Model::unguarded(static function () {
            suspend();

            return [Model::isUnguarded(), self::fillablePart(['name' => 'ok', 'admin' => 1])];
        });

        $this->assertSame([true, ['name' => 'ok', 'admin' => 1]], $inside);
    }

    public function test_a_nested_window_does_not_close_the_outer_one(): void
    {
        $inside = Model::unguarded(static function () {
            Model::unguarded(static fn () => null);

            return Model::isUnguarded();
        });

        $this->assertTrue($inside, 'the inner call returns into a window that is still open');
    }

    public function test_a_sibling_still_gets_its_model_events(): void
    {
        $delivered = [];
        $dispatcher = new Dispatcher(new Container());
        $dispatcher->listen('probe', static function () use (&$delivered) {
            $delivered[] = 'probe';
        });
        Model::setEventDispatcher($dispatcher);

        $this->insideAForeignWindow(
            static fn () => Model::withoutEvents(...),
            ['sibling' => static fn () => Model::getEventDispatcher()->dispatch('probe')],
        );

        $this->assertSame(['probe'], $delivered, 'only the coroutine inside withoutEvents() is silenced');
    }

    public function test_the_coroutine_inside_without_events_is_silenced(): void
    {
        $delivered = [];
        $dispatcher = new Dispatcher(new Container());
        $dispatcher->listen('probe', static function () use (&$delivered) {
            $delivered[] = 'probe';
        });
        Model::setEventDispatcher($dispatcher);

        Model::withoutEvents(static function () {
            suspend();

            Model::getEventDispatcher()->dispatch('probe');
        });

        $this->assertSame([], $delivered);
    }

    /**
     * The event path a request actually takes: a save fires `creating` through the static
     * dispatcher, which is what an observer of an application hangs on.
     */
    public function test_a_sibling_still_fires_its_model_events_on_a_save(): void
    {
        $created = [];
        Model::setEventDispatcher(new Dispatcher(new Container()));
        GuardedRow::creating(static function ($row) use (&$created) {
            $created[] = $row->name;
        });

        $this->insideAForeignWindow(
            static fn () => Model::withoutEvents(...),
            ['sibling' => static fn () => GuardedRow::create(['name' => 'sibling'])->exists],
        );

        $this->assertSame(['sibling'], $created, 'the sibling\'s observers must still run');
    }

    public function test_the_coroutine_inside_without_events_saves_quietly(): void
    {
        $created = [];
        Model::setEventDispatcher(new Dispatcher(new Container()));
        GuardedRow::creating(static function ($row) use (&$created) {
            $created[] = $row->name;
        });

        $saved = Model::withoutEvents(static function () {
            suspend();

            return GuardedRow::create(['name' => 'quiet'])->exists;
        });

        $this->assertTrue($saved, 'the row is written; only the event is dropped');
        $this->assertSame([], $created);
    }

    /**
     * A coroutine spawned inside a window is outside it, which is what a context-local window
     * means. The case is here so that the day it changes, it changes on purpose.
     */
    public function test_a_child_coroutine_does_not_inherit_the_window(): void
    {
        $inside = Model::unguarded(static fn () => \Async\await(\Async\spawn(
            static fn () => Model::isUnguarded()
        )));

        $this->assertFalse($inside, 'the window belongs to the coroutine that opened it');
    }

    /**
     * `Model::unguard()` is how an application switches the guard off for the whole process —
     * a seeder, a service provider — and it has no callback to close.
     */
    public function test_the_process_wide_switch_still_reaches_every_coroutine(): void
    {
        Model::unguard();

        $seen = $this->runParallel(['request' => static fn () => Model::isUnguarded()]);

        $this->assertTrue($seen['request']);

        Model::reguard();

        $seen = $this->runParallel(['request' => static fn () => Model::isUnguarded()]);

        $this->assertFalse($seen['request']);
    }

    /**
     * `Model::setEventDispatcher()` is how bootstrap gives the worker its dispatcher, long
     * before any request is served.
     */
    public function test_the_process_wide_dispatcher_still_reaches_every_coroutine(): void
    {
        $dispatcher = new Dispatcher(new Container());
        Model::setEventDispatcher($dispatcher);

        $seen = $this->runParallel(['request' => static fn () => Model::getEventDispatcher()]);

        $this->assertSame($dispatcher, $seen['request']);
    }

    /**
     * What a model keeps of the given attributes. GuardedRow declares `name` fillable and
     * nothing else, so a pair of the two says whether the guard is on, and `fill()` answers
     * without touching the table.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function fillablePart(array $attributes): array
    {
        return (new GuardedRow())->fill($attributes)->getAttributes();
    }

    /**
     * Run the cases while another coroutine holds a window of the given kind open.
     *
     * The window opens first and closes only after every case has finished, so a case that
     * reads the static reads it from inside somebody else's window — the interleave the
     * defect needs.
     *
     * @param  callable(): callable  $window  gives the scoped method to hold open
     * @param  array<string, callable>  $cases
     * @return array<string, mixed>
     */
    private function insideAForeignWindow(callable $window, array $cases): array
    {
        $gate = new stdClass();
        $gate->open = false;
        $gate->left = 0;

        $coroutines = ['window' => function () use ($gate, $window, $cases) {
            ($window())(function () use ($gate, $cases) {
                $gate->open = true;
                $this->until(fn () => $gate->left === count($cases));
            });
        }];

        foreach ($cases as $name => $case) {
            $coroutines[$name] = function () use ($gate, $case) {
                $this->until(fn () => $gate->open);

                try {
                    return $case();
                } finally {
                    $gate->left++;
                }
            };
        }

        return $this->runParallel($coroutines);
    }

    /**
     * Yield until the condition holds. runParallel() caps the whole thing at five seconds, so
     * a condition that never becomes true fails the test instead of hanging the suite.
     */
    private function until(callable $condition): void
    {
        while (! $condition()) {
            suspend();
        }
    }
}
