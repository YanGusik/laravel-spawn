<?php

namespace Spawn\Laravel\Tests;

use PHPUnit\Framework\TestCase;
use Spawn\Laravel\Server\StreamedBody;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * StreamedBody against a body and a client that misbehave (#85, Issue 23). An output buffer
 * of the test's own stands in for the worker's standard output: whatever reaches it reached
 * the worker's log instead of the client.
 */
class StreamedBodyTest extends TestCase
{
    private int $level;

    protected function setUp(): void
    {
        ob_start();
        $this->level = ob_get_level();
    }

    protected function tearDown(): void
    {
        while (ob_get_level() >= $this->level) {
            ob_end_clean();
        }
    }

    private function leaked(): string
    {
        $this->assertSame($this->level, ob_get_level(), 'forward() left a buffer of its own open');

        return (string) ob_get_contents();
    }

    private static function gone(): \Closure
    {
        return static function (string $chunk): void {
            throw new \RuntimeException('stream closed by peer');
        };
    }

    public function test_a_client_gone_before_the_last_chunk_ends_the_body_quietly(): void
    {
        $delivered = StreamedBody::forward(new StreamedResponse(function () {
            echo 'hello';
        }), self::gone());

        $this->assertFalse($delivered);
        $this->assertSame('', $this->leaked());
    }

    public function test_a_client_gone_after_the_body_popped_one_buffer_leaks_nothing(): void
    {
        $delivered = StreamedBody::forward(new StreamedResponse(function () {
            ob_end_clean();
            echo str_repeat('B', 5000);
            echo 'AFTER';
        }), self::gone());

        $this->assertFalse($delivered);
        $this->assertSame('', $this->leaked());
    }

    public function test_a_body_that_pops_every_buffer_sends_what_it_wrote_once(): void
    {
        $sent = '';

        try {
            StreamedBody::forward(new StreamedResponse(function () {
                ob_end_flush();
                echo 'XYZ';
                ob_end_flush();
            }), static function (string $chunk) use (&$sent): void {
                $sent .= $chunk;
            });
            $this->fail('A body that removed every forwarding buffer has to fail');
        } catch (\LogicException) {
        }

        $this->assertSame('XYZ', $sent);
        $this->assertSame('', $this->leaked());
    }
}
