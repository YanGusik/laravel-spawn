<?php

namespace Spawn\Laravel\Http\Client;

use Illuminate\Support\Collection;

/**
 * The state one request installs on the HTTP client factory.
 *
 * Everything here answers a question about the request that is running: what its outbound
 * calls are stubbed with, whether it is recording them, whether it refuses to leave the
 * process. The factory itself stays one object per worker, because global middleware and
 * global options are the worker's and a fresh factory per call would lose them.
 */
final class HttpClientState
{
    /** @var Collection<int, callable> */
    public Collection $stubCallbacks;

    public bool $recording = false;

    /** @var list<array{0: \Illuminate\Http\Client\Request, 1: \Illuminate\Http\Client\Response|null}> */
    public array $recorded = [];

    /** @var list<\Illuminate\Http\Client\ResponseSequence> */
    public array $responseSequences = [];

    public bool $preventStrayRequests = false;

    /** @var array<int, string> */
    public array $allowedStrayRequestUrls = [];

    public function __construct()
    {
        $this->stubCallbacks = new Collection();
    }
}
