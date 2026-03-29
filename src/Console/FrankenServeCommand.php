<?php

namespace Spawn\Laravel\Console;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class FrankenServeCommand extends Command
{
    protected $signature = 'async:franken
        {--host=0.0.0.0       : Host to listen on}
        {--port=8080           : Port to listen on}
        {--workers=1           : Number of PHP worker threads}
        {--buffer=20           : Per-worker request buffer size (max queued requests)}
        {--watch               : Watch for file changes and automatically reload workers}';

    protected $description = 'Start the TrueAsync FrankenPHP server';

    public function handle(): int
    {
        $this->ensureFrankenPhpIsInstalled();

        $host    = $this->option('host');
        $port    = (int) $this->option('port');
        $workers = max(1, (int) $this->option('workers'));
        $buffer  = max(1, (int) $this->option('buffer'));
        $watch   = (bool) $this->option('watch');

        $stateDir = storage_path('app/trueasync');
        if (!is_dir($stateDir)) {
            mkdir($stateDir, 0755, true);
        }

        $workerPath   = $stateDir . '/worker.php';
        $caddyfilePath = $stateDir . '/Caddyfile';

        $this->writeWorkerFile($workerPath);
        $this->writeCaddyfile($caddyfilePath, $workerPath, $host, $port, $workers, $buffer, $watch);

        $this->info("Starting TrueAsync FrankenPHP on {$host}:{$port} ({$workers} worker(s), buffer={$buffer})" . ($watch ? ' (--watch)' : ''));
        $this->line("  Worker:    {$workerPath}");
        $this->line("  Caddyfile: {$caddyfilePath}");
        $this->newLine();

        $process = new Process(
            ['frankenphp', 'run', '--config', $caddyfilePath],
            base_path(),
            array_merge($_ENV, ['APP_ENV' => app()->environment()]),
        );

        $process->setTimeout(null);
        $process->start(fn($type, $output) => $this->output->write($output));

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn() => $process->stop(3));
        pcntl_signal(SIGINT, fn() => $process->stop(3));

        return $process->wait() === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function ensureFrankenPhpIsInstalled(): void
    {
        exec('which frankenphp 2>/dev/null', $output, $code);

        if ($code !== 0) {
            $this->error('`frankenphp` binary not found in PATH.');
            $this->line('Make sure you are inside the trueasync/php-true-async:latest-frankenphp container.');
            exit(1);
        }
    }

    private function writeWorkerFile(string $path): void
    {
        $basePath = base_path();

        file_put_contents($path, <<<PHP
        <?php

        ini_set('display_errors', '1');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);

        require_once '{$basePath}/vendor/autoload.php';

        \$app = require_once '{$basePath}/bootstrap/app.php';

        // bootstrap/app.php only creates the container — config, env etc. are not loaded yet.
        // Kernel::bootstrap() runs all bootstrappers (LoadConfiguration, LoadEnvironmentVariables, etc.)
        \$app->make(\\Illuminate\\Contracts\\Http\\Kernel::class)->bootstrap();

        \$server = new \\Spawn\\Laravel\\Server\\FrankenPhpServer(\$app);
        \$server->prepareApp();
        \$server->start();
        PHP);
    }

    private function writeCaddyfile(
        string $path,
        string $workerPath,
        string $host,
        int $port,
        int $workers,
        int $buffer,
        bool $watch = false,
    ): void {
        $appPath       = base_path();
        $watchDirective = $watch ? "\n                        watch" : '';

        // Use :port (no host) to avoid Caddy treating the host as a domain and enabling TLS.
        // The bind directive restricts which interface to listen on.
        // Static files (CSS, JS, images) are served directly by Caddy from public/.
        // All other requests go to the async PHP worker.
        file_put_contents($path, <<<CADDY
        {
            admin off
            frankenphp {
            }
        }

        :{$port} {
            bind {$host}
            root * {$appPath}/public

            @static file
            handle @static {
                file_server
            }

            route {
                php_server {
                    index off
                    file_server off

                    worker {
                        file {$workerPath}
                        num {$workers}
                        async
                        buffer_size {$buffer}
                        match *{$watchDirective}
                    }
                }
            }

            log {
                output file {$this->logPath()}
                level INFO
            }
        }
        CADDY);
    }

    private function logPath(): string
    {
        return storage_path('logs/frankenphp-access.log');
    }
}
