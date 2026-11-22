<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\Factory;
use stdClass;

use function Async\suspend;

/**
 * The presence verifier behind `unique:` and `exists:` is one object per worker, and a rule
 * naming a connection sets it on that object before the query. This pins why that is safe: the
 * verifier reads the connection as it builds the query, in the same step as the set, and
 * whatever suspends afterwards, the query included, runs on the connection already chosen.
 */
class ValidationPresenceTest extends AsyncTestCase
{
    public function test_a_rule_on_another_connection_keeps_it_while_its_query_suspends(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'shard');

        foreach (['default', 'shard'] as $name) {
            $capsule->getConnection($name)->getSchemaBuilder()->create('accounts', function (Blueprint $table) {
                $table->increments('id');
            });
        }

        $capsule->getConnection('shard')->table('accounts')->insert(['id' => 7]);

        $gate = new stdClass();
        $gate->inside = false;
        $gate->done = false;
        $capsule->getConnection('shard')->beforeExecuting(function () use ($gate) {
            $gate->inside = true;

            while (! $gate->done) {
                suspend();
            }
        });

        $factory = new Factory(new Translator(new ArrayLoader(), 'en'));
        $factory->setPresenceVerifier(new DatabasePresenceVerifier($capsule->getDatabaseManager()));

        $results = $this->runParallel([
            'shard' => fn () => $factory->make(['id' => 7], ['id' => 'exists:shard.accounts,id'])->passes(),
            'default' => function () use ($factory, $gate) {
                while (! $gate->inside) {
                    suspend();
                }

                try {
                    return $factory->make(['id' => 7], ['id' => 'exists:accounts,id'])->passes();
                } finally {
                    $gate->done = true;
                }
            },
        ]);

        $this->assertSame(['shard' => true, 'default' => false], $results);
    }
}
