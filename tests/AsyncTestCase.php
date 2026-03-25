<?php

namespace TrueAsync\Laravel\Tests;

use Async\Scope;
use PHPUnit\Framework\TestCase;
use TrueAsync\Laravel\Foundation\AsyncApplication;
abstract class AsyncTestCase extends TestCase
{
    protected function createApp(): AsyncApplication
    {
        $app = new AsyncApplication(sys_get_temp_dir());
        $app->enableAsyncMode();

        return $app;
    }

    protected function runParallel(array $coroutines): array
    {
        $results = [];
        $scope = new Scope();

        foreach ($coroutines as $key => $fn) {
            $scope->spawn(function () use ($key, $fn, &$results) {
                $results[$key] = $fn();
            });
        }

        $scope->awaitCompletion(\Async\timeout(5000));

        return $results;
    }
}
