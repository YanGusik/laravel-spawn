<?php

namespace Spawn\Laravel\Server;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Spawn\Laravel\Contracts\ServerInterface;
use Spawn\Laravel\Foundation\ScopedService;
use Spawn\Laravel\Server\Concerns\ManagesDatabasePool;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use TrueAsync\HttpServer;
use TrueAsync\HttpServerConfig;
use TrueAsync\HttpRequest;
use TrueAsync\HttpResponse;
use TrueAsync\StaticHandler;
use TrueAsync\StaticOnMissing;
use Illuminate\Http\Request;
use function Async\current_context;
use function Async\request_context;

class TrueAsyncServer implements ServerInterface
{
    use ManagesDatabasePool;

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
            $server = new HttpServer($config);

            $this->registerStaticHandlers($server);

            $telescopeEnabled = class_exists(\Laravel\Telescope\Telescope::class)
                && method_exists(\Laravel\Telescope\Telescope::class, 'isRecording');

            $server->addHttpHandler(function (HttpRequest $taRequest, HttpResponse $taResponse) use ($telescopeEnabled
            ): void {
                $taRequest->awaitBody();

                $request = $this->convertRequest($taRequest);

                var_dump(request_context() !== null);

                request_context()->set(ScopedService::REQUEST, $request);

                if ($telescopeEnabled) {
                    \Laravel\Telescope\Telescope::startRecording(false);
                }

                $app    = app();
                $kernel = $app->make(Kernel::class);
                try {
                    $laravelResponse = $kernel->handle($request);
                    $this->sendResponse($taResponse, $laravelResponse);

                    $kernel->terminate($request, $laravelResponse);
                } catch (\Throwable $e) {
                    fwrite(STDERR, "\n!!! FATAL SERVER ERROR !!!\n");
                    fwrite(STDERR, 'Message: '.$e->getMessage()."\n");
                    fwrite(STDERR, 'File: '.$e->getFile().':'.$e->getLine()."\n");
                    fwrite(STDERR, "Trace:\n".$e->getTraceAsString()."\n");


                    $taResponse->setStatusCode(500);
                    $taResponse->setHeader('Content-Type', 'text/plain');
                    $taResponse->setBody($e->getMessage());
                    $taResponse->end();
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

        $config->setBacklog((int) ($this->options['backlog'] ?? 2048));
        $config->setMaxBodySize((int) ($this->options['max_body_size'] ?? 32 * 1024 * 1024));
        $config->setReadTimeout((int) ($this->options['read_timeout'] ?? 60));
        $config->setWriteTimeout((int) ($this->options['write_timeout'] ?? 60));
        $config->setCompressionEnabled((bool) ($this->options['compression'] ?? true));
        $config->setWorkers((int) ($this->options['workers']));
        $config->setLogSeverity(\TrueAsync\LogSeverity::INFO)
            ->setLogStream(STDERR);

        $autoloadPath  = $this->autoloadPath;
        $bootstrapPath = $this->bootstrapPath;

        $config->setBootloader(static function () use ($autoloadPath, $bootstrapPath) {
            require $autoloadPath;
            $app = require $bootstrapPath;
            $app->make(\Illuminate\Contracts\Http\Kernel::class)->bootstrap();


            if (!class_exists(\TrueAsync\HttpServer::class)) {
                throw new \RuntimeException(
                    'TrueAsyncServer extension is not available. '.
                    'Make sure you are running under the TrueAsync server.'
                );
            }

            set_time_limit(0);

            if ($app instanceof \Spawn\Laravel\Foundation\AsyncApplication) {
                $app->enableAsyncMode();
            }

            $poolConfig = $app->make('config')->get('async.db_pool', []);

            if (empty($poolConfig['enabled'])) {
                return;
            }

            $connections = $app->make('config')->get('database.connections', []);

            foreach (array_keys($connections) as $name) {
                $app->make('config')->set(
                    "database.connections.{$name}.options",
                    array_replace(
                        $app->make('config')->get("database.connections.{$name}.options", []),
                        [
                            \PDO::ATTR_POOL_ENABLED              => true,
                            \PDO::ATTR_POOL_MIN                  => $poolConfig['min'] ?? 2,
                            \PDO::ATTR_POOL_MAX                  => $poolConfig['max'] ?? 10,
                            \PDO::ATTR_POOL_HEALTHCHECK_INTERVAL => $poolConfig['healthcheck_interval'] ?? 30,
                        ]
                    )
                );
            }

            // If any connections were already created during bootstrap (before pool
            // options were set), purge them so they get re-created with pool enabled.
            if ($app->bound('db')) {
                $app->make('db')->purge();
            }

            if (($view = $app->make('view')) instanceof \Spawn\Laravel\View\AsyncViewFactory) {
                $view->bootCompleted();
            }

            if ($app->bound(\Spatie\Permission\PermissionRegistrar::class)) {
                $registrar = $app->make(\Spatie\Permission\PermissionRegistrar::class);
                if ($registrar instanceof \Spawn\Laravel\Permission\AsyncPermissionRegistrar) {
                    $registrar->bootCompleted();
                }
            }

            if ($app->bound(\Inertia\ResponseFactory::class)) {
                $inertia = $app->make(\Inertia\ResponseFactory::class);
                if ($inertia instanceof \Spawn\Laravel\Inertia\AsyncResponseFactory) {
                    $inertia->bootCompleted();
                }
            }

            if (($translator = $app->make('translator')) instanceof \Spawn\Laravel\Translation\AsyncTranslator) {
                $translator->bootCompleted();
            }

            if (($config = $app->make('config')) instanceof \Spawn\Laravel\Config\AsyncConfig) {
                $config->bootCompleted();
            }

            if (($events = $app->make('events')) instanceof \Spawn\Laravel\Events\AsyncDispatcher) {
                $events->bootCompleted();
            }

            if (($router = $app->make('router')) instanceof \Spawn\Laravel\Routing\AsyncRouter) {
                $router->bootCompleted();
            }

            if (class_exists(\Laravel\Telescope\Telescope::class) && method_exists(\Laravel\Telescope\Telescope::class,
                    'enableAsyncRecording')) {
                \Laravel\Telescope\Telescope::enableAsyncRecording();
            }
        });

        return $config;
    }

    private function registerStaticHandlers(HttpServer $server): void
    {
        foreach ($this->options['static_handlers'] ?? [] as $sh) {
            $prefix = $sh['prefix'] ?? '/static/';
            $root   = $sh['root'] ?? '/data/static';

            if (!is_dir($root)) {
                continue;
            }

            $handler = new StaticHandler($prefix, $root);

            if (!empty($sh['precompressed'])) {
                $handler->enablePrecompressed(...$sh['precompressed']);
            }

            if (!empty($sh['etag'])) {
                $handler->setEtagEnabled(true);
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
            $request->getFiles(),
            $server,
            $request->getBody()
        );
    }

    private function sendResponse(HttpResponse $taResponse, SymfonyResponse $response): void
    {
        $taResponse->setStatusCode($response->getStatusCode());

        foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
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

        $taResponse->setBody($response->getContent() ?? '');
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