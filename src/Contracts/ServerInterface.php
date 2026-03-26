<?php

namespace TrueAsync\Laravel\Contracts;

interface ServerInterface
{
    /**
     * Prepare the application for async serving.
     * Called once before start() — enables async mode, configures DB pool, etc.
     */
    public function prepareApp(): void;

    /**
     * Start the server and block until shutdown.
     */
    public function start(): void;
}
