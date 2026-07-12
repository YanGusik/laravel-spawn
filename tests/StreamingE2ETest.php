<?php

namespace Spawn\Laravel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end SSE/gRPC test. Drives {@see tests/e2e/run_streaming.php}, which
 * boots a real TrueAsyncServer in a worker thread (`spawn_thread`) and hits it
 * with real clients (raw socket for SSE, curl over HTTP/2 for gRPC) — the
 * streaming paths added on top of TrueAsyncServer have no equivalent in
 * DevServer, so HttpE2ETest's fixture cannot cover them.
 *
 * Run as its own process for the same reason as HttpE2ETest: teardown sends
 * SIGTERM to the runner itself, which TrueAsyncServer now reacts to by
 * calling HttpServer::stop() (see registerShutdownSignals()) — that must not
 * reach the PHPUnit runner.
 */
class StreamingE2ETest extends TestCase
{
    public function test_sse_and_grpc_end_to_end_suite(): void
    {
        if (!extension_loaded('curl')) {
            $this->markTestSkipped('ext-curl is required to drive the gRPC HTTP/2 client.');
        }

        $runner = __DIR__ . '/e2e/run_streaming.php';

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1';
        exec($command, $lines, $code);
        $output = implode("\n", $lines);

        $this->assertSame(0, $code, "e2e runner exited non-zero:\n{$output}");
        $this->assertStringContainsString('0 failed', $output, $output);
    }
}
