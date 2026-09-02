<?php

namespace Spawn\Laravel\Tests\Fixtures;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * A transport that writes its entry and exit into a shared log and suspends in between.
 *
 * The suspension stands for the round trips a real SMTP send makes: a send that never yields
 * cannot interleave, so a pool that let two coroutines share one connection would still look
 * correct without it.
 */
final class RecordingTransport implements TransportInterface
{
    /**
     * Which connection this is, counted across the process.
     *
     * A pool destroys a connection before building its replacement, and PHP hands the freed
     * object's id to the next object, so `spl_object_id()` would report the two as one.
     */
    public readonly int $serial;

    private static int $built = 0;

    /**
     * @param  int  $suspendMs  how long one send holds the connection
     * @param  bool  $fails  whether the send throws instead of finishing, standing for a relay
     *   that refused the message
     */
    public function __construct(
        private readonly SendLog $log,
        private readonly int $suspendMs = 1,
        private readonly bool $fails = false,
    ) {
        $this->serial = ++self::$built;
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $this->log->record('enter', $this->serial);

        \Async\delay($this->suspendMs);

        if ($this->fails) {
            $this->log->record('fail', $this->serial);

            throw new TransportException('the relay refused the message');
        }

        $this->log->record('leave', $this->serial);

        return null;
    }

    public function __toString(): string
    {
        return 'recording://fake';
    }
}
