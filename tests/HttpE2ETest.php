<?php

namespace Spawn\Laravel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end HTTP test. Drives {@see tests/e2e/run.php}, which boots the bench
 * fixture in a real worker thread (`spawn_thread`) and hits it over a loopback
 * socket with concurrent coroutine clients — exercising the full server stack
 * (routing, per-request isolation, error handling) rather than in-process helpers.
 *
 * The runner is executed as its own process because its teardown sends SIGTERM
 * (Thread::cancel() is unimplemented in TrueAsync), which must not reach the
 * PHPUnit runner.
 */
class HttpE2ETest extends TestCase
{
    public function test_http_server_end_to_end_suite(): void
    {
        $runner = __DIR__ . '/e2e/run.php';

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1';
        exec($command, $lines, $code);
        $output = implode("\n", $lines);

        $this->assertSame(0, $code, "e2e runner exited non-zero:\n{$output}");
        $this->assertStringContainsString('0 failed', $output, $output);
    }
}
