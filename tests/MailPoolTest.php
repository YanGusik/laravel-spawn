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
     * A connection is dropped rather than closed politely: QUIT would wait for a reply until
     * default_socket_timeout (60 seconds unless the mailer config sets one), and the relay
     * that just failed the send is exactly the one that may never answer.
     */
    public function test_dropping_a_failed_connection_does_not_wait_for_the_relay(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server, "listen failed: $errstr");

        $address = stream_socket_get_name($server, false);
        $port = (int) substr($address, strrpos($address, ':') + 1);

        $read = [];
        $sink = function () use ($server, &$read): void {
            $client = stream_socket_accept($server, 5);
            fwrite($client, "220 sink\r\n");

            while (($line = fgets($client)) !== false) {
                $verb = strtoupper(strtok(rtrim($line, "\r\n"), ' :'));
                $read[] = $verb;

                match ($verb) {
                    'EHLO' => fwrite($client, "250 sink\r\n"),
                    'MAIL' => fwrite($client, "421 sink closing\r\n"),
                    'RSET' => fwrite($client, "250 Ok\r\n"),
                    // Everything else, QUIT included, goes unanswered.
                    default => null,
                };
            }

            fclose($client);
            fclose($server);
        };

        $elapsed = 0.0;
        $send = function () use ($port, &$elapsed): void {
            $transport = new PooledTransport(fn () => new EsmtpTransport('127.0.0.1', $port, false), 'smtp://sink', 1);
            $started = microtime(true);

            try {
                $transport->send((new Email())->from('a@example.com')->to('b@example.com')->text('body'));
            } catch (TransportExceptionInterface) {
            }

            $elapsed = microtime(true) - $started;
            $transport->close();
        };

        $this->runParallel([$sink, $send]);

        $this->assertContains('MAIL', $read, 'the sink never saw the message the send was supposed to make');
        $this->assertNotContains('QUIT', $read);
        $this->assertLessThan(2.0, $elapsed);
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
