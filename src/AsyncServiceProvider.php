<?php

namespace Spawn\Laravel;

use Illuminate\Support\ServiceProvider;
use Spawn\Laravel\Console\FrankenServeCommand;
use Spawn\Laravel\Console\ServeCommand;

class AsyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/async.php', 'async');

        $this->commands([
            ServeCommand::class,
            FrankenServeCommand::class,
        ]);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/async.php' => config_path('async.php'),
        ], 'async-config');
    }
}
