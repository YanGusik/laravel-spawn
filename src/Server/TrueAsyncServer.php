<?php

namespace Spawn\Laravel\Server;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Spawn\Laravel\Async\RawIo;
use Spawn\Laravel\Contracts\ServerInterface;
use Spawn\Laravel\Foundation\RequestContext;
use Spawn\Laravel\Foundation\ScopedService;
use Spawn\Laravel\Foundation\WorkerBootstrap;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TrueAsync\HttpServer;
use TrueAsync\HttpServerRuntimeException;
use TrueAsync\HttpServerConfig;
use TrueAsync\HttpRequest;
use TrueAsync\HttpResponse;
use TrueAsync\StaticHandler;
use TrueAsync\StaticOnMissing;
use Illuminate\Http\Request;
use function Async\spawn;

class TrueAsyncServer implements ServerInterface
{
    private const STREAM_CHUNK_BYTES = 65536;

    public function __construct(private $autoloadPath, private $bootstrapPath, private readonly array $options = [])
    {
    }

    public function prepareApp(): void
    {

    }

    public function start(): void
    {
        try {
            $config = $this->buildConfig();

            /* The bootloader is only consulted in pool mode (workers > 1).
             * With a single worker this process serves the requests itself,
             * so it needs the same initialization. */
            if ((int) ($this->options['workers'] ?? 0) <= 1) {
                self::initializeApp(app());
            }

            $server = new HttpServer($config);

            $this->registerStaticHandlers($server);
            $this->registerGrpcHandlers($server);
            $this->registerShutdownSignals($server);

            $telescopeEnabled = class_exists(\Laravel\Telescope\Telescope::class)
                && method_exists(\Laravel\Telescope\Telescope::class, 'isRecording');

            $server->addHttpHandler(function (HttpRequest $taRequest, HttpResponse $taResponse) use (
                $telescopeEnabled,
                $server
            ): void {
                $taRequest->awaitBody();

                $request = $this->convertRequest($taRequest);

                RawIo::set($taRequest, $taResponse);
                RequestContext::current()->set(ScopedService::REQUEST, $request);

                if ($telescopeEnabled) {
                    \Laravel\Telescope\Telescope::startRecording(false);
                }

                $app    = app();

                /* This closure is the only place holding both the worker's server object
                 * and the worker's container, so a controller reaching for ServerMetrics
                 * depends on this line. It runs per request rather than once because a
                 * `use (&$bound)` flag cannot be transferred into a worker thread. */
                $app->make(ServerMetrics::class)->useServer($server);

                \Spawn\Laravel\Debugbar\ResetDebugbar::handle($app, $request);

                $kernel = $app->make(Kernel::class);
                try {
                    $laravelResponse = $kernel->handle($request);
                    try {
                        $this->sendResponse($taResponse, $laravelResponse, $taRequest->getMethod() === 'HEAD');
                    } finally {
                        /* An aborted response still owes the session its write and the
                         * terminable middleware its run, and a failure of either must not
                         * take the place of the one that got us here. */
                        try {
                            $kernel->terminate($request, $laravelResponse);
                        } catch (\Throwable $terminateFailure) {
                            fwrite(STDERR, '[async] terminate: '.$terminateFailure->getMessage()."\n");
                        }
                    }
                } catch (\Throwable $e) {
                    fwrite(STDERR, "\n!!! FATAL SERVER ERROR !!!\n");
                    fwrite(STDERR, 'Message: '.$e->getMessage()."\n");
                    fwrite(STDERR, 'File: '.$e->getFile().':'.$e->getLine()."\n");
                    fwrite(STDERR, "Trace:\n".$e->getTraceAsString()."\n");


                    // A status line already on the wire has no replacement, and asking for one throws.
                    if (!$taResponse->isClosed() && !$taResponse->isHeadersSent())
                    {
                        $taResponse->setStatusCode(500);
                        $taResponse->setHeader('Content-Type', 'text/plain');
                        $taResponse->setBody($e->getMessage());
                        $taResponse->end();
                    }
                }
            });

            $server->start();
        } catch (\Throwable $e) {
            fwrite(STDERR, "\n!!! FATAL SERVER ERROR !!!\n");
            fwrite(STDERR, 'Message: '.$e->getMessage()."\n");
            fwrite(STDERR, 'File: '.$e->getFile().':'.$e->getLine()."\n");
            fwrite(STDERR, "Trace:\n".$e->getTraceAsString()."\n");

            sleep(2);
            exit(1);
        }
    }

    private function buildConfig(): HttpServerConfig
    {
        $config = new HttpServerConfig();

        $listeners = $this->options['listeners'] ?? [];

        if ($listeners === []) {
            throw new \InvalidArgumentException(
                'At least one listener must be configured in options["listeners"].'
            );
        }

        $hasTlsListeners = false;
        foreach ($listeners as $listener) {
            if (!empty($listener['tls'])) {
                $hasTlsListeners = true;
            }
        }

        $certPath = $this->options['tls_cert'] ?? $_SERVER['TLS_CERT'] ?? '/certs/server.crt';
        $keyPath  = $this->options['tls_key'] ?? $_SERVER['TLS_KEY'] ?? '/certs/server.key';
        $hasCert  = is_readable($certPath) && is_readable($keyPath);

        if ($hasTlsListeners && !$hasCert) {
            fwrite(STDERR,
                "[true-async-server] TLS listeners configured but certificates not found (cert: {$certPath}, key: {$keyPath}). Skipping TLS listeners.\n");
        }

        if ($hasTlsListeners && $hasCert) {
            $config->setCertificate($certPath);
            $config->setPrivateKey($keyPath);
        }

        foreach ($listeners as $listener) {
            $host     = $listener['host'] ?? '0.0.0.0';
            $port     = (int) ($listener['port'] ?? 8080);
            $tls      = !empty($listener['tls']);
            $protocol = $listener['protocol'] ?? 'auto';

            // Skip TLS listeners when certificates are missing
            if ($tls && !$hasCert) {
                continue;
            }

            match ($protocol) {
                'auto' => $config->addListener($host, $port, $tls),
                'http1' => $config->addHttp1Listener($host, $port, $tls),
                'http2' => $config->addHttp2Listener($host, $port, $tls),
                'http3' => $config->addHttp3Listener($host, $port),
                default => throw new \InvalidArgumentException("Unknown listener protocol: {$protocol}"),
            };
        }


        $hotReloadPaths = config('async.server.hot_reload_paths', []);

        if (app()->isProduction()) {
            $config->enableReloadOnSignal();
        } elseif ($hotReloadPaths !== []) {
            $config->enableHotReload(
                array_map(base_path(...), $hotReloadPaths)
            );
        }

        $config->setBacklog((int) ($this->options['backlog'] ?? 2048));
        $config->setMaxBodySize((int) ($this->options['max_body_size'] ?? 32 * 1024 * 1024));
        $config->setReadTimeout((int) ($this->options['read_timeout'] ?? 60));
        $config->setWriteTimeout((int) ($this->options['write_timeout'] ?? 60));
        $config->setCompressionEnabled((bool) ($this->options['compression'] ?? true));
        /* With it off the extension allocates no counter slab and getStats() throws.
         * The per-request increments run either way. */
        $config->setStatsEnabled((bool) ($this->options['stats'] ?? true));
        /* The extension parses incoming traceparent / tracestate headers and stamps each
         * request with the timings ServerMetrics::latency() reports. */
        $config->setTelemetryEnabled((bool) ($this->options['telemetry'] ?? false));
        $config->setWorkers((int) ($this->options['workers']));
        $config->setLogSeverity(\TrueAsync\LogSeverity::INFO)
            ->setLogStream(STDERR);

        $autoloadPath  = $this->autoloadPath;
        $bootstrapPath = $this->bootstrapPath;

        $config->setBootloader(static function () use ($autoloadPath, $bootstrapPath) {
            $_ENV['APP_RUNNING_IN_CONSOLE'] = false;
            require $autoloadPath;
            $app = require $bootstrapPath;
            $app->make(\Illuminate\Contracts\Http\Kernel::class)->bootstrap();


            if (!class_exists(\TrueAsync\HttpServer::class)) {
                throw new \RuntimeException(
                    'TrueAsyncServer extension is not available. '.
                    'Make sure you are running under the TrueAsync server.'
                );
            }

            TrueAsyncServer::initializeApp($app);
        });

        return $config;
    }

    /**
     * Everything a worker needs before its first request.
     *
     * Runs once per worker thread, or once in this process when the server is not in
     * pool mode. Kept as a method of the server because applications and other packages
     * call it to initialise a thread of their own.
     */
    public static function initializeApp(Application $app): void
    {
        WorkerBootstrap::run($app);
    }

    /* A mount at the root exposes whatever the directory holds, so it is served
     * only where the configuration names it. The extension gained the prefix "/"
     * in 0.13.0 and made hide('*.php') cover every depth in 0.14.0; on anything
     * older such a mount throws at the constructor or serves admin/tools.php as
     * text, and refusing it with a line on stderr beats both. */
    private const ROOT_MOUNT_MINIMUM_SERVER = '0.14.0';

    private function rootMountIsServable(string $prefix): bool
    {
        if ($prefix !== '/') {
            return true;
        }

        $server = phpversion('true_async_server');

        if ($server !== false
            && version_compare($server, self::ROOT_MOUNT_MINIMUM_SERVER, '>=')) {
            return true;
        }

        fwrite(STDERR, sprintf(
            "async: skipping the static mount at \"/\" — it needs true_async_server %s, this build is %s\n",
            self::ROOT_MOUNT_MINIMUM_SERVER,
            $server === false ? 'absent' : $server
        ));

        return false;
    }

    private function registerStaticHandlers(HttpServer $server): void
    {
        foreach ($this->options['static_handlers'] ?? [] as $sh) {
            $prefix = $sh['prefix'] ?? '/static/';
            $root   = $sh['root'] ?? '/data/static';

            if (!is_dir($root) || !$this->rootMountIsServable($prefix)) {
                continue;
            }

            $handler = new StaticHandler($prefix, $root);

            if (!empty($sh['precompressed'])) {
                $handler->enablePrecompressed(...$sh['precompressed']);
            }

            if (!empty($sh['etag'])) {
                $handler->setEtagEnabled(true);
            }

            if (!empty($sh['hide'])) {
                $handler->hide(...$sh['hide']);
            }

            if (isset($sh['open_file_cache'])) {
                $cache      = $sh['open_file_cache'];
                $maxEntries = (int) ($cache[0] ?? 1024);
                $ttl        = (int) ($cache[1] ?? 60);
                $handler->setOpenFileCache($maxEntries, $ttl);
            }

            $onMissing = ($sh['on_missing'] ?? 'not_found') === 'next'
                ? StaticOnMissing::NEXT
                : StaticOnMissing::NOT_FOUND;

            $handler->setOnMissing($onMissing);

            $server->addStaticHandler($handler);
        }
    }

    private function convertRequest(HttpRequest $request): Request
    {
        $uri    = $request->getUri();
        $path   = $request->getPath();
        $method = $request->getMethod();
        $query  = $request->getQuery();

        $hostHeader = $request->getHeader('host') ?? '';
        $serverName = 'localhost';
        $serverPort = 80;
        // TODO: refactoring
        if ($hostHeader !== '') {
            $parts      = explode(':', $hostHeader);
            $serverName = $parts[0];
            $serverPort = isset($parts[1]) ? (int) $parts[1] : 80;
        }

        // TODO: refactoring and debug
        if (str_contains($uri, '://')) {
            $parsed     = parse_url($uri);
            $serverName = $parsed['host'] ?? $serverName;
            $serverPort = $parsed['port'] ?? $serverPort;
        }

        $server = [
            'REQUEST_METHOD'  => $method,
            'REQUEST_URI'     => $uri,
            'PATH_INFO'       => $path,
            'QUERY_STRING'    => http_build_query($query),
            'SERVER_PROTOCOL' => 'HTTP/'.$request->getHttpVersion(),
            'SERVER_NAME'     => $serverName,
            'SERVER_PORT'     => $serverPort,
            'DOCUMENT_URI'    => $path,
            'SCRIPT_NAME'     => '',
            'SCRIPT_FILENAME' => '',
            'REMOTE_ADDR'     => '127.0.0.1',
            'CONTENT_TYPE'    => $request->getContentType() ?? '',
            'CONTENT_LENGTH'  => $request->getContentLength() ?? '',
        ];

        if ($request->hasHeader('host')) {
            $server['HTTP_HOST'] = $request->getHeader('host');
        }

        foreach ($request->getHeaders() as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return new Request(
            $query,
            $request->getPost(),
            [],
            $this->parseCookies($request),
            UploadedFiles::convert($request->getFiles()),
            $server,
            $request->getBody()
        );
    }

    /**
     * SIGTERM/SIGINT have no default disposition for a PID-1 process without
     * its own handler (the common case: a bare `php artisan async:serve` as
     * the container entrypoint) — the kernel simply drops them, and
     * HttpServer::start() blocks forever. TrueAsync's own signal() lets us
     * catch them like any other coroutine event and stop the server
     * ourselves, exactly like DevServer's hand-rolled accept loop already does.
     */
    private function registerShutdownSignals(HttpServer $server): void
    {
        spawn(function () use ($server): void {
            \Async\await_any_or_fail([
                \Async\signal(\Async\Signal::SIGINT),
                \Async\signal(\Async\Signal::SIGTERM),
            ]);

            try {
                $server->stop();
            } catch (\Throwable $e) {
                // Pool mode (config('async.server.workers') > 1) can't be
                // stopped from the parent yet — true-async/server#117.
                // Nothing more to do from here until that lands upstream.
                fwrite(STDERR, "[trueasync-server] graceful stop failed: {$e->getMessage()}\n");
            }
        });
    }

    private function registerGrpcHandlers(HttpServer $server): void
    {
        $handlers = $this->options['grpc_handlers'] ?? [];

        if ($handlers === []) {
            return;
        }

        // gRPC messages (protobuf) have no Symfony Request/Response equivalent,
        // so unlike addHttpHandler this bypasses the Laravel Kernel entirely.
        // The handler still runs after the same bootloader (DB pool, isolation
        // adapters are already active), just without routing/middleware.
        $server->addGrpcHandler(function (HttpRequest $taRequest, HttpResponse $taResponse) use ($handlers): void {
            RawIo::set($taRequest, $taResponse);

            $path = $taRequest->getPath();

            if (!isset($handlers[$path])) {
                $taResponse->setTrailer('grpc-status', '12'); // UNIMPLEMENTED
                $taResponse->setTrailer('grpc-message', "no handler registered for {$path}");

                return;
            }

            try {
                [$class, $method] = $handlers[$path];
                app($class)->$method($taRequest, $taResponse);
            } catch (\Throwable $e) {
                fwrite(STDERR, "\n!!! GRPC HANDLER ERROR ({$path}) !!!\n{$e}\n");

                if (!$taResponse->isClosed()) {
                    $taResponse->setTrailer('grpc-status', '13'); // INTERNAL
                    $taResponse->setTrailer('grpc-message', $e->getMessage());
                }
            }
        });
    }

    /**
     * @param bool $isHead wire method, not Symfony's: middleware may rewrite the latter
     */
    private function sendResponse(HttpResponse $taResponse, SymfonyResponse $response, bool $isHead): void
    {
        // Already streamed and closed directly (Sse::end(), or any other code
        // that wrote to trueasync_response() itself) — nothing left to send.
        /* send() leaves the response open with its setters sealed, so closed alone is not the
         * whole test. sendFile() seals it while raising neither flag and there is nothing to
         * ask, which is why the first setter doubles as the probe. */
        if ($taResponse->isClosed() || $taResponse->isHeadersSent()) {
            return;
        }

        try {
            $taResponse->setStatusCode($response->getStatusCode());
        } catch (HttpServerRuntimeException) {
            return;
        }

        foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            /* Only the server counts the bytes it writes: compression re-encodes the body,
             * and a Laravel value outlives later edits to the content. HEAD has none to count,
             * so there the value is all the client gets (RFC 9110 §9.3.2). */
            if (!$isHead && strcasecmp($name, 'Content-Length') === 0) {
                continue;
            }

            $first = true;
            foreach ($values as $value) {
                if ($first) {
                    $taResponse->setHeader($name, $value);
                    $first = false;
                } else {
                    $taResponse->addHeader($name, $value);
                }
            }
        }

        foreach ($response->headers->getCookies() as $cookie) {
            $taResponse->addHeader('Set-Cookie', (string) $cookie);
        }

        $content = $response->getContent();

        // StreamedResponse and BinaryFileResponse hold no body to return, and answer false.
        if ($content === false) {
            $this->streamContent($taResponse, $response);

            return;
        }

        $taResponse->setBody($content);
        $taResponse->end();
    }

    /**
     * Forward a response that writes its own body to standard output.
     *
     * Chunked framing, with the peer's backpressure reaching this coroutine. What the body is
     * stays Symfony's: ranges, the deferred deletion of a download, the generator of a stream.
     *
     * Rethrows while the buffer still holds everything, so the caller can answer 500. Past the
     * first chunk a failure is only loggable: chunked cannot be disowned mid-flight. A peer
     * that hangs up counts as an ordinary end.
     */
    private function streamContent(HttpResponse $taResponse, SymfonyResponse $response): void
    {
        /* Deflate holds its output back until it has enough of it, and text compresses well
         * enough that a whole slow stream can fit inside that holdback: measured, 350 KB of
         * CSV produced over 1.5 s arrived as one 10 KB burst at the end. A file is read as
         * fast as the disk allows, so only the callback-driven stream pays for immediacy. */
        if ($response instanceof StreamedResponse) {
            $taResponse->setNoCompression();
        }

        $peerGone   = false;
        $discarding = false;

        /* A handler that returns no value counts as failed: PHP disables it and writes the
         * pending chunk to the worker's own output. It also runs on a discard, where dropping
         * the return value is not enough, so both the caller's ob_clean() and this method's
         * own unwind have to be recognised and answered with silence. */
        $sink = static function (string $chunk, int $phase) use ($taResponse, &$peerGone, &$discarding): string {
            if ($chunk === '' || $peerGone || $discarding || ($phase & PHP_OUTPUT_HANDLER_CLEAN) !== 0) {
                return '';
            }

            try {
                $taResponse->send($chunk);
            } catch (\Throwable) {
                $peerGone = true;
            }

            return '';
        };

        // Streaming code often opens with `while (ob_get_level()) ob_end_flush()`, this handler included.
        $outerLevel = ob_get_level();

        if (!ob_start($sink, self::STREAM_CHUNK_BYTES)) {
            throw new \RuntimeException('Output buffering is unavailable, the response body cannot be forwarded');
        }

        try {
            $response->sendContent();
        } catch (\Throwable $e) {
            $discarding = true;

            while (ob_get_level() > $outerLevel) {
                ob_end_clean();
            }

            throw $e;
        }

        while (ob_get_level() > $outerLevel) {
            ob_end_flush();
        }

        if ($peerGone) {
            return;
        }

        $taResponse->end();
    }

    private function parseCookies(HttpRequest $request): array
    {
        $header = $request->getHeader('cookie') ?? '';
        if ($header === '') {
            return [];
        }

        $cookies = [];
        foreach (explode('; ', $header) as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2) {
                $cookies[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $cookies;
    }
}