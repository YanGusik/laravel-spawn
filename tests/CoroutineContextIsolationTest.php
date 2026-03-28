<?php

namespace Spawn\Laravel\Tests;

use function Async\coroutine_context;
use function Async\delay;

class CoroutineContextIsolationTest extends AsyncTestCase
{
    public function test_each_coroutine_has_own_context(): void
    {
        $results = $this->runParallel([
            'a' => function () {
                coroutine_context()->set('value', 'A');
                delay(200);
                return coroutine_context()->find('value');
            },
            'b' => function () {
                coroutine_context()->set('value', 'B');
                delay(200);
                return coroutine_context()->find('value');
            },
            'c' => function () {
                coroutine_context()->set('value', 'C');
                delay(200);
                return coroutine_context()->find('value');
            },
        ]);

        $this->assertSame('A', $results['a']);
        $this->assertSame('B', $results['b']);
        $this->assertSame('C', $results['c']);
    }

    public function test_context_value_not_visible_in_sibling_coroutine(): void
    {
        $results = $this->runParallel([
            'writer' => function () {
                coroutine_context()->set('secret', 'only-mine');
                delay(200);
                return 'done';
            },
            'reader' => function () {
                delay(100); // start after writer sets the value
                return coroutine_context()->find('secret');
            },
        ]);

        $this->assertNull($results['reader'], 'sibling coroutine must not see another coroutine\'s context');
    }
}
