<?php

namespace TrueAsync\Laravel\Console;

use Illuminate\Console\Command;
use TrueAsync\Laravel\Server\HttpServer;

class ServeCommand extends Command
{
    protected $signature = 'async:serve
        {--host=0.0.0.0 : The host to listen on}
        {--port=8080 : The port to listen on}';

    protected $description = 'Start the TrueAsync HTTP server';

    public function handle(): void
    {
        $host = $this->option('host');
        $port = (int) $this->option('port');

        $this->info("Starting TrueAsync HTTP server on {$host}:{$port}");

        $server = new HttpServer($this->laravel, $host, $port);
        $server->prepareApp();
        $server->start();
    }
}
