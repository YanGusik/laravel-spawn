<?php

namespace Spawn\Laravel\Tests;

use Illuminate\Config\Repository;
use Illuminate\Mail\MailManager;
use Spawn\Laravel\Foundation\AsyncApplication;
use Spawn\Laravel\Mail\MailPool;
use Spawn\Laravel\Mail\PooledTransport;
use Spawn\Laravel\Tests\Fixtures\RecordingTransport;
use Spawn\Laravel\Tests\Fixtures\SendLog;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

class MailPoolTest extends AsyncTestCase
{
    /**
     * Seconds a read from the SMTP sink waits before giving up. Every timing bound below is a
     * multiple of it: one erroneous wait for a QUIT reply costs exactly this much.
     */
    private const STREAM_TIMEOUT = 0.5;

    protected function tearDown(): void
    {
        // The pool holds its slots until it is closed, and a slot left to the destructor phase
        // would run its transport's destructor after the engine has torn down. A worker closes
        // the pool by dropping the mailer; a test process runs one application after another
        // and has to do it here.
        gc_collect_cycles();

        parent::tearDown();
    }

    public function test_one_connection_carries_one_message_at_a_time(): void
    {
        $log = new SendLog();
        $transport = new PooledTransport(fn () => new RecordingTransport($log), 'smtp://fake', 1);

        $this->runParallel(array_fill(0, 4, fn () => $transport->send(new RawMessage('body'))));

        $this->assertCount(1, $log->slots());
        $this->assertNoConnectionCarriedTwoMessages($log);
        $this->assertSame(4, $this->timesLogged('leave', $log));

        $transport->close();
    }

    public function test_a_pool_of_two_uses_two_connections(): void
    {
        $log = new SendLog();
        $transport = new PooledTransport(fn () => new RecordingTransport($log), 'smtp://fake', 2);

        $this->runParallel(array_fill(0, 4, fn () => $transport->send(new RawMessage('body'))));

        $this->assertCount(2, $log->slots());
        $this->assertNoConnectionCarriedTwoMessages($log);
        $this->assertSame(4, $this->timesLogged('leave', $log));

        $transport->close();
    }

    public function test_a_failed_send_costs_its_connection(): void
    {
        $log = new SendLog();
        $built = 0;

        $transport = new PooledTransport(function () use ($log, &$built) {
            $built++;

            return new RecordingTransport($log, fails: $built === 1);
        }, 'smtp://fake', 1);

        try {
            $transport->send(new RawMessage('body'));
            $this->fail('the first send was supposed to fail');
        } catch (TransportExceptionInterface) {
        }

        $transport->send(new RawMessage('body'));

        $this->assertSame(2, $built);
        $this->assertCount(2, $log->slots(), 'the failed connection was handed out again');

        $transport->close();
    }

    public function test_a_busy_pool_is_not_reported_as_a_transport_failure(): void
    {
        $log = new SendLog();
        $transport = new PooledTransport(
            fn () => new RecordingTransport($log, suspendMs: 200),
            'smtp://fake',
            1,
            50,
        );

        $failures = [];
        $send = function () use ($transport, &$failures): void {
            try {
                $transport->send(new RawMessage('body'));
            } catch (\Throwable $e) {
                $failures[] = $e;
            }
        };

        $this->runParallel([$send, $send]);

        $this->assertCount(1, $failures, 'one of the two sends was supposed to wait and give up');
        $this->assertInstanceOf(\Async\TimeoutException::class, $failures[0]);

        // Failover blacklists a transport that reports a TransportExceptionInterface for its
        // whole retry period, so a full pool must not be told as one: the relay is healthy.
        $this->assertNotInstanceOf(TransportExceptionInterface::class, $failures[0]);

        $transport->close();
    }

    public function test_the_manager_hands_smtp_mailers_a_pooled_transport(): void
    {
        $app = $this->appWithMail(['enabled' => true, 'max' => 3]);
        $manager = $app->make('mail.manager');

        MailPool::configure($app);

        $transport = $manager->createSymfonyTransport($this->smtpConfig());

        $this->assertInstanceOf(PooledTransport::class, $transport);
        $this->assertSame('smtp://127.0.0.1:2525', (string) $transport);
    }

    public function test_the_pool_can_be_switched_off(): void
    {
        $app = $this->appWithMail(['enabled' => false]);
        $manager = $app->make('mail.manager');

        MailPool::configure($app);

        $this->assertInstanceOf(EsmtpTransport::class, $manager->createSymfonyTransport($this->smtpConfig()));
    }

    public function test_an_application_transport_of_its_own_is_left_alone(): void
    {
        $app = $this->appWithMail(['enabled' => true]);
        $manager = $app->make('mail.manager');
        $manager->extend('smtp', fn (array $config) => new NullTransport());

        MailPool::configure($app);

        $this->assertInstanceOf(NullTransport::class, $manager->createSymfonyTransport($this->smtpConfig()));
    }

    /**
     * A connection whose send failed is dropped without QUIT and without a wait. The relay
     * that just failed the send is the one that may never answer QUIT, and the reply would be
     * read until the stream timeout. A 550 is the failure the transport leaves to the pool: it
     * answers with RSET and keeps the connection open, so closing it is the pool's alone.
     */
    public function test_a_failed_send_drops_its_connection_without_quit(): void
    {
        $read = [];
        $elapsed = $this->failedSendElapsed($read, [
            'EHLO' => "250 sink\r\n",
            'MAIL' => "550 no\r\n",
            'RSET' => "250 Ok\r\n",
            // QUIT goes unanswered.
        ]);

        $this->assertNotContains('QUIT', $read);
        $this->assertLessThan(0.5 * self::STREAM_TIMEOUT, $elapsed);
    }

    /**
     * A 421 is a failure the transport closes itself, QUIT and a read of its reply included,
     * before the pool sees the exception (symfony/mailer 8.1.7 and later; 8.1.5 does the same
     * after a timeout or a broken pipe). That read is bounded by the stream timeout, and the
     * pool adds no wait of its own. Whether QUIT went out depends on the symfony/mailer
     * release and is not asserted.
     */
    public function test_a_failed_send_is_bounded_by_the_transport_timeout(): void
    {
        $read = [];
        $elapsed = $this->failedSendElapsed($read, [
            'EHLO' => "250 sink\r\n",
            'MAIL' => "421 sink closing\r\n",
            'RSET' => "250 Ok\r\n",
            // QUIT goes unanswered.
        ]);

        $this->assertLessThan(1.5 * self::STREAM_TIMEOUT, $elapsed);
    }

    /**
     * A relay that goes silent mid-message costs the send two stream timeouts, not one: the
     * read of the reply times out, and the transport then closes the connection with QUIT and
     * reads for that reply until it times out as well. The bound fails on a third wait.
     */
    public function test_a_relay_that_goes_silent_costs_two_transport_timeouts(): void
    {
        $read = [];
        $elapsed = $this->failedSendElapsed($read, [
            'EHLO' => "250 sink\r\n",
            'MAIL' => "250 sink\r\n",
            'RCPT' => "250 sink\r\n",
            'DATA' => "354 go\r\n",
            // The body's terminating dot, and QUIT after it, go unanswered.
        ]);

        $this->assertLessThan(2.5 * self::STREAM_TIMEOUT, $elapsed);
    }

    /**
     * A connection the pool disposes of is dropped rather than closed politely: QUIT would wait
     * for a reply until the stream timeout, the same code runs in the destructor phase, where
     * suspending is not available, and a relay that has half-closed never answers.
     */
    public function test_closing_the_pool_does_not_wait_for_the_relay(): void
    {
        [$server, $port] = $this->listen();

        $read = [];
        $sink = $this->sink($server, $read, [
            'EHLO' => "250 sink\r\n",
            'MAIL' => "250 sink\r\n",
            'RCPT' => "250 sink\r\n",
            'DATA' => "354 go\r\n",
            '.' => "250 Ok queued\r\n",
            // QUIT goes unanswered.
        ]);

        $elapsed = INF;  // see failedSendElapsed()
        $send = function () use ($port, &$elapsed): void {
            $transport = new PooledTransport(fn () => $this->smtpTransport($port), 'smtp://sink', 1);
            $transport->send($this->email());

            $started = microtime(true);
            $transport->close();
            $elapsed = microtime(true) - $started;
        };

        $this->runParallel([$sink, $send]);

        $this->assertContains('.', $read, 'the sink never saw the end of the message');
        $this->assertNotContains('QUIT', $read);
        $this->assertLessThan(0.5 * self::STREAM_TIMEOUT, $elapsed);
    }

    /**
     * Send one message through a pool of one against a sink answering with $replies, and return
     * how long the send took, in seconds. The send is expected to fail; MAIL must have reached
     * the sink, and the verbs it read are left in $read.
     *
     * @param  array<string, string>  $replies  See sink().
     */
    private function failedSendElapsed(array &$read, array $replies): float
    {
        [$server, $port] = $this->listen();
        $sink = $this->sink($server, $read, $replies);

        // INF until the send returns: runParallel() cancels a coroutine still running after
        // 5 s, and a cancelled send must not read as one that took no time.
        $elapsed = INF;
        $send = function () use ($port, &$elapsed): void {
            $transport = new PooledTransport(fn () => $this->smtpTransport($port), 'smtp://sink', 1);
            $started = microtime(true);

            try {
                $transport->send($this->email());
            } catch (TransportExceptionInterface) {
            }

            $elapsed = microtime(true) - $started;
            $transport->close();
        };

        $this->runParallel([$sink, $send]);

        $this->assertContains('MAIL', $read, 'the sink never saw the message the send was supposed to make');

        return $elapsed;
    }

    /** @return array{0: resource, 1: int} A listening socket on a free port, and the port. */
    private function listen(): array
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server, "listen failed: $errstr");

        $address = stream_socket_get_name($server, false);

        return [$server, (int) substr($address, strrpos($address, ':') + 1)];
    }

    /**
     * A coroutine body serving one SMTP client until it closes, recording every command verb
     * it sent in $read. The body after DATA is read up to its terminating dot and recorded as
     * '.'. A verb absent from $replies goes unanswered. The listening socket is closed with
     * the client: one client per sink.
     *
     * @param  resource  $server  From listen().
     * @param  array<string, string>  $replies  Verb => the full reply line, CRLF included.
     */
    private function sink($server, array &$read, array $replies): \Closure
    {
        return function () use ($server, &$read, $replies): void {
            $client = stream_socket_accept($server, 5);
            fwrite($client, "220 sink\r\n");
            $inBody = false;

            while (($line = fgets($client)) !== false) {
                $line = rtrim($line, "\r\n");

                if ($inBody && $line !== '.') {
                    continue;
                }

                $inBody = false;
                $verb = $line === '.' ? '.' : strtoupper(strtok($line, ' :'));
                $read[] = $verb;

                if ($verb === 'DATA') {
                    $inBody = true;
                }

                if (isset($replies[$verb])) {
                    fwrite($client, $replies[$verb]);
                }
            }

            fclose($client);
            fclose($server);
        };
    }

    /** A plain SMTP transport to the sink whose reads give up after STREAM_TIMEOUT. */
    private function smtpTransport(int $port): EsmtpTransport
    {
        $transport = new EsmtpTransport('127.0.0.1', $port, false);
        $transport->getStream()->setTimeout(self::STREAM_TIMEOUT);

        return $transport;
    }

    private function email(): Email
    {
        return (new Email())->from('a@example.com')->to('b@example.com')->text('body');
    }

    /** An application with a mail manager and the given pool config, and nothing else. */
    private function appWithMail(array $poolConfig): AsyncApplication
    {
        $app = $this->createApp();

        $app->instance('config', new Repository(['async' => ['mail_pool' => $poolConfig]]));
        $app->singleton('mail.manager', fn ($app) => new MailManager($app));

        return $app;
    }

    /** @return array<string, mixed> the mailer config a `mail.mailers.smtp` entry gives the manager */
    private function smtpConfig(): array
    {
        return ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 2525];
    }

    private function timesLogged(string $event, SendLog $log): int
    {
        return count(array_filter($log->events, fn (string $line) => str_starts_with($line, $event . ' ')));
    }

    private function assertNoConnectionCarriedTwoMessages(SendLog $log): void
    {
        $inFlight = [];

        foreach ($log->events as $line) {
            [$event, $slot] = explode(' ', $line);

            if ($event !== 'enter') {
                unset($inFlight[$slot]);

                continue;
            }

            $this->assertArrayNotHasKey($slot, $inFlight, "connection $slot carried two messages at once");
            $inFlight[$slot] = true;
        }
    }
}
