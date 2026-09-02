<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Process;
use ReflectionClass;
use Spawn\Laravel\Foundation\AsyncApplication;
use Spawn\Laravel\Process\AsyncProcessFactory;

use function Async\delay;

/**
 * Fake handlers, recorded processes and the stray-process guard belong to the request that
 * installed them.
 *
 * The factory is one object per worker on purpose — Laravel registers no binding for it, so
 * a rebuilt root would lose a stray guard a provider switched on at boot — and that is why
 * the state has to move: nothing resets it between requests, so on a shared object a
 * `Process::fake()` from one request answers every later one.
 */
class ProcessStateTest extends AsyncTestCase
{
    use BootsAsyncApplication;

    /**
     * The properties that stay on the shared object. Everything else Factory declares must be
     * in AsyncProcessFactory::REQUEST_STATE, or a Laravel upgrade has added state nobody placed.
     */
    private const WORKER_STATE = [
        // Macroable's own static: macros are registered once, for the class.
        'macros',
    ];

    public function test_every_property_of_the_framework_factory_is_placed(): void
    {
        $declared = array_map(
            static fn ($property) => $property->getName(),
            (new ReflectionClass(Factory::class))->getProperties()
        );

        $placed = array_merge(AsyncProcessFactory::REQUEST_STATE, self::WORKER_STATE);

        $this->assertSame([], array_values(array_diff($declared, $placed)),
            'Laravel added a property to Process\Factory; decide whether it is the request\'s or the worker\'s');
    }

    /**
     * B installs an empty fake with the stray guard on while A's fake is in place, so on a
     * shared object A's handler answers B; on isolated state B has no handler and the guard
     * throws.
     */
    public function test_a_fake_installed_by_one_request_does_not_answer_another(): void
    {
        $this->worker();

        $answers = $this->runParallel([
            'a' => function () {
                Process::fake(['*' => Process::result('from-A')]);
                delay(100);

                return trim(Process::run('echo a')->output());
            },
            'b' => function () {
                delay(50);
                Process::fake([])->preventStrayProcesses();

                try {
                    return trim(Process::run('echo b')->output());
                } catch (\RuntimeException) {
                    return 'no fake answered';
                }
            },
        ]);

        $this->assertSame('from-A', $answers['a']);
        $this->assertSame('no fake answered', $answers['b']);
    }

    public function test_a_later_request_does_not_inherit_the_fake(): void
    {
        $this->worker();

        $this->inRequest(function () {
            Process::fake(['*' => Process::result('from-A')]);

            return trim(Process::run('echo a')->output());
        });

        $answer = $this->inRequest(function () {
            Process::fake([])->preventStrayProcesses();

            try {
                return trim(Process::run('echo b')->output());
            } catch (\RuntimeException) {
                return 'no fake answered';
            }
        });

        $this->assertSame('no fake answered', $answer);
    }

    public function test_recorded_processes_stay_in_the_request_that_ran_them(): void
    {
        $this->worker();

        $this->runParallel([
            'a' => function () {
                Process::fake();
                Process::run('echo a');
                delay(100);

                Process::assertRan('echo a');
                Process::assertNotRan('echo b');
            },
            'b' => function () {
                Process::fake();
                Process::run('echo b');
                delay(100);

                Process::assertRan('echo b');
                Process::assertNotRan('echo a');
            },
        ]);
    }

    private function worker(): AsyncApplication
    {
        $app = $this->bootedApp([]);
        $app->enableAsyncMode();

        return $app;
    }
}
