<?php

namespace TrueAsync\Laravel;

use Illuminate\Support\ServiceProvider;
use TrueAsync\Laravel\Console\ServeCommand;

class AsyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/async.php', 'async');

        $this->commands([
            ServeCommand::class,
        ]);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/async.php' => config_path('async.php'),
        ], 'async-config');
    }
}
