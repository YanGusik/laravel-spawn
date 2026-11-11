<?php

namespace Spawn\Laravel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end request-conversion test. Drives {@see tests/e2e/run_request_conversion.php},
 * which boots a real TrueAsyncServer in a worker thread and checks what the Laravel request
 * was built from: client address, query string, cookies, host and port.
 *
 * TrueAsync\HttpRequest has a private constructor, so the conversion has no unit-test seam.
 * Run as its own process: teardown sends SIGTERM to the runner, which must not reach the
 * PHPUnit runner.
 */
class RequestConversionE2ETest extends TestCase
{
    public function test_request_conversion_end_to_end_suite(): void
    {
        $runner = __DIR__ . '/e2e/run_request_conversion.php';

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1';
        exec($command, $lines, $code);
        $output = implode("\n", $lines);

        $this->assertSame(0, $code, "e2e runner exited non-zero:\n{$output}");
        $this->assertStringContainsString('0 failed', $output, $output);
    }
}
