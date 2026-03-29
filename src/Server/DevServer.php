<?php

namespace Spawn\Laravel\Server;

use Async\Future;
use Async\FutureState;
use Async\Scope;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Spawn\Laravel\Contracts\ServerInterface;
use Spawn\Laravel\Foundation\ContextKeys;
use Spawn\Laravel\Server\Concerns\ManagesDatabasePool;

use function Async\current_context;

class DevServer implements ServerInterface
{
    use ManagesDatabasePool;

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
        if ($this->app instanceof \Spawn\Laravel\Foundation\AsyncApplication) {
            $this->app->enableAsyncMode();
        }

        $this->configureDatabasePool();
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

                // Each request gets its own Scope so that current_context()
                // is isolated per-request and child coroutines can access
                // request-scoped services via hierarchical find().
                $requestScope = Scope::inherit($serverScope);

                $requestScope->setExceptionHandler(function (\Throwable $e) {
                    echo "[request error] " . $e::class . ": " . $e->getMessage() . "\n";
                });

                $requestScope->spawn($this->handleConnection(...), $client, $requestScope);
            }
        });

        try {
            $serverScope->awaitCompletion($shutdownFuture);
        } catch (\Async\AsyncCancellation) {
            $serverScope->cancel();
            $this->serverScope = null;
        }
    }

    private function handleConnection(mixed $client, Scope $requestScope): void
    {
        try {
            $raw = $this->readRaw($client);

            if ($raw === '') {
                return;
            }

            $request = RequestParser::parse($raw);

            current_context()->set(ContextKeys::$request, $request);

            $kernel = $this->app->make(Kernel::class);
            $response = $kernel->handle($request);

            ResponseEmitter::emit($client, $response);

            $kernel->terminate($request, $response);
        } finally {
            $requestScope->dispose();
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
