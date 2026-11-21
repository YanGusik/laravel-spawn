<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Spawn\Laravel\Tests\Fixtures\WindowRow;
use stdClass;

use function Async\await;
use function Async\spawn;
use function Async\suspend;

/**
 * Four more Eloquent decisions that live in class statics must hold for the coroutine that made
 * them and for no other: timestamps (`withoutTimestamps()`), touching (`withoutTouching()`),
 * broadcasting (`withoutBroadcasting()`) and the strict-mode switch `isset()` turns off for the
 * length of one attribute read (`Model::offsetExists()`).
 *
 * Two of them are worse than a leak for the length of a window. `withoutBroadcasting()` and
 * `offsetExists()` save the static, write it and restore the saved value, so two overlapping
 * calls that leave in the order they came in restore the inner one's saved value: the switch
 * stays off for the rest of the worker. The cases named `..._stays_on_after_two_overlapping_...`
 * pin that.
 *
 * A coroutine spawned inside a window does not inherit it, as with the other windows of this
 * package; the child cases pin that so the day it changes, it changes on purpose.
 */
class EloquentModelWindowsTest extends AsyncTestCase
{
    use HoldsAForeignWindow;

    protected function setUp(): void
    {
        parent::setUp();

        Model::preventAccessingMissingAttributes(false);
        WindowRow::$broadcastAsked = [];

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        Capsule::schema()->create('window_rows', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Model::preventAccessingMissingAttributes(false);

        parent::tearDown();
    }

    // --- Timestamps ------------------------------------------------------------------------

    public function test_a_sibling_still_writes_its_timestamps(): void
    {
        $seen = $this->insideAForeignWindow(
            static fn () => WindowRow::withoutTimestamps(...),
            ['sibling' => static fn () => WindowRow::find(WindowRow::create(['name' => 's'])->id)->created_at],
        );

        $this->assertNotNull($seen['sibling'], 'only the coroutine inside withoutTimestamps() skips them');
    }

    public function test_the_coroutine_inside_without_timestamps_saves_without_them(): void
    {
        $createdAt = WindowRow::withoutTimestamps(static function () {
            suspend();

            return WindowRow::find(WindowRow::create(['name' => 'quiet'])->id)->created_at;
        });

        $this->assertNull($createdAt);
    }

    public function test_a_nested_without_timestamps_does_not_close_the_outer_one(): void
    {
        $inside = WindowRow::withoutTimestamps(static function () {
            WindowRow::withoutTimestamps(static fn () => null);

            return WindowRow::isIgnoringTimestamps();
        });

        $this->assertTrue($inside);
        $this->assertFalse(WindowRow::isIgnoringTimestamps(), 'the window closes when the outer call returns');
    }

    public function test_a_child_coroutine_does_not_inherit_the_timestamps_window(): void
    {
        $inside = WindowRow::withoutTimestamps(static fn () => await(spawn(
            static fn () => WindowRow::isIgnoringTimestamps()
        )));

        $this->assertFalse($inside, 'the window belongs to the coroutine that opened it');
    }

    // --- Touching --------------------------------------------------------------------------

    public function test_a_sibling_still_touches(): void
    {
        $seen = $this->insideAForeignWindow(
            static fn () => static fn (callable $callback) => WindowRow::withoutTouching($callback),
            ['sibling' => static fn () => WindowRow::isIgnoringTouch()],
        );

        $this->assertFalse($seen['sibling'], 'only the coroutine inside withoutTouching() skips touches');
    }

    public function test_the_coroutine_inside_without_touching_ignores_touches(): void
    {
        $inside = null;

        WindowRow::withoutTouching(static function () use (&$inside) {
            suspend();

            $inside = WindowRow::isIgnoringTouch();
        });

        $this->assertTrue($inside);
    }

    /**
     * Upstream closes a window by taking its classes out of the shared list, which takes them
     * out of an enclosing window on the same class as well.
     */
    public function test_a_nested_without_touching_does_not_close_the_outer_one(): void
    {
        $inside = null;

        WindowRow::withoutTouching(static function () use (&$inside) {
            WindowRow::withoutTouching(static fn () => null);

            $inside = WindowRow::isIgnoringTouch();
        });

        $this->assertTrue($inside, 'the inner call returns into a window that is still open');
        $this->assertFalse(WindowRow::isIgnoringTouch());
    }

    // --- Broadcasting ----------------------------------------------------------------------

    public function test_a_sibling_still_broadcasts(): void
    {
        $seen = $this->insideAForeignWindow(
            static fn () => Model::withoutBroadcasting(...),
            ['sibling' => static fn () => (new WindowRow())->probeBroadcast()],
        );

        $this->assertTrue($seen['sibling'], 'only the coroutine inside withoutBroadcasting() is silent');
    }

    public function test_the_coroutine_inside_without_broadcasting_is_silent(): void
    {
        $broadcast = Model::withoutBroadcasting(static function () {
            suspend();

            return (new WindowRow())->probeBroadcast();
        });

        $this->assertFalse($broadcast);
    }

    public function test_broadcasting_stays_on_after_two_overlapping_windows(): void
    {
        $this->overlap(static fn (callable $inside) => Model::withoutBroadcasting($inside));

        $this->assertTrue((new WindowRow())->probeBroadcast(), 'broadcasting must not stay off for the worker');
    }

    /**
     * The cost of a window held per coroutine: a parallel import inside withoutBroadcasting()
     * broadcasts from every child.
     */
    public function test_a_child_coroutine_does_not_inherit_the_broadcasting_window(): void
    {
        $inside = Model::withoutBroadcasting(static fn () => await(spawn(
            static fn () => (new WindowRow())->probeBroadcast()
        )));

        $this->assertTrue($inside, 'the window belongs to the coroutine that opened it');
    }

    // --- The missing-attribute switch around isset() ---------------------------------------

    public function test_a_sibling_still_refuses_a_missing_attribute_while_another_reads_through_isset(): void
    {
        Model::preventAccessingMissingAttributes();

        $seen = $this->insideAForeignWindow(
            static fn () => static function (callable $callback) {
                $row = WindowRow::stored();
                $row->onRead = $callback;

                return isset($row->slow);
            },
            ['sibling' => static function () {
                try {
                    return WindowRow::stored()->missing;
                } catch (MissingAttributeException) {
                    return 'refused';
                }
            }],
        );

        $this->assertSame('refused', $seen['sibling'], 'strict mode is off only inside the isset() that turned it off');
    }

    public function test_strict_mode_stays_on_after_two_overlapping_isset_calls(): void
    {
        Model::preventAccessingMissingAttributes();

        $this->overlap(static function (callable $inside) {
            $row = WindowRow::stored();
            $row->onRead = $inside;

            return isset($row->slow);
        });

        $this->assertTrue(Model::preventsAccessingMissingAttributes(), 'strict mode must not stay off for the worker');
    }

    public function test_isset_on_a_missing_attribute_answers_false_in_strict_mode(): void
    {
        Model::preventAccessingMissingAttributes();

        $this->assertFalse(isset(WindowRow::stored()->missing));
        $this->assertTrue(Model::preventsAccessingMissingAttributes());
    }

    public function test_the_process_wide_strict_switch_still_reaches_every_coroutine(): void
    {
        Model::preventAccessingMissingAttributes();

        $seen = $this->runParallel(['request' => static fn () => Model::preventsAccessingMissingAttributes()]);

        $this->assertTrue($seen['request']);
    }

    /**
     * Two coroutines enter the same window and leave it in the order they entered: the first
     * leaves while the second is still inside. With a save-and-restore static the second then
     * restores the value the first had written, which is the switch turned off.
     *
     * @param  callable(callable): mixed  $enter  runs its argument inside the window
     */
    private function overlap(callable $enter): void
    {
        $gate = new stdClass();
        $gate->firstIn = false;
        $gate->secondIn = false;
        $gate->firstOut = false;

        $this->runParallel([
            'first' => function () use ($enter, $gate) {
                try {
                    $enter(function () use ($gate) {
                        $gate->firstIn = true;
                        $this->until(fn () => $gate->secondIn);
                    });
                } finally {
                    $gate->firstOut = true;
                }
            },
            'second' => function () use ($enter, $gate) {
                $this->until(fn () => $gate->firstIn);
                $enter(function () use ($gate) {
                    $gate->secondIn = true;
                    $this->until(fn () => $gate->firstOut);
                });
            },
        ]);
    }
}
