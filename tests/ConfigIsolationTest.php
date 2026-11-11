<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Config\Repository;

use function Async\delay;

class ConfigIsolationTest extends AsyncTestCase
{
    private function makeConfig(string $class = Repository::class): Repository
    {
        return new $class([
            'app' => [
                'locale' => 'en',
                'name' => 'MyApp',
            ],
            'services' => [
                'aws' => ['key' => 'boot-key'],
            ],
        ]);
    }

    // ── Stock Repository: prove the bug ──

    public function test_stock_config_set_leaks_between_coroutines(): void
    {
        $config = $this->makeConfig();

        $results = $this->runParallel([
            'a' => function () use ($config) {
                $config->set('app.locale', 'ru');
                delay(200);
                return $config->get('app.locale');
            },
            'b' => function () use ($config) {
                delay(50);
                $config->set('app.locale', 'de');
                return $config->get('app.locale');
            },
        ]);

        $this->assertEquals('de', $results['a'],
            'BUG: coroutine A sees coroutine B\'s config value');
    }

    public function test_stock_config_set_persists_across_requests(): void
    {
        $config = $this->makeConfig();

        $results = $this->runParallel([
            'a' => function () use ($config) {
                $config->set('services.aws.key', 'secret-a');
                delay(200);
                return $config->get('services.aws.key');
            },
            'b' => function () use ($config) {
                delay(250);
                // B runs after A — but A's set() mutated the shared config
                return $config->get('services.aws.key');
            },
        ]);

        $this->assertEquals('secret-a', $results['b'],
            'BUG: coroutine B sees coroutine A\'s leftover config mutation');
    }

    // ── AsyncConfig: prove the fix ──

    public function test_async_config_set_isolated(): void
    {
        $config = $this->makeConfig(\Spawn\Laravel\Config\AsyncConfig::class);
        $config->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($config) {
                $config->set('app.locale', 'ru');
                delay(200);
                return $config->get('app.locale');
            },
            'b' => function () use ($config) {
                delay(50);
                $config->set('app.locale', 'de');
                return $config->get('app.locale');
            },
        ]);

        $this->assertEquals('ru', $results['a'], 'A must see its own config');
        $this->assertEquals('de', $results['b'], 'B must see its own config');
    }

    public function test_async_config_set_does_not_persist(): void
    {
        $config = $this->makeConfig(\Spawn\Laravel\Config\AsyncConfig::class);
        $config->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($config) {
                $config->set('services.aws.key', 'secret-a');
                delay(200);
                return $config->get('services.aws.key');
            },
            'b' => function () use ($config) {
                delay(250);
                return $config->get('services.aws.key');
            },
        ]);

        $this->assertEquals('secret-a', $results['a'], 'A sees its own override');
        $this->assertEquals('boot-key', $results['b'], 'B sees original boot config');
    }

    public function test_async_config_get_falls_through_to_base(): void
    {
        $config = $this->makeConfig(\Spawn\Laravel\Config\AsyncConfig::class);
        $config->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($config) {
                // Only override locale, not name
                $config->set('app.locale', 'fr');
                return [
                    'locale' => $config->get('app.locale'),
                    'name' => $config->get('app.name'),
                ];
            },
        ]);

        $this->assertEquals('fr', $results['a']['locale'], 'Override applied');
        $this->assertEquals('MyApp', $results['a']['name'], 'Non-overridden falls through to base');
    }

    public function test_async_config_has_checks_overlay(): void
    {
        $config = $this->makeConfig(\Spawn\Laravel\Config\AsyncConfig::class);
        $config->bootCompleted();

        $results = $this->runParallel([
            'a' => function () use ($config) {
                $config->set('custom.key', 'value');
                return [
                    'has_custom' => $config->has('custom.key'),
                    'has_app' => $config->has('app.name'),
                    'has_missing' => $config->has('nonexistent'),
                ];
            },
        ]);

        $this->assertTrue($results['a']['has_custom'], 'Overlay key found');
        $this->assertTrue($results['a']['has_app'], 'Base key found');
        $this->assertFalse($results['a']['has_missing'], 'Missing key not found');
    }

    public function test_async_config_before_boot_behaves_as_stock(): void
    {
        $config = $this->makeConfig(\Spawn\Laravel\Config\AsyncConfig::class);
        // bootCompleted() NOT called

        $config->set('app.locale', 'ja');
        $this->assertEquals('ja', $config->get('app.locale'));
    }

    private function asyncConfigWithLists(): \Spawn\Laravel\Config\AsyncConfig
    {
        $config = new \Spawn\Laravel\Config\AsyncConfig([
            'app' => [
                'providers' => ['A', 'B', 'C'],
                'options' => ['k1' => 'v1', 'k2' => 'v2'],
                'nested' => ['x' => 1, 'y' => 2],
            ],
        ]);
        $config->bootCompleted();

        return $config;
    }

    public function test_async_config_replaces_a_list_with_a_shorter_one(): void
    {
        $config = $this->asyncConfigWithLists();

        $results = $this->runParallel([
            'a' => function () use ($config) {
                $config->set('app.providers', ['Only']);

                return [$config->get('app.providers'), $config->all()['app']['providers']];
            },
        ]);

        $this->assertSame([['Only'], ['Only']], $results['a']);
    }

    public function test_async_config_empties_an_array(): void
    {
        $config = $this->asyncConfigWithLists();

        $results = $this->runParallel([
            'a' => function () use ($config) {
                $config->set('app.options', []);

                return [$config->get('app.options'), $config->all()['app']['options']];
            },
        ]);

        $this->assertSame([[], []], $results['a']);
    }

    public function test_async_config_partial_nested_write_keeps_its_siblings(): void
    {
        $config = $this->asyncConfigWithLists();

        $results = $this->runParallel([
            'a' => function () use ($config) {
                $config->set('app.nested.x', 9);

                return $config->get('app.nested');
            },
            'b' => function () use ($config) {
                delay(20);

                return $config->get('app.nested');
            },
        ]);

        $this->assertSame(['x' => 9, 'y' => 2], $results['a']);
        $this->assertSame(['x' => 1, 'y' => 2], $results['b'], 'a sibling request reads the base');
    }

    public function test_async_config_has_answers_true_for_a_key_set_to_null(): void
    {
        $config = $this->asyncConfigWithLists();

        $results = $this->runParallel([
            'a' => function () use ($config) {
                $config->set('app.missing', null);

                return $config->has('app.missing');
            },
        ]);

        $this->assertTrue($results['a']);
    }
}
