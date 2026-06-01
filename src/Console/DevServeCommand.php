<?php

namespace Spawn\Laravel\Console;

use Illuminate\Console\Command;
use Spawn\Laravel\Server\DevServer;

class DevServeCommand extends Command
{
    protected $signature = 'async:dev
        {--host=0.0.0.0 : The host to listen on}
        {--port=8080    : The port to listen on}';

    protected $description = 'Start the TrueAsync HTTP server';

    public function handle(): void
    {
        $host = $this->option('host');
        $port = (int) $this->option('port');

        $this->info("Starting TrueAsync HTTP server on {$host}:{$port}");

        $server = new DevServer($this->laravel, $host, $port);
        $server->prepareApp();
        $server->start();
    }
}
