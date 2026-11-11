<?php

namespace Spawn\Laravel\Tests;

use Async\Scope;
use Spawn\Laravel\Foundation\RequestContext;
use Spawn\Laravel\Log\RequestLogContext;

use function Async\await;
use function Async\root_context;
use function Async\spawn;

/**
 * Servers that get no request scope from the extension (DevServer, FrankenPHP) mark the request's
 * own scope with RequestContext::begin(), so that a coroutine of a nested Scope::inherit() reads
 * the request's per-request state as it does under TrueAsyncServer.
 */
class RequestBoundaryTest extends AsyncTestCase
{
    /** Run $inner in a coroutine of a scope nested one level inside the current one. */
    private static function inNestedScope(callable $inner): mixed
    {
        $nested = Scope::inherit();

        return await($nested->spawn($inner));
    }

    public function test_a_nested_scope_inside_a_marked_request_reads_the_requests_state(): void
    {
        $results = $this->runParallel(['a' => function () {
            RequestContext::begin();
            RequestLogContext::add(['trace_id' => 'abc']);

            return self::inNestedScope(fn () => [RequestLogContext::all(), RequestContext::current()]);
        }]);

        [$seen, $context] = $results['a'];

        $this->assertSame(['trace_id' => 'abc'], $seen);
        $this->assertNotSame(root_context(), $context);
    }

    public function test_what_a_nested_scope_writes_reaches_the_marked_request(): void
    {
        $results = $this->runParallel(['a' => function () {
            RequestContext::begin();
            self::inNestedScope(fn () => RequestLogContext::add(['from' => 'nested']));

            return RequestLogContext::all();
        }]);

        $this->assertSame(['from' => 'nested'], $results['a']);
    }

    public function test_outside_any_request_a_nested_scope_inherits_the_state(): void
    {
        $results = $this->runParallel(['a' => function () {
            RequestLogContext::add(['job' => 'import']);

            return self::inNestedScope(fn () => RequestLogContext::all());
        }]);

        $this->assertSame(['job' => 'import'], $results['a']);
    }

    public function test_a_marked_request_does_not_read_state_left_in_the_root_context(): void
    {
        root_context()->set('spawn.log.context', ['leaked' => true], replace: true);

        try {
            $results = $this->runParallel(['a' => function () {
                RequestContext::begin();

                return RequestLogContext::all();
            }]);
        } finally {
            root_context()->unset('spawn.log.context');
        }

        $this->assertSame([], $results['a']);
    }
}
