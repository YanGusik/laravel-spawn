<?php

namespace Spawn\Laravel\Tests\Fixtures;

use Illuminate\View\ViewServiceProvider;

/** An application's own view provider, as one that swaps the finder is written: registered after the package's. */
final class AppViewServiceProvider extends ViewServiceProvider
{
}
