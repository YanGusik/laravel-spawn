<?php

namespace Spawn\Laravel\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;

/** A provider of the kind Laravel registers only when an event fires (`when()`): it sets configuration. */
final class EventLoadedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app['config']->set('event_loaded.ready', true);
    }
}
