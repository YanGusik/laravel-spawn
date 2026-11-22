<?php

namespace Spawn\Laravel\Server;

use Illuminate\Contracts\Debug\ShouldntReport;

/**
 * Thrown into a response body that writes after its client has gone, to stop it.
 *
 * connection_aborted() stays 0 under the server, so a body that loops until it turns true,
 * as eventStream() does, would otherwise run for good. Not reported: a client that hangs up
 * is not an error of the application.
 *
 * @internal
 */
final class PeerGone extends \RuntimeException implements ShouldntReport
{
}
