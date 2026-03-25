<?php

namespace TrueAsync\Laravel\Server;

use Async\Future;
use Async\FutureState;
use Async\Scope;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;

use function Async\coroutine_context;

class HttpServer
{
    private ?Scope $serverScope = null;

    public function __construct(
        private readonly Application $app,
        private readonly string $host,
        private readonly int $port,
    ) {}

    public function __destruct()
    {
        $this->serverScope?->dispose();
    }

    public function prepareApp(): void
    {
        if ($this->app instanceof \TrueAsync\Laravel\Foundation\AsyncApplication) {
            $this->app->enableAsyncMode();
        }

        $this->configureDatabasePool();
    }

    /**
     * Force the DatabaseManager to establish its connection before any request coroutines start.
     * This ensures the PDO Pool is created in the server coroutine scope, not per-request.
     */
    private function warmUpDatabasePool(): void
    {
        $poolConfig = $this->app->make('config')->get('async.db_pool', []);

        if (empty($poolConfig['enabled'])) {
            return;
        }

        if (!$this->app->bound('db')) {
            return;
        }

        try {
            $this->app->make('db')->connection()->getPdo();
        } catch (\Throwable $e) {
            echo "[async] DB pool warm-up failed: " . $e->getMessage() . "\n";
        }
    }

    private function configureDatabasePool(): void
    {
        $poolConfig = $this->app->make('config')->get('async.db_pool', []);

        if (empty($poolConfig['enabled'])) {
            return;
        }

        $connections = $this->app->make('config')->get('database.connections', []);

        foreach (array_keys($connections) as $name) {
            $this->app->make('config')->set(
                "database.connections.{$name}.options",
                array_replace(
                    $this->app->make('config')->get("database.connections.{$name}.options", []),
                    [
                        \PDO::ATTR_POOL_ENABLED              => true,
                        \PDO::ATTR_POOL_MIN                  => $poolConfig['min'] ?? 2,
                        \PDO::ATTR_POOL_MAX                  => $poolConfig['max'] ?? 10,
                        \PDO::ATTR_POOL_HEALTHCHECK_INTERVAL => $poolConfig['healthcheck_interval'] ?? 30,
                    ]
                )
            );
        }
    }

    public function start(): void
    {
        $shutdownState = new FutureState();
        $shutdownFuture = new Future($shutdownState);

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn() => $shutdownState->complete(null));
        pcntl_signal(SIGINT, fn() => $shutdownState->complete(null));

        $this->serverScope = new Scope();
        $serverScope = $this->serverScope;

        $serverScope->setExceptionHandler(function (\Throwable $e) {
            echo "[server error] " . $e::class . ": " . $e->getMessage() . "\n";
        });

        $serverScope->spawn(function () use ($serverScope) {
            $this->warmUpDatabasePool();

            $socket = stream_socket_server("tcp://{$this->host}:{$this->port}");

            if ($socket === false) {
                throw new \RuntimeException("Failed to bind tcp://{$this->host}:{$this->port}");
            }

            echo "Listening on tcp://{$this->host}:{$this->port}\n";

            while (true) {
                $client = stream_socket_accept($socket, timeout: -1);

                if ($client === false) {
                    continue;
                }

                $serverScope->spawn($this->handleConnection(...), $client);
            }
        });

        try {
            $serverScope->awaitCompletion($shutdownFuture);
        } catch (\Async\AsyncCancellation) {
            $serverScope->cancel();
            $this->serverScope = null;
        }
    }

    private function handleConnection(mixed $client): void
    {
        try {
            $raw = $this->readRaw($client);

            if ($raw === '') {
                return;
            }

            $request = RequestParser::parse($raw);

            coroutine_context()->set('laravel.request', $request);

            $kernel = $this->app->make(Kernel::class);
            $response = $kernel->handle($request);

            ResponseEmitter::emit($client, $response);

            $kernel->terminate($request, $response);
        } finally {
            fclose($client);
        }
    }

    private function readRaw(mixed $client): string
    {
        $raw = '';

        while ($chunk = fread($client, 8192)) {
            $raw .= $chunk;

            if (str_contains($raw, "\r\n\r\n")) {
                if (preg_match('/Content-Length:\s*(\d+)/i', $raw, $m)) {
                    $headerEnd = strpos($raw, "\r\n\r\n") + 4;
                    $bodyLength = (int) $m[1];
                    $bodyRead = strlen($raw) - $headerEnd;

                    while ($bodyRead < $bodyLength) {
                        $chunk = fread($client, $bodyLength - $bodyRead);
                        if ($chunk === false || $chunk === '') {
                            break;
                        }
                        $raw .= $chunk;
                        $bodyRead += strlen($chunk);
                    }
                }

                break;
            }
        }

        return $raw;
    }
}
