<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Spawn\Laravel\Server\TrueAsyncServer;

/**
 * The document-root mount the shipped configuration names, and the guard that
 * refuses it on an extension too old to serve it safely.
 *
 * Nothing is mounted that the configuration does not list — a directory served
 * at "/" exposes whatever it holds, and an application that left the list empty
 * chose that. What the shipped entry has to get right is asserted here: an
 * unmatched path goes on to the kernel rather than 404, and public/index.php is
 * not written to the client as text.
 *
 * The serving itself belongs to the extension and is covered there
 * (tests/phpt/server/static/022, /023).
 */
class PublicMountTest extends TestCase
{
    private function shippedMounts(): array
    {
        $config = require __DIR__ . '/../config/async.php';

        return $config['server']['static_handlers'] ?? [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        /* public_path() goes through app()->publicPath(), so the container has
         * to hold an application rather than a bare path binding. Nothing here
         * is booted: the base path is all the config reads. */
        Container::setInstance(new Application('/srv/app'));
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_the_document_root_mount_falls_through_to_the_kernel(): void
    {
        $mounts = $this->shippedMounts();

        self::assertNotSame([], $mounts, 'the shipped config names the document root');
        self::assertSame('/', $mounts[0]['prefix']);
        self::assertSame('next', $mounts[0]['on_missing']);
        self::assertSame('/srv/app/public', $mounts[0]['root']);
    }

    public function test_the_document_root_mount_hides_php_sources(): void
    {
        self::assertContains('*.php', $this->shippedMounts()[0]['hide']);
    }

    public function test_a_root_mount_is_refused_on_an_extension_that_cannot_serve_it(): void
    {
        $server = new TrueAsyncServer('/dev/null', '/dev/null', []);
        $servable = (new ReflectionMethod($server, 'rootMountIsServable'));

        /* A prefix below the root is served by every version. */
        self::assertTrue($servable->invoke($server, '/assets/'));

        $version = phpversion('true_async_server');
        $current = $version !== false && version_compare($version, '0.14.0', '>=');

        /* Before 0.14.0 a mount at "/" throws at the constructor or hands
         * admin/tools.php to the client, so the guard withholds it. */
        self::assertSame($current, $servable->invoke($server, '/'));
    }
}
