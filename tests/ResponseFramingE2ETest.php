<?php

namespace Spawn\Laravel\Tests;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end response-framing test. Drives {@see tests/e2e/run_response_framing.php},
 * which boots a real TrueAsyncServer in a worker thread and reads raw responses off a
 * socket: what the answer announces as its length against what it carries, and whether
 * a body Symfony writes to standard output arrives at all.
 *
 * HttpE2ETest cannot cover this. Its fixture is DevServer, which emits through
 * ResponseEmitter and counts the body itself, so neither a mismatched length nor a lost
 * streamed body is expressible there.
 *
 * Run as its own process for the same reason as StreamingE2ETest: teardown sends SIGTERM
 * to the runner, which TrueAsyncServer answers with HttpServer::stop().
 */
class ResponseFramingE2ETest extends TestCase
{
    public function test_response_framing_end_to_end_suite(): void
    {
        $runner = __DIR__ . '/e2e/run_response_framing.php';

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1';
        exec($command, $lines, $code);
        $output = implode("\n", $lines);

        $this->assertSame(0, $code, "e2e runner exited non-zero:\n{$output}");
        $this->assertStringContainsString('0 failed', $output, $output);
        // The server thread writes to this process's standard output: a body that went there leaked.
        $this->assertStringNotContainsString('BODY-AFTER-POP', $output);
        $this->assertStringNotContainsString('LEAKED-TO-STDOUT', $output);
    }
}
