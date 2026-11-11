<?php

namespace Spawn\Laravel\Http\Kernel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Routing\Router;

/**
 * Laravel's stock HTTP kernel with the request's start time kept per request
 * ({@see PerRequestStartTime}). The package puts it in place of the stock kernel; an application
 * with a kernel of its own adds the trait to that kernel instead.
 */
class AsyncHttpKernel extends Kernel
{
    use PerRequestStartTime;

    public function __construct(Application $app, Router $router)
    {
        parent::__construct($app, $router);

        $this->keepRequestStartPerRequest();
    }
}
