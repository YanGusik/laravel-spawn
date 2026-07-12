<?php

use Spawn\Laravel\Async\RawIo;
use TrueAsync\HttpRequest;
use TrueAsync\HttpResponse;

if (! function_exists('trueasync_request')) {
    /**
     * The raw TrueAsync HttpRequest behind the current Illuminate request.
     * Needed for gRPC (readMessage) and anything else the Illuminate Request
     * does not expose.
     */
    function trueasync_request(): HttpRequest
    {
        return RawIo::request();
    }
}

if (! function_exists('trueasync_response')) {
    /**
     * The raw TrueAsync HttpResponse behind the current Illuminate response.
     * Use it to stream (SSE, writeMessage for gRPC) instead of returning
     * a buffered Illuminate Response.
     */
    function trueasync_response(): HttpResponse
    {
        return RawIo::response();
    }
}
