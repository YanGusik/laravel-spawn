<?php

namespace Bench\Grpc;

use TrueAsync\HttpRequest;
use TrueAsync\HttpResponse;

/**
 * Bounces the incoming message straight back — used by StreamingE2ETest to
 * assert grpc_handlers dispatch (readMessage/writeMessage) works end to end,
 * without needing real protobuf schemas.
 */
class EchoHandler
{
    public function echo(HttpRequest $req, HttpResponse $res): void
    {
        $res->writeMessage($req->readMessage() ?? '');
    }
}
