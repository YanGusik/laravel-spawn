<?php

namespace Spawn\Laravel\Tests;

use Spawn\Laravel\Async\RawIo;

/**
 * Outside TrueAsyncServer there is no request scope, and the raw objects are asked for in vain:
 * the answer is the documented RuntimeException, not an Error on a null context.
 */
class RawIoTest extends AsyncTestCase
{
    public function test_the_raw_request_outside_the_server_is_a_runtime_exception(): void
    {
        $results = $this->runParallel(['a' => function () {
            try {
                RawIo::request();
            } catch (\Throwable $e) {
                return $e::class;
            }

            return null;
        }]);

        $this->assertSame(\RuntimeException::class, $results['a']);
    }

    public function test_the_raw_response_outside_the_server_is_a_runtime_exception(): void
    {
        $results = $this->runParallel(['a' => function () {
            try {
                RawIo::response();
            } catch (\Throwable $e) {
                return $e::class;
            }

            return null;
        }]);

        $this->assertSame(\RuntimeException::class, $results['a']);
    }
}
