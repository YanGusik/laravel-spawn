<?php

namespace Spawn\Laravel\Redis;

use Illuminate\Redis\Connectors\PhpRedisConnector;
use Illuminate\Support\Arr;
use Redis;

/**
 * Builds phpredis clients backed by the TrueAsync connection pool.
 *
 * The stock connector does `new Redis` followed by connect(), which pool mode
 * rejects: the object is a template there and the pool opens the connections
 * itself, so the address and credentials must come from the constructor.
 */
class AsyncPhpRedisConnector extends PhpRedisConnector
{
    public function __construct(private readonly array $pool)
    {
    }

    /**
     * Create the Redis client instance.
     *
     * @param  array  $config
     * @return \Redis
     */
    protected function createClient(array $config)
    {
        if (! $this->isPoolable()) {
            return parent::createClient($config);
        }

        $client = new Redis($this->constructorOptions($config));

        $this->applyTemplateOptions($client, $config);

        return $client;
    }

    /**
     * The pool needs both the async runtime and a phpredis build that supports it.
     */
    protected function isPoolable(): bool
    {
        return ! empty($this->pool['enabled'])
            && function_exists('Async\spawn')
            && method_exists(Redis::class, 'getPool');
    }

    /**
     * Options the pool replays for every connection it opens. Only what must be
     * in place at connect time belongs here.
     */
    protected function constructorOptions(array $config): array
    {
        $host = $this->formatHost($config);

        $options = [
            'host' => $host,
            // phpredis takes a host starting with `/` for a unix socket only with a port below 1,
            // and opens 6379 itself for a TCP host with port 0: what the stock connector's
            // missing port amounts to.
            'port' => (int) ($config['port'] ?? 0),
            'pool' => [
                'enabled' => true,
                'min'     => (int) ($this->pool['min'] ?? 0),
                'max'     => (int) ($this->pool['max'] ?? 10),
                // The multiplexed lanes read their sockets with plain recv(), past the stream's
                // TLS layer, so a TLS connection with lanes would read ciphertext.
                'mux'     => $this->usesTls($host, $config) ? 0 : (int) ($this->pool['mux'] ?? 0),
            ],
        ];

        if (isset($config['timeout'])) {
            $options['connectTimeout'] = (float) $config['timeout'];
        }

        if (isset($config['read_timeout'])) {
            $options['readTimeout'] = (float) $config['read_timeout'];
        }

        if (isset($config['retry_interval'])) {
            $options['retryInterval'] = (int) $config['retry_interval'];
        }

        if (isset($config['database'])) {
            $options['database'] = (int) $config['database'];
        }

        if (! empty($config['password'])) {
            $options['auth'] = isset($config['username']) && $config['username'] !== '' && is_string($config['password'])
                ? [$config['username'], $config['password']]
                : $config['password'];
        }

        // The constructor takes the TLS options flat, as RedisCluster does, where Laravel's
        // documented form wraps them in `stream` or `ssl`.
        if (! is_null($context = Arr::get($config, 'context'))) {
            $options['ssl'] = $this->normalizeClusterContext($context);
        }

        return $options;
    }

    /**
     * Whether the pool's connections speak TLS: a `tls://` or `ssl://` host, or any context,
     * which phpredis takes for TLS on a host with no scheme.
     */
    private function usesTls(string $host, array $config): bool
    {
        $scheme = strtolower((string) parse_url($host, PHP_URL_SCHEME));

        return in_array($scheme, ['tls', 'ssl'], true) || Arr::get($config, 'context') !== null;
    }

    /**
     * Mirrors the option block of the stock connector. These live on the template
     * socket and the pool propagates them to its connections.
     */
    protected function applyTemplateOptions(Redis $client, array $config): void
    {
        if (array_key_exists('max_retries', $config)) {
            $client->setOption(Redis::OPT_MAX_RETRIES, $config['max_retries']);
        }

        if (array_key_exists('backoff_algorithm', $config)) {
            $client->setOption(Redis::OPT_BACKOFF_ALGORITHM, $this->parseBackoffAlgorithm($config['backoff_algorithm']));
        }

        if (array_key_exists('backoff_base', $config)) {
            $client->setOption(Redis::OPT_BACKOFF_BASE, $config['backoff_base']);
        }

        if (array_key_exists('backoff_cap', $config)) {
            $client->setOption(Redis::OPT_BACKOFF_CAP, $config['backoff_cap']);
        }

        if (! empty($config['prefix'])) {
            $client->setOption(Redis::OPT_PREFIX, $config['prefix']);
        }

        if (! empty($config['read_timeout'])) {
            $client->setOption(Redis::OPT_READ_TIMEOUT, $config['read_timeout']);
        }

        if (! empty($config['scan'])) {
            $client->setOption(Redis::OPT_SCAN, $config['scan']);
        }

        if (array_key_exists('serializer', $config)) {
            $client->setOption(Redis::OPT_SERIALIZER, $config['serializer']);
        }

        if (array_key_exists('compression', $config)) {
            $client->setOption(Redis::OPT_COMPRESSION, $config['compression']);
        }

        if (array_key_exists('compression_level', $config)) {
            $client->setOption(Redis::OPT_COMPRESSION_LEVEL, $config['compression_level']);
        }

        if (! empty($config['tcp_keepalive'])) {
            $client->setOption(Redis::OPT_TCP_KEEPALIVE, $config['tcp_keepalive']);
        }

        if (defined('Redis::OPT_PACK_IGNORE_NUMBERS') && array_key_exists('pack_ignore_numbers', $config)) {
            $client->setOption(Redis::OPT_PACK_IGNORE_NUMBERS, $config['pack_ignore_numbers']);
        }

        // 'name' (CLIENT SETNAME) is deliberately skipped: it would name a single
        // pooled connection, not the pool.
    }
}
