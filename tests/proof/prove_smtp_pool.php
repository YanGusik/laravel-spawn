<?php

/**
 * One SMTP connection per worker carries the commands of concurrent sends interleaved.
 *
 * `MailManager` memoises one mailer per name for the life of the worker, and the mailer holds
 * one transport with one socket. A message is a sequence of commands (`MAIL FROM`, a `RCPT TO`
 * per recipient, `DATA`, the body, the terminating dot) rather than one round trip, and every
 * step is a socket read or write that TrueAsync suspends on, so a second coroutine enters the
 * sequence while the first is waiting.
 *
 * The reproducer speaks the protocol rather than mocking it: an SMTP sink listens on a loopback
 * port in this process, answers the four codes a send needs, and records what it read on each
 * connection. The oracle is that record — a connection that carried whole messages shows
 * `EHLO MAIL RCPT DATA BODY` in that order, and a connection that carried two sends at once
 * cannot.
 *
 * The sink replies after a short delay, which is what a relay across a network does; without it
 * a send can finish before the scheduler ever hands the coroutine over, and the shared transport
 * looks correct.
 *
 * Exits 0 if the pooled arrangement kept every message whole, 1 if it did not, 2 if a control
 * failed. Reported as YanGusik/laravel-spawn#73.
 */

use Spawn\Laravel\Mail\PooledTransport;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/harness.php';

const SENDERS = 3;
const POOL_SIZE = 2;
const REPLY_DELAY_MS = 3;

/**
 * An SMTP server that answers a send and remembers the order it was asked.
 *
 * Only the commands Symfony's transport issues are understood; anything else is refused, which
 * keeps a protocol change visible instead of silently accepted.
 */
final class ProofSink
{
    /** @var array<int, list<string>> commands read, keyed by the connection that carried them */
    public array $readPerConnection = [];

    private $server;

    public readonly int $port;

    private bool $running = true;

    private int $connections = 0;

    public function __construct(private readonly int $replyDelayMs)
    {
        $this->server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if (! $this->server) {
            throw new RuntimeException("the proof sink could not listen: $errstr");
        }

        $address = stream_socket_get_name($this->server, false);
        $this->port = (int) substr($address, strrpos($address, ':') + 1);
    }

    /** Accept connections until stop() is called, each served by a coroutine of its own. */
    public function serve(): void
    {
        $connections = [];

        while ($this->running) {
            $client = @stream_socket_accept($this->server, 0.2);

            if (! $client) {
                continue;
            }

            $id = ++$this->connections;
            $connections[] = \Async\spawn(fn () => $this->converse($client, $id));
        }

        if ($connections) {
            \Async\await_all($connections);
        }

        fclose($this->server);
    }

    public function stop(): void
    {
        $this->running = false;
    }

    private function converse($client, int $id): void
    {
        // Reads come back every second so that a connection left open by a failed send does not
        // hold the sink after stop().
        stream_set_timeout($client, 1);
        $this->readPerConnection[$id] = [];
        $this->reply($client, '220 proof sink ESMTP');

        while (true) {
            $line = fgets($client);

            if ($line === false) {
                if (! empty(stream_get_meta_data($client)['timed_out']) && $this->running) {
                    continue;
                }

                break;
            }

            $verb = strtoupper(strtok(rtrim($line, "\r\n"), ' :'));
            $this->readPerConnection[$id][] = $verb;

            match ($verb) {
                'EHLO' => $this->reply($client, "250-proof sink\r\n250 SIZE 10000000"),
                'MAIL', 'RCPT', 'RSET', 'NOOP' => $this->reply($client, '250 Ok'),
                'DATA' => $this->readBody($client, $id),
                'QUIT' => $this->reply($client, '221 Bye'),
                default => $this->reply($client, '500 unknown command'),
            };

            if ($verb === 'QUIT') {
                break;
            }
        }

        fclose($client);
    }

    private function readBody($client, int $id): void
    {
        $this->reply($client, '354 End data with <CR><LF>.<CR><LF>');

        while (($line = fgets($client)) !== false) {
            if ($line === ".\r\n" || $line === ".\n") {
                break;
            }
        }

        $this->readPerConnection[$id][] = 'BODY';
        $this->reply($client, '250 Ok: queued as proof');
    }

    private function reply($client, string $line): void
    {
        \Async\delay($this->replyDelayMs);

        fwrite($client, $line . "\r\n");
    }
}

/**
 * Whether one connection's commands are whole messages, one after another.
 *
 * A greeting, then any number of messages, each `MAIL`, one or more `RCPT`, `DATA`, `BODY`.
 * Two sends sharing the connection cannot produce this: their commands arrive interleaved.
 */
function proof_carried_whole_messages(array $commands): bool
{
    $remaining = array_values(array_filter($commands, static fn (string $c) => $c !== 'NOOP' && $c !== 'QUIT'));

    if (array_shift($remaining) !== 'EHLO') {
        return false;
    }

    while ($remaining) {
        if (array_shift($remaining) !== 'MAIL') {
            return false;
        }

        if (($remaining[0] ?? null) !== 'RCPT') {
            return false;
        }

        while (($remaining[0] ?? null) === 'RCPT') {
            array_shift($remaining);
        }

        if (array_shift($remaining) !== 'DATA' || array_shift($remaining) !== 'BODY') {
            return false;
        }
    }

    return true;
}

/**
 * Send three messages at once through the given transport and report what the sink saw.
 *
 * @return array{delivered: int, whole: bool}
 */
function proof_send_concurrently(ProofSink $sink, TransportInterface $transport): array
{
    $server = \Async\spawn(static fn () => $sink->serve());

    $requests = [];

    for ($i = 1; $i <= SENDERS; $i++) {
        $requests[$i] = static function () use ($transport, $i): bool {
            $message = (new Email())
                ->from('sender@example.invalid')
                ->to("recipient$i@example.invalid")
                ->subject("message $i")
                ->text('body');

            try {
                $transport->send($message);

                return true;
            } catch (\Throwable) {
                return false;
            }
        };
    }

    $delivered = count(array_filter(proof_run_concurrently($requests)));

    $sink->stop();
    \Async\await_all([$server]);

    foreach ($sink->readPerConnection as $id => $commands) {
        printf("         connection %d: %s\n", $id, implode(' ', $commands));
    }

    $whole = array_reduce(
        $sink->readPerConnection,
        static fn (bool $carry, array $commands) => $carry && proof_carried_whole_messages($commands),
        true,
    );

    return ['delivered' => $delivered, 'whole' => $whole];
}

function proof_transport_for(int $port): TransportInterface
{
    return (new EsmtpTransportFactory())->create(new Dsn('smtp', '127.0.0.1', null, null, $port));
}

$run = new ProofRun();

/* A mailer without the pool: one transport, one socket, three coroutines writing into it. */
$sharedSink = new ProofSink(REPLY_DELAY_MS);
$shared = proof_transport_for($sharedSink->port);
$sharedResult = proof_send_concurrently($sharedSink, $shared);

/* Read as controls, not as findings: they say the fixture reproduces what the pool is for.
 * A runtime on which the shared transport kept its messages whole would make the comparison
 * below meaningless, and the run says so rather than reporting the pool as proven. */
$run->control('shared transport: the sink was reached at all', $sharedSink->readPerConnection !== [], true);
$run->control('shared transport: sends are lost', $sharedResult['delivered'] < SENDERS, true);
$run->control('shared transport: a connection carried interleaved commands', $sharedResult['whole'], false);

/* The same three sends through a pool of two connections. */
$pooledSink = new ProofSink(REPLY_DELAY_MS);
$pooled = new PooledTransport(
    static fn () => proof_transport_for($pooledSink->port),
    'smtp://127.0.0.1:' . $pooledSink->port,
    POOL_SIZE,
);
$pooledResult = proof_send_concurrently($pooledSink, $pooled);

$run->control('pooled: connections opened', count($pooledSink->readPerConnection) <= POOL_SIZE, true);
$run->isolation('pooled: messages delivered of ' . SENDERS, $pooledResult['delivered'], SENDERS);
$run->isolation('pooled: every connection carried whole messages', $pooledResult['whole'], true);

$pooled->close();

exit($run->exitCode());
