<?php

namespace TrueAsync\Laravel\Tests;

use Illuminate\Http\Request;

use function Async\coroutine_context;
use function Async\delay;

class RequestIsolationTest extends AsyncTestCase
{
    public function test_each_coroutine_resolves_its_own_request(): void
    {
        $app = $this->createApp();

        $results = $this->runParallel([
            'user1' => function () use ($app) {
                $request = Request::create('/test?user=1');
                coroutine_context()->set('laravel.request', $request);
                delay(200);
                return $app->make('request')->query('user');
            },
            'user2' => function () use ($app) {
                $request = Request::create('/test?user=2');
                coroutine_context()->set('laravel.request', $request);
                delay(200);
                return $app->make('request')->query('user');
            },
            'user3' => function () use ($app) {
                $request = Request::create('/test?user=3');
                coroutine_context()->set('laravel.request', $request);
                delay(200);
                return $app->make('request')->query('user');
            },
        ]);

        $this->assertSame('1', $results['user1']);
        $this->assertSame('2', $results['user2']);
        $this->assertSame('3', $results['user3']);
    }
}
