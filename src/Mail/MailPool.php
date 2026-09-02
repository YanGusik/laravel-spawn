<?php

namespace Spawn\Laravel\Mail;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Mail\MailManager;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Wires the SMTP connection pool into Laravel's mail manager.
 *
 * `MailManager` memoises one mailer per name for the life of the worker and that mailer holds
 * one transport, so without a pool every coroutine sends through the same socket. Installed
 * once per worker, before any request is served.
 *
 * The pool is installed as a custom `smtp` creator rather than by replacing the manager:
 * `createSymfonyTransport()` consults custom creators before its own `create*Transport()`
 * methods, so this works on the stock manager, on an application's subclass of it (whose
 * own `createSmtpTransport()` override is what builds each connection) and on a manager the
 * container has already resolved.
 */
final class MailPool
{
    /**
     * Give the application's SMTP mailers a pooled transport.
     *
     * Does nothing when the pool is switched off, when the async runtime is absent, or when
     * the application registered an `smtp` creator of its own — that creator wins, and the
     * mailers it builds keep one shared connection.
     */
    public static function configure(Application $app): void
    {
        $config = $app->make('config')->get('async.mail_pool', []);

        if (empty($config['enabled'])) {
            return;
        }

        if (! function_exists('Async\spawn')) {
            return;
        }

        if (! $app->bound('mail.manager')) {
            return;
        }

        $manager = $app->make('mail.manager');

        if (! $manager instanceof MailManager || self::hasCustomSmtpCreator($manager)) {
            return;
        }

        $max = (int) ($config['max'] ?? 5);

        if ($max < 1) {
            // Async\Pool takes at least one slot and throws a ValueError otherwise, from the
            // constructor call that a first send makes inside some request.
            fwrite(STDERR, "[async] async.mail_pool.max is $max; it must be at least 1, and SMTP mailers stay unpooled\n");

            return;
        }

        $acquireTimeoutMs = (int) (($config['acquire_timeout'] ?? 0) * 1000);
        $build = self::smtpBuilder($manager);

        $manager->extend('smtp', static function (array $mailerConfig) use ($build, $max, $acquireTimeoutMs) {
            $connection = static fn (): TransportInterface => $build($mailerConfig);

            // The name comes from a connection rather than from the config, because the config
            // may leave the scheme and the port to the transport constructor: a host with no
            // port becomes `smtps://host` on a build with OpenSSL. Building one opens no socket,
            // and this one is dropped as soon as it has been read.
            return new PooledTransport($connection, (string) $connection(), $max, $acquireTimeoutMs);
        });

        // A mailer resolved during bootstrap (by a provider setting alwaysTo(), say) still
        // holds a transport of its own, and nothing rebuilds it later.
        $manager->forgetMailers();
    }

    /**
     * The manager's own transport builder, reachable as a closure.
     *
     * `createSmtpTransport()` is protected and applies the mailer options Laravel documents
     * (`source_ip`, `timeout`), so every connection in the pool is built exactly as an
     * unpooled one would be, including by a subclass that overrides it.
     *
     * @return \Closure(array): TransportInterface
     */
    private static function smtpBuilder(MailManager $manager): \Closure
    {
        return \Closure::bind(
            fn (array $config): TransportInterface => $this->createSmtpTransport($config),
            $manager,
            $manager::class,
        );
    }

    private static function hasCustomSmtpCreator(MailManager $manager): bool
    {
        return \Closure::bind(
            fn (): bool => isset($this->customCreators['smtp']),
            $manager,
            $manager::class,
        )();
    }
}
