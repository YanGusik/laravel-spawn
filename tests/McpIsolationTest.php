<?php

namespace Spawn\Laravel\Tests;

use function Async\delay;

/**
 * laravel/mcp keeps the JSON-RPC request being served in the container (#85, Issue 5):
 * Server::handle() and ToolSearch put it in with instance('mcp.request') and take it out with
 * forgetInstance() when the call ends. Two tool calls served at once must each read their own.
 */
class McpIsolationTest extends AsyncTestCase
{
    use BootsAsyncApplication;

    /**
     * @return array<string, array{string}>
     */
    public static function heldKeys(): array
    {
        return [
            'the JSON-RPC request' => ['mcp.request'],
            'a resource read\'s request' => [\Laravel\Mcp\Request::class],
            'a resource read\'s scripts' => ['mcp.library_scripts'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('heldKeys')]
    public function test_each_request_reads_its_own_mcp_request_while_another_is_served(string $key): void
    {
        if (! class_exists(\Laravel\Mcp\Server\McpServiceProvider::class)) {
            $this->markTestSkipped('laravel/mcp is not installed');
        }

        $app = $this->bootedApp([\Laravel\Mcp\Server\McpServiceProvider::class]);
        $app->enableAsyncMode();

        $call = static function (string $id, int $holdMs) use ($app, $key): array {
            $app->instance($key, $id);

            try {
                delay($holdMs);

                return [$app->bound($key), $app->make($key)];
            } finally {
                $app->forgetInstance($key);
            }
        };

        $results = $this->runParallel([
            'a' => fn () => $call('a', 60),
            'b' => fn () => $call('b', 10),
        ]);

        $this->assertSame([true, 'a'], $results['a'], 'b ended first and took a\'s request out with its own');
        $this->assertSame([true, 'b'], $results['b']);
        $this->assertFalse($app->bound($key), 'nothing is left behind for the worker');
    }

    public function test_each_request_has_its_own_client_manager(): void
    {
        if (! class_exists(\Laravel\Mcp\Client\ClientManager::class)) {
            $this->markTestSkipped('laravel/mcp is not installed');
        }

        $app = $this->bootedApp([\Laravel\Mcp\Server\McpServiceProvider::class]);
        $app->enableAsyncMode();

        $manager = static fn () => $app->make(\Laravel\Mcp\Client\ClientManager::class);
        $results = $this->runParallel(['a' => $manager, 'b' => $manager]);

        $this->assertNotSame($results['a'], $results['b']);
    }

    public function test_a_client_registered_at_boot_is_known_to_every_request(): void
    {
        if (! class_exists(\Laravel\Mcp\Client\ClientManager::class)) {
            $this->markTestSkipped('laravel/mcp is not installed');
        }

        $app = $this->bootedApp([\Laravel\Mcp\Server\McpServiceProvider::class]);
        $app->make(\Laravel\Mcp\Client\ClientManager::class)
            ->registerClient('github', fn () => throw new \LogicException('not connected in this test'));
        $app->enableAsyncMode();

        $registered = fn () => (fn () => array_keys($this->factories))
            ->call($app->make(\Laravel\Mcp\Client\ClientManager::class));

        $this->assertSame(['a' => ['github'], 'b' => ['github']], $this->runParallel(['a' => $registered, 'b' => $registered]));
    }
}
