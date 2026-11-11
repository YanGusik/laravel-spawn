<?php

namespace Spawn\Laravel\Telescope;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\EventFake;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\Contracts\TerminableRepository;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\EntryUpdate;
use Laravel\Telescope\Jobs\ProcessPendingUpdates;
use Throwable;
use Spawn\Laravel\Foundation\RequestContext;

/**
 * Coroutine-safe overrides for Telescope's recording pipeline.
 *
 * Replaces static $entriesQueue, $updatesQueue, $shouldRecord
 * with per-request storage via RequestContext.
 *
 * Apply this trait to a class that replaces Laravel\Telescope\Telescope
 * via composer exclude-from-classmap.
 */
trait CoroutineSafeRecording
{
    private static bool $asyncMode = false;

    private const CTX_ENTRIES = 'telescope.entries';
    private const CTX_UPDATES = 'telescope.updates';
    private const CTX_RECORDING = 'telescope.recording';

    /**
     * Switch to per-coroutine mode. Called once at server start.
     */
    public static function enableAsyncRecording(): void
    {
        static::$asyncMode = true;
    }

    /**
     * Start recording entries.
     */
    /**
     * Start recording for a request the server is about to serve, when Telescope would record
     * it: Telescope is enabled, and the request is to an approved domain and path — its own
     * dashboard polling, `telescope-api/*`, is not.
     *
     * Upstream decides this once in start(), for the one request of a PHP-FPM process; a worker
     * serves many, and asks per request.
     *
     * @param  \Illuminate\Http\Request  $request
     */
    public static function startRecordingFor($request): void
    {
        if (! config('telescope.enabled')) {
            return;
        }

        if (! static::requestIsToApprovedDomain($request) || ! static::requestIsToApprovedUri($request)) {
            return;
        }

        static::startRecording(false);
    }

    public static function startRecording($loadMonitoredTags = true)
    {
        if ($loadMonitoredTags) {
            app(EntriesRepository::class)->loadMonitoredTags();
        }

        $recordingPaused = false;

        try {
            $recordingPaused = cache('telescope:pause-recording');
        } catch (\Exception) {
            //
        }

        $shouldRecord = ! $recordingPaused;

        if (static::$asyncMode) {
            RequestContext::current()->set(self::CTX_RECORDING, $shouldRecord, replace: true);
        } else {
            static::$shouldRecord = $shouldRecord;
        }
    }

    /**
     * Stop recording entries.
     */
    public static function stopRecording()
    {
        if (static::$asyncMode) {
            RequestContext::current()->set(self::CTX_RECORDING, false, replace: true);
        } else {
            static::$shouldRecord = false;
        }
    }

    /**
     * Execute the given callback without recording Telescope entries.
     */
    public static function withoutRecording($callback)
    {
        if (static::$asyncMode) {
            $ctx = RequestContext::current();
            $prev = $ctx->find(self::CTX_RECORDING) ?? false;
            $ctx->set(self::CTX_RECORDING, false, replace: true);

            try {
                return call_user_func($callback);
            } finally {
                $ctx->set(self::CTX_RECORDING, $prev, replace: true);
            }
        }

        $shouldRecord = static::$shouldRecord;
        static::$shouldRecord = false;

        try {
            return call_user_func($callback);
        } finally {
            static::$shouldRecord = $shouldRecord;
        }
    }

    /**
     * Determine if Telescope is recording.
     */
    public static function isRecording()
    {
        $recording = static::$asyncMode
            ? (RequestContext::current()->find(self::CTX_RECORDING) ?? false)
            : static::$shouldRecord;

        return $recording && ! app('events') instanceof EventFake;
    }

    /**
     * Record the given entry (per-coroutine queue).
     */
    protected static function record(string $type, IncomingEntry $entry)
    {
        if (! static::isRecording()) {
            return;
        }

        try {
            if (Auth::hasResolvedGuards() && Auth::hasUser()) {
                $entry->user(Auth::user());
            }
        } catch (Throwable $e) {
            // Do nothing.
        }

        $entry->type($type)->tags(Arr::collapse(array_map(function ($tagCallback) use ($entry) {
            return $tagCallback($entry);
        }, static::$tagUsing)));

        static::withoutRecording(function () use ($entry) {
            if (collect(static::$filterUsing)->every->__invoke($entry)) {
                if (static::$asyncMode) {
                    $ctx = RequestContext::current();
                    $entries = $ctx->find(self::CTX_ENTRIES) ?? [];
                    $entries[] = $entry;
                    $ctx->set(self::CTX_ENTRIES, $entries, replace: true);
                } else {
                    static::$entriesQueue[] = $entry;
                }
            }

            if (static::$afterRecordingHook) {
                call_user_func(static::$afterRecordingHook, new static, $entry);
            }
        });
    }

    /**
     * Record the given entry update (per-coroutine queue).
     */
    public static function recordUpdate(EntryUpdate $update)
    {
        if (! static::isRecording()) {
            return;
        }

        if (static::$asyncMode) {
            $ctx = RequestContext::current();
            $updates = $ctx->find(self::CTX_UPDATES) ?? [];
            $updates[] = $update;
            $ctx->set(self::CTX_UPDATES, $updates, replace: true);
        } else {
            static::$updatesQueue[] = $update;
        }
    }

    /**
     * Flush all entries in the queue (per-coroutine).
     */
    public static function flushEntries()
    {
        if (static::$asyncMode) {
            RequestContext::current()->set(self::CTX_ENTRIES, [], replace: true);
        } else {
            static::$entriesQueue = [];
        }

        return new static;
    }

    /**
     * Store the queued entries and flush the queue (per-coroutine).
     */
    public static function store(EntriesRepository $storage)
    {
        if (static::$asyncMode) {
            static::storeAsync($storage);
        } else {
            static::storeSync($storage);
        }
    }

    private static function storeAsync(EntriesRepository $storage): void
    {
        $ctx = RequestContext::current();
        $entries = $ctx->find(self::CTX_ENTRIES) ?? [];
        $updates = $ctx->find(self::CTX_UPDATES) ?? [];

        if (empty($entries) && empty($updates)) {
            return;
        }

        static::withoutRecording(function () use ($storage, $entries, $updates) {
            // As upstream's store(): a rejected batch drops its entries, and its updates, which
            // belong to entries earlier requests stored, are written all the same.
            if (! collect(static::$filterBatchUsing)->every->__invoke(collect($entries))) {
                $entries = [];
            }

            try {
                $batchId = Str::orderedUuid()->toString();

                $entryCollection = collect($entries)->each(function ($entry) use ($batchId, $entries) {
                    $entry->batchId($batchId);
                    if ($entry->isDump()) {
                        $entry->assignEntryPointFromBatch($entries);
                    }
                });

                $updateCollection = collect($updates)->each(function ($entry) use ($batchId) {
                    $entry->change(['updated_batch_id' => $batchId]);
                });

                $storage->store($entryCollection);
                $updateResult = $storage->update($updateCollection) ?: Collection::make();

                if (! isset($_ENV['VAPOR_SSM_PATH'])) {
                    $delay = config('telescope.queue.delay');

                    $updateResult->whenNotEmpty(fn ($pendingUpdates) => rescue(fn () => ProcessPendingUpdates::dispatch(
                        $pendingUpdates,
                    )->onConnection(
                        config('telescope.queue.connection')
                    )->onQueue(
                        config('telescope.queue.queue')
                    )->delay(is_numeric($delay) && $delay > 0 ? now()->addSeconds($delay) : null)));
                }

                if ($storage instanceof TerminableRepository) {
                    $storage->terminate();
                }

                collect(static::$afterStoringHooks)->every->__invoke($entries, $batchId);
            } catch (Throwable $e) {
                app(ExceptionHandler::class)->report($e);
            }
        });

        $ctx->set(self::CTX_ENTRIES, [], replace: true);
        $ctx->set(self::CTX_UPDATES, [], replace: true);
    }

    private static function storeSync(EntriesRepository $storage): void
    {
        if (empty(static::$entriesQueue) && empty(static::$updatesQueue)) {
            return;
        }

        static::withoutRecording(function () use ($storage) {
            if (! collect(static::$filterBatchUsing)->every->__invoke(collect(static::$entriesQueue))) {
                static::$entriesQueue = [];
            }

            try {
                $batchId = Str::orderedUuid()->toString();

                $storage->store(static::collectEntries($batchId));
                $updateResult = $storage->update(static::collectUpdates($batchId)) ?: Collection::make();

                if (! isset($_ENV['VAPOR_SSM_PATH'])) {
                    $delay = config('telescope.queue.delay');

                    $updateResult->whenNotEmpty(fn ($pendingUpdates) => rescue(fn () => ProcessPendingUpdates::dispatch(
                        $pendingUpdates,
                    )->onConnection(
                        config('telescope.queue.connection')
                    )->onQueue(
                        config('telescope.queue.queue')
                    )->delay(is_numeric($delay) && $delay > 0 ? now()->addSeconds($delay) : null)));
                }

                if ($storage instanceof TerminableRepository) {
                    $storage->terminate();
                }

                collect(static::$afterStoringHooks)->every->__invoke(static::$entriesQueue, $batchId);
            } catch (Throwable $e) {
                app(ExceptionHandler::class)->report($e);
            }
        });

        static::$entriesQueue = [];
        static::$updatesQueue = [];
    }
}
