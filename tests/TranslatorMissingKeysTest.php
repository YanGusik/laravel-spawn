<?php

namespace Spawn\Laravel\Tests;

use RuntimeException;
use Spawn\Laravel\Tests\Fixtures\HoldingLoader;
use Spawn\Laravel\Translation\AsyncTranslator;
use stdClass;

use function Async\suspend;

/**
 * `Lang::handleMissingKeysUsing()` must reach every coroutine's missing keys whatever another
 * coroutine is doing.
 *
 * The translator is one object per worker, and Laravel switches the handling off through one
 * property of it at two places: `has()` for the length of its lookup, which loads a group and
 * can suspend there, and the handling itself for the length of the callback, which may log or
 * report and suspend too. Each place saves or sets the property and puts it back afterwards, so
 * a coroutine suspended in either one switches the handling off for every other, and two
 * overlapping `has()` calls leave it off for good.
 */
class TranslatorMissingKeysTest extends AsyncTestCase
{
    private HoldingLoader $loader;

    /** @var list<string> */
    private array $handled = [];

    private function translator(): AsyncTranslator
    {
        $this->loader = new HoldingLoader();
        $this->loader->addMessages('en', 'messages', ['welcome' => 'Welcome']);
        $this->loader->addMessages('en', 'first', ['line' => 'First']);
        $this->loader->addMessages('en', 'second', ['line' => 'Second']);

        $translator = new AsyncTranslator($this->loader, 'en');
        $translator->handleMissingKeysUsing(function ($key) {
            $this->handled[] = $key;

            return $key;
        });
        $translator->bootCompleted();

        return $translator;
    }

    public function test_a_sibling_missing_key_is_handled_while_another_coroutine_is_inside_has(): void
    {
        $translator = $this->translator();

        $gate = new stdClass();
        $gate->inside = false;
        $gate->done = false;
        $this->loader->holds['first'] = function () use ($gate) {
            $gate->inside = true;
            $this->until(fn () => $gate->done);
        };

        $this->runParallel([
            'has' => static fn () => $translator->has('first.line'),
            'sibling' => function () use ($translator, $gate) {
                $this->until(fn () => $gate->inside);

                try {
                    return $translator->get('messages.missing');
                } finally {
                    $gate->done = true;
                }
            },
        ]);

        $this->assertSame(['messages.missing'], $this->handled, 'has() switches the handling off for itself alone');
    }

    public function test_missing_keys_are_handled_after_two_overlapping_has_calls(): void
    {
        $translator = $this->translator();
        $gate = new stdClass();
        $gate->firstIn = false;
        $gate->secondIn = false;
        $gate->firstOut = false;

        $this->loader->holds['first'] = function () use ($gate) {
            $gate->firstIn = true;
            $this->until(fn () => $gate->secondIn);
        };
        $this->loader->holds['second'] = function () use ($gate) {
            $gate->secondIn = true;
            $this->until(fn () => $gate->firstOut);
        };

        $this->runParallel([
            'first' => function () use ($translator, $gate) {
                try {
                    return $translator->has('first.line');
                } finally {
                    $gate->firstOut = true;
                }
            },
            'second' => function () use ($translator, $gate) {
                $this->until(fn () => $gate->firstIn);

                return $translator->has('second.line');
            },
        ]);

        $translator->get('messages.missing');

        $this->assertSame(['messages.missing'], $this->handled, 'the handling must not stay off for the worker');
    }

    public function test_a_sibling_missing_key_is_handled_while_another_callback_suspends(): void
    {
        $translator = $this->translator();
        $gate = new stdClass();
        $gate->inside = false;
        $gate->done = false;

        $translator->handleMissingKeysUsing(function ($key) use ($gate) {
            $this->handled[] = $key;

            if ($key === 'messages.slow') {
                $gate->inside = true;
                $this->until(fn () => $gate->done);
            }

            return $key;
        });

        $this->runParallel([
            'slow' => fn () => $translator->get('messages.slow'),
            'sibling' => function () use ($translator, $gate) {
                $this->until(fn () => $gate->inside);

                try {
                    return $translator->get('messages.missing');
                } finally {
                    $gate->done = true;
                }
            },
        ]);

        $this->assertSame(['messages.slow', 'messages.missing'], $this->handled);
    }

    public function test_a_callback_that_throws_does_not_switch_the_handling_off(): void
    {
        $translator = $this->translator();
        $translator->handleMissingKeysUsing(function ($key) {
            $this->handled[] = $key;

            if ($key === 'messages.broken') {
                throw new RuntimeException('the reporter is down');
            }

            return $key;
        });

        try {
            $translator->get('messages.broken');
        } catch (RuntimeException) {
        }

        $translator->get('messages.missing');

        $this->assertSame(['messages.broken', 'messages.missing'], $this->handled);
    }

    /**
     * Laravel switches the handling off around the callback so that a callback translating a
     * missing key does not call itself; the window keeps that.
     */
    public function test_a_callback_that_translates_a_missing_key_runs_once(): void
    {
        $translator = $this->translator();
        $translator->handleMissingKeysUsing(function ($key) use ($translator) {
            $this->handled[] = $key;

            return $translator->get('messages.also-missing');
        });

        $this->assertSame('messages.also-missing', $translator->get('messages.missing'));
        $this->assertSame(['messages.missing'], $this->handled);
    }

    public function test_has_still_answers(): void
    {
        $translator = $this->translator();

        $this->assertTrue($translator->has('messages.welcome'));
        $this->assertFalse($translator->has('messages.missing'));
        $this->assertSame([], $this->handled, 'has() asks without handling');
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
