<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Http\Request;
use Spawn\Laravel\Foundation\ContextKeys;

use function Async\current_context;
use function Async\delay;

class RequestIsolationTest extends AsyncTestCase
{
    public function test_each_coroutine_resolves_its_own_request(): void
    {
        $app = $this->createApp();

        $results = $this->runParallel([
            'user1' => function () use ($app) {
                $request = Request::create('/test?user=1');
                current_context()->set(ContextKeys::$request, $request);
                delay(200);
                return $app->make('request')->query('user');
            },
            'user2' => function () use ($app) {
                $request = Request::create('/test?user=2');
                current_context()->set(ContextKeys::$request, $request);
                delay(200);
                return $app->make('request')->query('user');
            },
            'user3' => function () use ($app) {
                $request = Request::create('/test?user=3');
                current_context()->set(ContextKeys::$request, $request);
                delay(200);
                return $app->make('request')->query('user');
            },
        ]);

        $this->assertSame('1', $results['user1']);
        $this->assertSame('2', $results['user2']);
        $this->assertSame('3', $results['user3']);
    }

    public function test_child_coroutine_inherits_request_from_scope(): void
    {
        $app = $this->createApp();

        $results = $this->runParallel([
            'parent' => function () use ($app) {
                $request = Request::create('/test?user=parent');
                current_context()->set(ContextKeys::$request, $request);

                // Spawn a child coroutine — it should see the request
                // via hierarchical find() on the scope context.
                $childResult = null;
                $scope = \Async\Scope::inherit();
                $scope->spawn(function () use ($app, &$childResult) {
                    delay(50);
                    $childResult = $app->make('request')->query('user');
                });
                $scope->awaitCompletion(\Async\timeout(2000));

                return $childResult;
            },
        ]);

        $this->assertSame('parent', $results['parent']);
    }
}
