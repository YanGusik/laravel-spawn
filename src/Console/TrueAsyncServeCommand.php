<?php

namespace Spawn\Laravel\Console;

use Illuminate\Console\Command;
use Spawn\Laravel\Server\TrueAsyncServer;
use function Async\await;
use function Async\await_all;
use function Async\spawn;
use function Async\spawn_thread;

class TrueAsyncServeCommand extends Command
{
    protected $signature = 'async:serve
        {--host=    : Override the listener host}
        {--port=    : Override the listener port}
        {--workers= : Number of worker threads (0=auto)}';

    protected $description = 'Start the TrueAsync HTTP server (multi-threaded)';

    public function handle(): int
    {
        if (!class_exists(\TrueAsync\HttpServer::class)) {
            $this->error('The TrueAsync extension is not installed or not loaded.');

            return self::FAILURE;
        }

        $cfg     = config('async.server', []);
        $workers = (int) ($this->option('workers') ?? $cfg['workers'] ?? 0);
        if ($workers <= 0) {
            $workers = \Async\available_parallelism();
        }

        $options = self::serverOptions($cfg, $this->option('host'), $this->option('port'), $workers);
        $options['grpc_handlers'] = config('async.grpc_handlers', []);

        ['host' => $host, 'port' => $port] = $options['listeners'][0];

        $this->info("Starting TrueAsync server with {$workers} workers on {$host}:{$port}");

        $autoloadPath  = base_path('vendor/autoload.php');
        $bootstrapPath = base_path('bootstrap/app.php');

        $server = new TrueAsyncServer($autoloadPath,$bootstrapPath,$options);
        $server->start();

        return self::SUCCESS;
    }

    /**
     * The server options for async.server and the command line.
     *
     * --host and --port override the first configured listener and keep its TLS and protocol;
     * every further listener is passed as configured. With none configured the first is
     * 0.0.0.0:8080.
     *
     * @param  array<string, mixed>  $config  async.server
     * @param  string|null  $host  --host, or null when not given
     * @param  string|null  $port  --port, or null when not given
     * @param  int  $workers  the worker count already resolved, at least 1
     * @return array<string, mixed>
     */
    public static function serverOptions(array $config, ?string $host, ?string $port, int $workers): array
    {
        $listeners = $config['listeners'] ?? [];
        $first = $listeners[0] ?? [];

        $listeners[0] = array_merge($first, [
            'host' => $host ?? $first['host'] ?? '0.0.0.0',
            'port' => (int) ($port ?? $first['port'] ?? 8080),
        ]);

        return array_merge($config, ['listeners' => array_values($listeners), 'workers' => $workers]);
    }
}