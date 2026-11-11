<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Events\Dispatcher;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;

use function Async\delay;

class TelescopeIsolationTest extends AsyncTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The override Telescope class lives in overrides/ and is normally loaded
        // by a prepending autoloader registered in AsyncServiceProvider::register().
        // In unit tests we load it directly so the class is available.
        require_once \Spawn\Laravel\Telescope\TelescopeOverride::file(__DIR__ . '/../vendor/laravel/telescope/src/Telescope.php');

        // Reset Telescope state
        Telescope::$entriesQueue = [];
        Telescope::$updatesQueue = [];
        Telescope::$shouldRecord = false;
        Telescope::$filterUsing = [];
        Telescope::$tagUsing = [];

        // isRecording() needs 'events' service
        $app = $this->createApp();
        $app->singleton('events', fn ($app) => new Dispatcher($app));
    }

    // ── Stock behavior (asyncMode off): prove the bug ──

    public function test_stock_entries_queue_leaks_between_coroutines(): void
    {
        Telescope::$shouldRecord = true;

        $results = $this->runParallel([
            'a' => function () {
                Telescope::$entriesQueue[] = 'entry-a';
                delay(200);
                return Telescope::$entriesQueue;
            },
            'b' => function () {
                delay(50);
                Telescope::$entriesQueue[] = 'entry-b';
                return Telescope::$entriesQueue;
            },
        ]);

        // BUG: B sees A's entries in shared static array
        $this->assertContains('entry-a', $results['b'],
            'BUG: coroutine B sees coroutine A\'s entries in shared queue');
    }

    public function test_stock_should_record_leaks_between_coroutines(): void
    {
        $results = $this->runParallel([
            'a' => function () {
                Telescope::$shouldRecord = true;
                delay(200);
                return Telescope::$shouldRecord;
            },
            'b' => function () {
                delay(50);
                Telescope::$shouldRecord = false;
                return Telescope::$shouldRecord;
            },
        ]);

        // BUG: A set true, B set false — A sees false
        $this->assertFalse($results['a'],
            'BUG: coroutine A sees coroutine B\'s shouldRecord=false');
    }

    // ── Async mode: prove the fix ──

    public function test_async_should_record_isolated(): void
    {
        Telescope::enableAsyncRecording();

        $results = $this->runParallel([
            'a' => function () {
                Telescope::startRecording(false);
                delay(200);
                return Telescope::isRecording();
            },
            'b' => function () {
                delay(50);
                Telescope::stopRecording();
                return Telescope::isRecording();
            },
        ]);

        $this->assertTrue($results['a'], 'A must still be recording');
        $this->assertFalse($results['b'], 'B must be stopped');
    }

    public function test_async_entries_queue_isolated(): void
    {
        Telescope::enableAsyncRecording();

        $results = $this->runParallel([
            'a' => function () {
                Telescope::startRecording(false);
                Telescope::recordLog(IncomingEntry::make(['message' => 'log-a']));
                delay(200);
                return \Async\current_context()->find('telescope.entries') ?? [];
            },
            'b' => function () {
                delay(50);
                Telescope::startRecording(false);
                Telescope::recordLog(IncomingEntry::make(['message' => 'log-b']));
                return \Async\current_context()->find('telescope.entries') ?? [];
            },
        ]);

        $this->assertCount(1, $results['a'], 'A must have exactly 1 entry');
        $this->assertCount(1, $results['b'], 'B must have exactly 1 entry');
        $this->assertEquals('log-a', $results['a'][0]->content['message']);
        $this->assertEquals('log-b', $results['b'][0]->content['message']);
    }

    public function test_async_without_recording_isolated(): void
    {
        Telescope::enableAsyncRecording();

        $results = $this->runParallel([
            'a' => function () {
                Telescope::startRecording(false);
                return Telescope::withoutRecording(function () {
                    delay(200);
                    return Telescope::isRecording();
                });
            },
            'b' => function () {
                delay(50);
                Telescope::startRecording(false);
                return Telescope::isRecording();
            },
        ]);

        $this->assertFalse($results['a'], 'A inside withoutRecording must not record');
        $this->assertTrue($results['b'], 'B must still be recording');
    }

    /**
     * @return array<string, array{0: bool, 1: string, 2: bool}>
     */
    public static function recordingGates(): array
    {
        return [
            'enabled, an ordinary path' => [true, '/orders', true],
            'disabled' => [false, '/orders', false],
            "enabled, Telescope's own polling" => [true, '/telescope-api/requests', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('recordingGates')]
    public function test_a_request_is_recorded_only_when_telescope_would_record_it(bool $enabled, string $path, bool $expected): void
    {
        Telescope::enableAsyncRecording();
        app()->instance('config', new \Illuminate\Config\Repository(['telescope' => ['enabled' => $enabled]]));
        // startRecording() asks the cache whether recording is paused.
        app()->instance('cache', new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore()));

        $results = $this->runParallel(['a' => function () use ($path) {
            Telescope::startRecordingFor(\Illuminate\Http\Request::create($path));

            return Telescope::isRecording();
        }]);

        $this->assertSame($expected, $results['a']);
    }

    /**
     * Upstream's store() drops the entries a batch filter rejects and still writes the updates
     * the request queued: they belong to entries stored by earlier requests.
     */
    public function test_a_rejected_batch_still_stores_its_updates(): void
    {
        Telescope::enableAsyncRecording();
        Telescope::$filterBatchUsing = [fn () => false];

        $storage = new RecordingEntriesRepository();
        // store() reads the queue settings before it dispatches pending updates.
        app()->instance('config', new \Illuminate\Config\Repository(['telescope' => ['queue' => []]]));

        try {
            $this->runParallel(['a' => function () use ($storage) {
                Telescope::startRecording(false);
                Telescope::recordLog(IncomingEntry::make(['message' => 'rejected']));
                Telescope::recordUpdate(new \Laravel\Telescope\EntryUpdate('uuid-1', 'job', ['status' => 'processed']));
                Telescope::store($storage);
            }]);
        } finally {
            Telescope::$filterBatchUsing = [];
        }

        $this->assertSame(0, $storage->stored, 'the rejected entries are dropped');
        $this->assertSame(1, $storage->updated, 'the update is written all the same');
    }
}

final class RecordingEntriesRepository implements \Laravel\Telescope\Contracts\EntriesRepository
{
    public int $stored = 0;

    public int $updated = 0;

    public function find($id): \Laravel\Telescope\EntryResult
    {
        throw new \LogicException('not used');
    }

    public function get($type, \Laravel\Telescope\Storage\EntryQueryOptions $options)
    {
        return collect();
    }

    public function store(\Illuminate\Support\Collection $entries)
    {
        $this->stored += $entries->count();
    }

    public function update(\Illuminate\Support\Collection $updates)
    {
        $this->updated += $updates->count();

        return collect();
    }

    public function loadMonitoredTags()
    {
    }

    public function isMonitoring(array $tags)
    {
        return false;
    }

    public function monitoring()
    {
        return [];
    }

    public function monitor(array $tags)
    {
    }

    public function stopMonitoring(array $tags)
    {
    }
}
