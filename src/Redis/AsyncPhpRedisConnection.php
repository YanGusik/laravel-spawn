<?php

namespace Spawn\Laravel\Redis;

use Closure;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Redis;
use Throwable;

use function Async\coroutine_context;

/**
 * A pooled phpredis connection whose `withoutSerializationOrCompression()` switches the
 * serializer off for the calling coroutine alone.
 *
 * Upstream switches `OPT_SERIALIZER` and `OPT_COMPRESSION` off on the client, runs the callback
 * and switches them back. In pool mode the client is the pool's template, whose options every
 * pooled connection takes on acquire, so while the callback waits on Redis every coroutine of
 * the worker reads and writes without them: cached arrays come back as raw bytes, as `false`,
 * or as a TypeError from `RedisStore`. Laravel's rate limiter makes that call on every
 * throttled request.
 *
 * Here the callback runs against a second pooled template, the raw client, built from the same
 * configuration with the serializer and compression off. `$client` answers with it while the
 * calling coroutine is inside the window, so every method of the connection — commands,
 * pack(), serialized(), compressed(), pipelines, client() — follows without a copy of upstream
 * code. The raw pool has no multiplexed lanes and no warm sockets, and opens its first one when
 * a window first sends a command.
 *
 * A coroutine spawned inside the window does not inherit it: the context is the opener's own,
 * and a child runs with the serializer on.
 */
class AsyncPhpRedisConnection extends PhpRedisConnection
{
    /**
     * The connection's client: the raw one inside this coroutine's window, the configured one
     * everywhere else. Writes — rebuildClient() — replace the configured one.
     *
     * @var \Redis
     */
    protected $client {
        get => $this->openWindows > 0 && coroutine_context()->findLocal($this->windowKey) === true
            ? $this->raw
            : $this->client;
    }

    /** How many coroutines are inside a window right now, so that no other read pays for one. */
    private int $openWindows = 0;

    private Redis $raw;

    /**
     * The window's key in the coroutine's context: a string rather than the connection
     * itself, so that a context outliving the connection does not keep it alive.
     */
    private readonly string $windowKey;

    /**
     * @param  Closure(): Redis  $rawConnector  builds the raw client
     */
    public function __construct($client, callable $connector, array $config, private readonly Closure $rawConnector)
    {
        parent::__construct($client, $connector, $config);

        $this->windowKey = 'spawn.redis.raw-window.'.spl_object_id($this);
        $this->raw = ($this->rawConnector)();
    }

    /**
     * Run the callback with values sent and read as they are, in this coroutine and nowhere
     * else.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function withoutSerializationOrCompression(callable $callback)
    {
        if (! $this->serialized() && ! $this->compressed()) {
            return $callback();
        }

        $context = coroutine_context();
        $previous = $context->findLocal($this->windowKey);

        $context->set($this->windowKey, true, true);
        $this->openWindows++;

        try {
            return $callback();
        } finally {
            $this->openWindows--;
            $context->set($this->windowKey, $previous, true);
        }
    }

    /**
     * Close the raw pool with the configured one, so that a purged connection leaves no idle
     * sockets behind.
     */
    public function disconnect()
    {
        parent::disconnect();

        try {
            $this->raw->close();
        } catch (Throwable) {
            //
        }
    }

    /**
     * A lost connection inside a window is the raw client's: rebuild that one and leave the
     * configured client, which every other coroutine is using, as it is.
     */
    protected function rebuildClient()
    {
        if ($this->client !== $this->raw) {
            parent::rebuildClient();

            return;
        }

        try {
            $this->raw->close();
        } catch (Throwable) {
            //
        }

        $this->raw = ($this->rawConnector)();
    }
}
