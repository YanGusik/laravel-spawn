<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;

use function Async\delay;

class TranslatorIsolationTest extends AsyncTestCase
{
    private function makeTranslator(string $class = Translator::class): Translator
    {
        $loader = new ArrayLoader();
        $loader->addMessages('en', 'messages', ['welcome' => 'Welcome']);
        $loader->addMessages('ru', 'messages', ['welcome' => 'Добро пожаловать']);
        $loader->addMessages('de', 'messages', ['welcome' => 'Willkommen']);

        return new $class($loader, 'en');
    }

    // ── Stock Translator: prove the bug ──

    public function test_stock_translator_locale_leaks_between_coroutines(): void
    {
        $translator = $this->makeTranslator();

        $results = $this->runParallel([
            'a' => function () use ($translator) {
                $translator->setLocale('ru');
                delay(200);
                return $translator->get('messages.welcome');
            },
            'b' => function () use ($translator) {
                delay(50);
                $translator->setLocale('de');
                return $translator->get('messages.welcome');
            },
        ]);

        // BUG: A set locale to 'ru', but B changed it to 'de'
        $this->assertEquals('Willkommen', $results['a'],
            'BUG: coroutine A sees coroutine B\'s locale — gets German instead of Russian');
    }

    // ── AsyncTranslator: prove the fix ──

    public function test_async_translator_locale_isolated(): void
    {
        $translator = $this->makeTranslator(\Spawn\Laravel\Translation\AsyncTranslator::class);
        $translator->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($translator) {
                $translator->setLocale('ru');
                delay(200);
                return $translator->get('messages.welcome');
            },
            'b' => function () use ($translator) {
                delay(50);
                $translator->setLocale('de');
                return $translator->get('messages.welcome');
            },
        ]);

        $this->assertEquals('Добро пожаловать', $results['a'], 'A must see Russian');
        $this->assertEquals('Willkommen', $results['b'], 'B must see German');
    }

    public function test_async_translator_loaded_cache_shared(): void
    {
        $translator = $this->makeTranslator(\Spawn\Laravel\Translation\AsyncTranslator::class);
        $translator->bootCompleted();

        // Pre-warm the cache by loading translations
        $translator->setLocale('en');
        $translator->get('messages.welcome');

        $loadedBefore = (new \ReflectionProperty($translator, 'loaded'))->getValue($translator);

        $results = $this->runParallel([
            'a' => function () use ($translator) {
                $translator->setLocale('ru');
                $translator->get('messages.welcome');
                return (new \ReflectionProperty($translator, 'loaded'))->getValue($translator);
            },
            'b' => function () use ($translator) {
                $translator->setLocale('de');
                $translator->get('messages.welcome');
                return (new \ReflectionProperty($translator, 'loaded'))->getValue($translator);
            },
        ]);

        // Both coroutines should have enriched the same shared $loaded cache
        $loaded = (new \ReflectionProperty($translator, 'loaded'))->getValue($translator);
        $this->assertArrayHasKey('ru', $loaded['*']['messages'] ?? []);
        $this->assertArrayHasKey('de', $loaded['*']['messages'] ?? []);
    }

    public function test_async_translator_default_locale_from_boot(): void
    {
        $translator = $this->makeTranslator(\Spawn\Laravel\Translation\AsyncTranslator::class);
        // Boot locale is 'en'
        $translator->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($translator) {
                // Don't set locale — should inherit boot default
                return $translator->getLocale();
            },
            'b' => function () use ($translator) {
                $translator->setLocale('de');
                return $translator->getLocale();
            },
        ]);

        $this->assertEquals('en', $results['a'], 'A inherits boot locale');
        $this->assertEquals('de', $results['b'], 'B sees its own locale');
    }

    public function test_async_translator_before_boot_behaves_as_stock(): void
    {
        $translator = $this->makeTranslator(\Spawn\Laravel\Translation\AsyncTranslator::class);
        // bootCompleted() NOT called

        $translator->setLocale('ru');
        $this->assertEquals('ru', $translator->getLocale());
        $this->assertEquals('Добро пожаловать', $translator->get('messages.welcome'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function locales(): array
    {
        return [
            'plain' => ['de'],
            'region' => ['pt_BR'],
            'slash' => ['en/x'],
            'backslash' => ['en\\x'],
            'parent directory' => ['..'],
            'nul byte' => ["a\0b"],
        ];
    }

    /**
     * The adapter refuses exactly what the installed Laravel refuses, so a later upstream
     * check reaches requests too.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('locales')]
    public function test_async_translator_refuses_what_laravel_refuses(string $locale): void
    {
        $refusedBy = static function (Translator $translator) use ($locale): bool {
            try {
                $translator->setLocale($locale);

                return false;
            } catch (\InvalidArgumentException) {
                return true;
            }
        };

        $async = $this->makeTranslator(\Spawn\Laravel\Translation\AsyncTranslator::class);
        $async->bootCompleted();

        $results = $this->runParallel(['a' => fn () => $refusedBy($async)]);

        $this->assertSame($refusedBy($this->makeTranslator()), $results['a']);
        $this->assertSame('en', $async->getLocale(), 'a check leaves the worker locale alone');
    }

    public function test_async_translator_fallback_is_per_request(): void
    {
        $translator = $this->makeTranslator(\Spawn\Laravel\Translation\AsyncTranslator::class);
        $translator->setFallback('en');
        $translator->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($translator) {
                $translator->setFallback('de');
                $translator->setLocale('fr');
                delay(100);

                return [$translator->getFallback(), $translator->get('messages.welcome')];
            },
            'b' => function () use ($translator) {
                delay(50);
                $translator->setLocale('fr');

                return [$translator->getFallback(), $translator->get('messages.welcome')];
            },
        ]);

        $this->assertSame(['de', 'Willkommen'], $results['a']);
        $this->assertSame(['en', 'Welcome'], $results['b']);
    }
}
