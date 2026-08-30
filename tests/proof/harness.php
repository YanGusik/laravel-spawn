<?php

/**
 * Shared scaffolding for the reproducers under tests/proof/.
 *
 * A reproducer makes two kinds of statement, and they cannot share a verdict. A
 * *control* says the fixture works: the operations run, the mechanism under test is
 * reachable, the reading is correct where isolation is not at stake. An *isolation*
 * check says what a request may observe of another request. A failed control makes the
 * run inconclusive rather than positive — without that split a script that executes
 * nothing at all exits the same way as one that caught the defect.
 *
 * Exit codes: 0 isolation held, 1 a defect reproduced, 2 inconclusive.
 */

use Async\Scope;

/**
 * Run the closures as concurrent requests, each in a scope of its own.
 *
 * Mirrors Spawn\Laravel\Tests\AsyncTestCase::runParallel(), which a standalone script
 * cannot reach: change the isolation shape there and change it here too. Results come
 * back keyed as they were given and in that order, so a caller comparing whole arrays
 * does not compare scheduling.
 *
 * @param  array<array-key, callable>  $requests
 * @return array<array-key, mixed>
 */
function proof_run_concurrently(array $requests): array
{
    $results = [];
    $failures = [];
    $scope = new Scope();

    foreach ($requests as $key => $fn) {
        $scope->spawn(static function () use ($key, $fn, &$results, &$failures) {
            $requestScope = Scope::inherit();

            $requestScope->spawn(static function () use ($key, $fn, &$results, &$failures) {
                try {
                    $results[$key] = $fn();
                } catch (\Throwable $e) {
                    $failures[$key] = $e;
                }
            });

            $requestScope->awaitCompletion(\Async\timeout(5000));
        });
    }

    $scope->awaitCompletion(\Async\timeout(5000));

    foreach (array_keys($requests) as $key) {
        if (isset($failures[$key])) {
            throw $failures[$key];
        }
    }

    return array_replace(array_fill_keys(array_keys($requests), null), $results);
}

/**
 * Run the closures one after another, in the same coroutine.
 *
 * @param  array<array-key, callable>  $requests
 * @return array<array-key, mixed>
 */
function proof_run_sequentially(array $requests): array
{
    $results = [];

    foreach ($requests as $key => $fn) {
        $results[$key] = $fn();
    }

    return $results;
}

/**
 * The verdict of one reproducer, kept as two separate tallies.
 */
final class ProofRun
{
    private bool $fixtureBroken = false;

    private bool $defectSeen = false;

    /**
     * A reading that must hold for the run to mean anything — the requests executed,
     * the mechanism is reachable, the value is what it is regardless of isolation.
     *
     * A failure here makes the whole run inconclusive: nothing is claimed either way.
     */
    public function control(string $label, mixed $actual, mixed $expected): void
    {
        if (! $this->report('control', $label, $actual === $expected, $actual, $this->render($expected))) {
            $this->fixtureBroken = true;
        }
    }

    /**
     * A reading a request may only make of itself. A failure is the defect.
     */
    public function isolation(string $label, mixed $actual, mixed $expected): void
    {
        if (! $this->report('isolation', $label, $actual === $expected, $actual, $this->render($expected))) {
            $this->defectSeen = true;
        }
    }

    /**
     * An isolation check written against a marker another request installed, for a
     * reading whose correct values are open-ended: the defect is recognised by the
     * marker arriving, not by any particular alternative.
     */
    public function isolationDiffers(string $label, mixed $actual, mixed $foreignMarker): void
    {
        $ok = $actual !== $foreignMarker;

        $this->report('isolation', $label, $ok, $actual, 'anything but ' . $this->render($foreignMarker));

        if (! $ok) {
            $this->defectSeen = true;
        }
    }

    /**
     * 0 when every isolation check held, 1 when one did not, 2 when a control failed
     * and the run therefore says nothing.
     */
    public function exitCode(): int
    {
        if ($this->fixtureBroken) {
            echo "inconclusive: a control failed, so nothing is claimed about isolation\n";

            return 2;
        }

        return $this->defectSeen ? 1 : 0;
    }

    private function report(string $kind, string $label, bool $ok, mixed $actual, string $expected): bool
    {
        printf(
            "%-8s %-11s %-44s got %-24s want %s\n",
            $ok ? '[ok]' : '[FAIL]',
            "($kind)",
            $label,
            $this->render($actual),
            $expected
        );

        return $ok;
    }

    private function render(mixed $value): string
    {
        return json_encode($value);
    }
}
