<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use ReflectionProperty;
use Spawn\Laravel\Database\Eloquent\EloquentOverrides;
use Spawn\Laravel\Tests\Fixtures\RelationItem;
use Spawn\Laravel\Tests\Fixtures\RelationOwner;
use stdClass;

use function Async\suspend;

/**
 * Eloquent switches Relation::$constraints off while it builds a relation for an eager load,
 * and back on afterwards. The property is thread state shared by every coroutine of a worker,
 * and the callback it wraps yields as soon as a model boots against config or cache — so a
 * sibling coroutine builds its relations inside somebody else's window and loses its
 * `where foreign_key = ?`, and overlapping windows restore each other's captured value and
 * leave the flag off for the life of the worker.
 *
 * The fixtures are ordinary models with nothing opted into: the package replaces two Eloquent
 * classes, so every model of an application is covered, and a test model has to be as plain as
 * the ones an application writes for the cases here to mean anything.
 *
 * The interleaves are driven by handshakes rather than by sleeping for long enough — a
 * timing-based test that misses its interleave passes, and passes for the wrong reason.
 */
class EloquentOverridesTest extends AsyncTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (new ReflectionProperty(Relation::class, 'constraints'))->setValue(null, true);

        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        Capsule::schema()->create('relation_owners', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('owner_key');
        });

        Capsule::schema()->create('relation_items', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('owner_id');
        });

        Capsule::table('relation_owners')->insert([
            ['id' => 1, 'owner_key' => 1],
            ['id' => 2, 'owner_key' => 2],
        ]);

        Capsule::schema()->create('relation_tags', function (Blueprint $table) {
            $table->increments('id');
        });

        Capsule::schema()->create('relation_owner_tag', function (Blueprint $table) {
            $table->integer('owner_id');
            $table->integer('tag_id');
        });

        Capsule::schema()->create('relation_notes', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('item_id');
        });

        Capsule::table('relation_items')->insert([
            ['id' => 1, 'owner_id' => 1],
            ['id' => 2, 'owner_id' => 2],
            ['id' => 3, 'owner_id' => 2],
        ]);

        Capsule::table('relation_tags')->insert([['id' => 1], ['id' => 2], ['id' => 3]]);

        Capsule::table('relation_owner_tag')->insert([
            ['owner_id' => 1, 'tag_id' => 1],
            ['owner_id' => 2, 'tag_id' => 2],
            ['owner_id' => 2, 'tag_id' => 3],
        ]);

        Capsule::table('relation_notes')->insert([
            ['id' => 1, 'item_id' => 1],
            ['id' => 2, 'item_id' => 2],
            ['id' => 3, 'item_id' => 3],
        ]);
    }

    public function test_the_copies_are_in_front_of_laravels_classes(): void
    {
        $this->assertSame('installed', EloquentOverrides::status());
    }

    /**
     * Each copy is frozen against the variant of the Laravel file it was taken from. When a
     * Laravel update moves a file to a variant no copy was taken from, this is where it is
     * noticed: run bin/refresh-eloquent-overrides.php, add the checksum it prints, and check
     * the edits are still in the new copy.
     */
    public function test_the_copies_still_match_the_laravel_files_behind_them(): void
    {
        foreach (EloquentOverrides::frozenAgainst() as $class => [$file, $checksums]) {
            $this->assertNotNull($file, "Laravel's own file for $class was not found");
            $this->assertContains(hash_file('sha256', $file), $checksums,
                "Laravel's $class has moved on; a copy of the new variant has to be taken under overrides/");
        }
    }

    public function test_every_variant_listed_has_its_copy_in_the_package(): void
    {
        foreach (EloquentOverrides::frozenAgainst() as $class => [, $checksums]) {
            foreach ($checksums as $checksum) {
                $this->assertNotNull(EloquentOverrides::copyFor($class, $checksum),
                    "the copy of $class taken from the variant $checksum is missing from overrides/");
            }
        }
    }

    public function test_a_variant_no_copy_was_taken_from_has_no_copy(): void
    {
        $this->assertNull(EloquentOverrides::copyFor(Relation::class, str_repeat('0', 64)));
        $this->assertNull(EloquentOverrides::copyFor('Illuminate\\Database\\Eloquent\\Model', str_repeat('0', 64)));
    }

    public function test_sibling_coroutine_keeps_its_where_clause(): void
    {
        $results = $this->insideAForeignWindow([
            'owner-1' => fn () => RelationOwner::find(1)->items()->count(),
        ]);

        $this->assertSame(1, $results['owner-1'], 'a sibling must see its own row, not every owner\'s');
    }

    public function test_one_of_many_keeps_its_where_clause(): void
    {
        $results = $this->insideAForeignWindow([
            'owner' => fn () => RelationOwner::find(2)->latestItem()->first()?->id,
        ]);

        $this->assertSame(3, $results['owner'], 'latestOfMany() constrains a subquery of its own');
    }

    public function test_a_relation_survives_a_yield_inside_add_constraints(): void
    {
        $results = $this->insideAForeignWindow([
            // The accessor of the local key suspends in the middle of addConstraints(), so the
            // constraint has to survive both the foreign window and the suspension.
            'owner' => fn () => RelationOwner::find(2)->slowItems()->count(),
        ]);

        $this->assertSame(2, $results['owner']);
    }

    public function test_the_shared_flag_no_longer_decides(): void
    {
        (new ReflectionProperty(Relation::class, 'constraints'))->setValue(null, false);

        $this->assertSame(1, RelationOwner::find(1)->items()->count());
    }

    public function test_one_of_a_has_many_is_still_built_without_constraints(): void
    {
        $one = RelationOwner::find(2)->items()->one();

        $this->assertSame(2, $one->first()?->id, 'one() narrows the relation of this owner, not of the table');
    }

    public function test_eager_loading_still_asks_for_every_owner_at_once(): void
    {
        Capsule::connection()->enableQueryLog();

        $owners = RelationOwner::with('items')->get();

        $log = Capsule::connection()->getQueryLog();

        $this->assertCount(2, $log, 'one query for the owners and one for all their items');
        $this->assertStringContainsString(' in (', $log[1]['query']);
        $this->assertStringNotContainsString('"owner_id" = ?', $log[1]['query']);
        $this->assertSame(1, $owners[0]->items->count());
        $this->assertSame(2, $owners[1]->items->count());
    }

    public function test_eager_loading_a_pivot_relation_still_joins(): void
    {
        $owners = RelationOwner::with('tags')->get();

        $this->assertSame(1, $owners[0]->tags->count());
        $this->assertSame(2, $owners[1]->tags->count());
    }

    public function test_eager_loading_through_a_second_table_still_joins(): void
    {
        $owners = RelationOwner::with('notes')->get();

        $this->assertSame(1, $owners[0]->notes->count());
        $this->assertSame(2, $owners[1]->notes->count());
    }

    public function test_a_pivot_relation_keeps_its_where_clause(): void
    {
        $results = $this->insideAForeignWindow([
            'owner' => fn () => RelationOwner::find(1)->tags()->count(),
        ]);

        $this->assertSame(1, $results['owner']);
    }

    public function test_lazy_eager_loading_still_groups_by_parent(): void
    {
        $owners = RelationOwner::all();
        $owners->load('items', 'tags', 'notes');

        $this->assertSame(1, $owners[0]->items->count());
        $this->assertSame(2, $owners[1]->items->count());
        $this->assertSame(2, $owners[1]->tags->count());
        $this->assertSame(2, $owners[1]->notes->count());
    }

    public function test_where_has_still_filters(): void
    {
        $this->assertSame(1, RelationOwner::has('items', '>=', 2)->count());
        $this->assertSame(2, RelationOwner::withCount('items')->get()[1]->items_count);
    }

    public function test_lazy_load_is_constrained_outside_any_window(): void
    {
        $this->assertSame(1, RelationOwner::find(1)->items()->count());
        $this->assertSame(2, RelationItem::query()->where('owner_id', 2)->count());
    }

    /**
     * Run each closure in a coroutine of its own while another coroutine holds a
     * noConstraints() window open, and hand back what each one returned.
     *
     * The window opens before any of them runs and closes only after the last has finished,
     * so a case that reads the flag reads it wrong — which is the point.
     *
     * @param  array<string, callable>  $cases
     * @return array<string, mixed>
     */
    private function insideAForeignWindow(array $cases): array
    {
        $gate = new stdClass();
        $gate->open = false;
        $gate->left = 0;

        $coroutines = ['window' => function () use ($gate, $cases) {
            Relation::noConstraints(function () use ($gate, $cases) {
                $gate->open = true;
                $this->until(fn () => $gate->left === count($cases));

                return null;
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
