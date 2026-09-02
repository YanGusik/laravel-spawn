<?php

namespace Spawn\Laravel\Mail;

use Async\Pool;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * A mail transport that gives every concurrent send a connection of its own.
 *
 * SMTP carries one message as a sequence of commands (`MAIL FROM`, a `RCPT TO` per recipient,
 * `DATA`, the body, the terminating dot), and every one of them is a socket read or write that
 * TrueAsync suspends on. One transport per worker means one socket, so two coroutines sending
 * at once interleave their sequences: a protocol error, or one request's message delivered to
 * another request's recipients. The error path adds `RSET`, which aborts whatever transaction
 * the other coroutine has open.
 *
 * Each send borrows a whole transport for the duration of one message and returns it, so the
 * sequence on any one socket stays intact while up to `max` messages are sent at once.
 * Connections are reused across sends, which keeps the TLS handshake and `AUTH` off the
 * per-message path; the transport reconnects itself when the relay drops an idle connection.
 */
final class PooledTransport implements TransportInterface
{
    private readonly Pool $pool;

    /**
     * Slots whose send did not finish cleanly. Weak, so a slot the pool has already destroyed
     * leaves nothing behind.
     *
     * @var \WeakMap<TransportInterface, true>
     */
    private readonly \WeakMap $poisoned;

    /**
     * @param  \Closure(): TransportInterface  $factory  Builds one connection's transport. Called
     *   again whenever a slot is destroyed, so it must be repeatable and must not memoise.
     * @param  string  $description  What `__toString()` answers. Failover logs and message debug
     *   output carry it, so it should be what the pooled transports themselves report.
     * @param  int  $max  Connections this pool may open at once. 1 serialises every send.
     * @param  int  $acquireTimeoutMs  How long a send waits for a free connection; 0 waits
     *   until one frees, which leaves the deadline to the caller's own cancellation.
     */
    public function __construct(
        \Closure $factory,
        private readonly string $description,
        int $max,
        private readonly int $acquireTimeoutMs = 0,
    ) {
        $this->poisoned = new \WeakMap();

        // The callbacks must not capture $this: the pool is held by this object, and a
        // closure pointing back would keep both alive past the mailer that owns them.
        $poisoned = $this->poisoned;

        $this->pool = new Pool(
            factory: $factory,
            destructor: static fn (TransportInterface $transport) => self::discard($transport),
            beforeRelease: static fn (TransportInterface $transport) => ! isset($poisoned[$transport]),
            max: $max,
        );
    }

    /**
     * Close every connection the pool holds.
     *
     * Idempotent. Called from the destructor, so a `Mail::purge()` or the end of the worker
     * closes the connections; public because a test process runs one application after
     * another and must not leave the pool to the destructor phase.
     */
    public function close(): void
    {
        $this->pool->close();
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * @throws \Async\TimeoutException when the pool is busy and an acquire timeout is configured.
     *   A full pool is backpressure rather than a transport failure, so this is deliberately not
     *   a TransportExceptionInterface: failover treats one of those as a dead relay and stops
     *   using a healthy host for its whole retry period.
     */
    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        // One release per acquired slot, and none for an acquire that threw: the pool lowers
        // its active count on every release it is given, and one release too many leaves that
        // count below zero, where the pool answers every later send as full.
        $transport = $this->pool->acquire($this->acquireTimeoutMs);

        try {
            return $transport->send($message, $envelope);
        } catch (\Throwable $e) {
            // Any failure, not only a protocol one: a coroutine cancelled while suspended on a
            // reply leaves that reply in the socket, and the next command on this connection
            // would read it as its own answer.
            $this->poisoned[$transport] = true;

            throw $e;
        } finally {
            $this->pool->release($transport);
        }
    }

    public function __toString(): string
    {
        return $this->description;
    }

    /**
     * Drop a connection without touching the network.
     *
     * Runs wherever a slot dies, including inside `unset()` on the mailer and inside the
     * destructor phase of the process, so it must not suspend and must not throw.
     */
    private static function discard(TransportInterface $transport): void
    {
        if (! $transport instanceof SmtpTransport) {
            return;
        }

        // The stream goes first. stop() sends QUIT and waits for the reply until the socket
        // timeout (default_socket_timeout, 60 seconds, unless the mailer config sets one),
        // and a relay that has half-closed never answers. With the stream already gone the
        // write throws, and stop()'s finally still marks the transport stopped, which is what
        // makes the later __destruct() a no-op instead of a second attempt on a dead socket.
        $transport->getStream()->terminate();

        try {
            $transport->stop();
        } catch (\Throwable) {
        }
    }
}
