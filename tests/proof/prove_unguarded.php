<?php

/**
 * Eloquent's guard and event statics leak out of the request that set them.
 *
 * `Model::unguarded()` and `Model::withoutEvents()` set a static, run a callback and
 * restore it in a `finally`. Under one-request-per-process that window is invisible;
 * under coroutines it lasts as long as the callback suspends, and every request in
 * flight runs inside it. The first drops mass-assignment protection, which is a
 * security property; the second swaps the shared event dispatcher for a null one, so
 * another request's model events are discarded with no error anywhere.
 *
 * Exits 0 if both statics stay inside their own request, 1 if either leaks, 2 if a
 * control failed. Reported as YanGusik/laravel-spawn#65, case 2.
 */

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;

use function Async\delay;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/harness.php';

/* B waits less than A holds the static, so its reading falls inside A's window.
 * Sequentially the same pair of delays puts it after A has restored. */
const HOLD_MS = 40;
const PROBE_MS = 15;

$run = new ProofRun();

/* Request A: an ordinary unguarded bulk import that awaits anything at all. */
$unguardedWriter = static fn () => Model::unguarded(static function () {
    delay(HOLD_MS);

    return 'imported';
});

/* Request B: touches neither guard nor dispatcher and only reports what it sees. */
$guardObserver = static function () {
    delay(PROBE_MS);

    return Model::isUnguarded();
};

$sequential = proof_run_sequentially(['a' => $unguardedWriter, 'b' => $guardObserver]);
$run->control('unguarded: A completed, sequential', $sequential['a'], 'imported');
$run->control('unguarded: B outside A\'s window', $sequential['b'], false);

$concurrent = proof_run_concurrently(['a' => $unguardedWriter, 'b' => $guardObserver]);
$run->control('unguarded: A completed, concurrent', $concurrent['a'], 'imported');
$run->isolation('unguarded: B inside A\'s window', $concurrent['b'], false);

/* The dispatcher is reachable from the container in a real application; here it is
 * set directly, because what leaks is the static Model holds, not how it got there. */
$dispatcher = new Dispatcher(new Container());
$delivered = [];
$dispatcher->listen('probe', static function () use (&$delivered) {
    $delivered[] = 'probe';
});
Model::setEventDispatcher($dispatcher);

$silentWriter = static fn () => Model::withoutEvents(static function () {
    delay(HOLD_MS);

    return 'saved quietly';
});

/* Every model event goes through the static dispatcher the same way, so asking it to
 * deliver one is the observation a save() would make from inside fireModelEvent(). */
$eventObserver = static function () use (&$delivered) {
    delay(PROBE_MS);
    $delivered = [];
    Model::getEventDispatcher()->dispatch('probe');

    return $delivered;
};

$sequential = proof_run_sequentially(['a' => $silentWriter, 'b' => $eventObserver]);
$run->control('events: A completed, sequential', $sequential['a'], 'saved quietly');
$run->control('events: B outside A\'s window', $sequential['b'], ['probe']);

$concurrent = proof_run_concurrently(['a' => $silentWriter, 'b' => $eventObserver]);
$run->control('events: A completed, concurrent', $concurrent['a'], 'saved quietly');
$run->isolation('events: B inside A\'s window', $concurrent['b'], ['probe']);

exit($run->exitCode());
