<?php

namespace Spawn\Laravel\Tests;

use Async\Scope;
use PHPUnit\Framework\TestCase;
use Spawn\Laravel\Foundation\AsyncApplication;

abstract class AsyncTestCase extends TestCase
{
    protected function createApp(): AsyncApplication
    {
        $app = new AsyncApplication(sys_get_temp_dir());
        $app->enableAsyncMode();

        return $app;
    }

    /**
     * Run closures in parallel, each within its own child Scope
     * (simulating per-request isolation as the real servers do).
     */
    protected function runParallel(array $coroutines): array
    {
        $results = [];
        $scope = new Scope();

        foreach ($coroutines as $key => $fn) {
            $scope->spawn(function () use ($key, $fn, &$results) {
                // Each "request" gets its own child scope so that
                // current_context() is isolated per-request.
                $requestScope = Scope::inherit();

                $requestScope->spawn(function () use ($key, $fn, &$results) {
                    $results[$key] = $fn();
                });

                $requestScope->awaitCompletion(\Async\timeout(5000));
            });
        }

        $scope->awaitCompletion(\Async\timeout(5000));

        return $results;
    }
}
