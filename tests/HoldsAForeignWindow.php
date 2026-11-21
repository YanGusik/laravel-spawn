<?php

namespace Spawn\Laravel\Tests;

use stdClass;

use function Async\suspend;

/**
 * The interleave every Eloquent window test needs: one coroutine inside a window, the others
 * running while it stays open. Driven by handshakes rather than by sleeping for long enough.
 *
 * @mixin AsyncTestCase
 */
trait HoldsAForeignWindow
{
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
