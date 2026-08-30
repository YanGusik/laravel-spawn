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

/* Whether A is between the opening and the closing of its window right now. B records it
 * along with its own reading, so a run whose delays failed to overlap is inconclusive rather
 * than a quiet "isolation held". */
$aIsInside = false;

/* Request A: an ordinary unguarded bulk import that awaits anything at all. */
$unguardedWriter = static function () use (&$aIsInside) {
    return Model::unguarded(static function () use (&$aIsInside) {
        $aIsInside = true;

        try {
            delay(HOLD_MS);

            return 'imported';
        } finally {
            $aIsInside = false;
        }
    });
};

/* Request B: touches neither guard nor dispatcher and only reports what it sees. */
$guardObserver = static function () use (&$aIsInside) {
    delay(PROBE_MS);

    return ['inside A' => $aIsInside, 'unguarded' => Model::isUnguarded()];
};

$sequential = proof_run_sequentially(['a' => $unguardedWriter, 'b' => $guardObserver]);
$run->control('unguarded: A completed, sequential', $sequential['a'], 'imported');
$run->control('unguarded: B ran outside A\'s window', $sequential['b']['inside A'], false);
$run->control('unguarded: B, sequential', $sequential['b']['unguarded'], false);

$concurrent = proof_run_concurrently(['a' => $unguardedWriter, 'b' => $guardObserver]);
$run->control('unguarded: A completed, concurrent', $concurrent['a'], 'imported');
$run->control('unguarded: B ran inside A\'s window', $concurrent['b']['inside A'], true);
$run->isolation('unguarded: B, concurrent', $concurrent['b']['unguarded'], false);

/* The dispatcher is reachable from the container in a real application; here it is
 * set directly, because what leaks is the static Model holds, not how it got there. */
$dispatcher = new Dispatcher(new Container());
$delivered = [];
$dispatcher->listen('probe', static function () use (&$delivered) {
    $delivered[] = 'probe';
});
Model::setEventDispatcher($dispatcher);

$silentWriter = static function () use (&$aIsInside) {
    return Model::withoutEvents(static function () use (&$aIsInside) {
        $aIsInside = true;

        try {
            delay(HOLD_MS);

            return 'saved quietly';
        } finally {
            $aIsInside = false;
        }
    });
};

/* Every model event goes through the static dispatcher the same way, so asking it to
 * deliver one is the observation a save() would make from inside fireModelEvent(). */
$eventObserver = static function () use (&$delivered, &$aIsInside) {
    delay(PROBE_MS);
    $delivered = [];
    $inside = $aIsInside;
    Model::getEventDispatcher()->dispatch('probe');

    return ['inside A' => $inside, 'delivered' => $delivered];
};

$sequential = proof_run_sequentially(['a' => $silentWriter, 'b' => $eventObserver]);
$run->control('events: A completed, sequential', $sequential['a'], 'saved quietly');
$run->control('events: B ran outside A\'s window', $sequential['b']['inside A'], false);
$run->control('events: B, sequential', $sequential['b']['delivered'], ['probe']);

$concurrent = proof_run_concurrently(['a' => $silentWriter, 'b' => $eventObserver]);
$run->control('events: A completed, concurrent', $concurrent['a'], 'saved quietly');
$run->control('events: B ran inside A\'s window', $concurrent['b']['inside A'], true);
$run->isolation('events: B, concurrent', $concurrent['b']['delivered'], ['probe']);

exit($run->exitCode());
