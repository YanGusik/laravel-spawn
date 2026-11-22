<?php

namespace Spawn\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use Spawn\Laravel\Server\TrueAsyncServer;

/**
 * Which paths the server watches for changes (#85, Issue 15): where `hot_reload` says, by
 * default in the local environment only, not in every environment other than production.
 */
class HotReloadOptionsTest extends TestCase
{
    private const PATHS = ['app', 'routes'];

    public function test_local_environment_watches_by_default(): void
    {
        $this->assertSame(self::PATHS, TrueAsyncServer::hotReloadPaths(['hot_reload_paths' => self::PATHS], 'local'));
    }

    public function test_other_environments_do_not_watch_by_default(): void
    {
        foreach (['staging', 'dev', 'testing', 'production'] as $environment) {
            $this->assertSame(
                [],
                TrueAsyncServer::hotReloadPaths(['hot_reload_paths' => self::PATHS], $environment),
                $environment
            );
        }
    }

    public function test_the_option_switches_watching_on_and_off_in_any_environment(): void
    {
        $on  = ['hot_reload' => true, 'hot_reload_paths' => self::PATHS];
        $off = ['hot_reload' => false, 'hot_reload_paths' => self::PATHS];

        $this->assertSame(self::PATHS, TrueAsyncServer::hotReloadPaths($on, 'staging'));
        $this->assertSame(self::PATHS, TrueAsyncServer::hotReloadPaths($on, 'production'));
        $this->assertSame([], TrueAsyncServer::hotReloadPaths($off, 'local'));
    }

    public function test_nothing_is_watched_without_paths(): void
    {
        $this->assertSame([], TrueAsyncServer::hotReloadPaths(['hot_reload' => true], 'local'));
    }
}
