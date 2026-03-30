<?php

namespace Spawn\Laravel\Server;

use FrankenPHP\HttpServer as FrankenHttpServer;
use FrankenPHP\Request as FrankenRequest;
use FrankenPHP\Response as FrankenResponse;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Spawn\Laravel\Contracts\ServerInterface;
use Spawn\Laravel\Foundation\ScopedService;
use Spawn\Laravel\Server\Concerns\ManagesDatabasePool;

use function Async\current_context;

class FrankenPhpServer implements ServerInterface
{
    use ManagesDatabasePool;

    public function __construct(
        private readonly Application $app,
    ) {}

    public function prepareApp(): void
    {
        if ($this->app instanceof \Spawn\Laravel\Foundation\AsyncApplication) {
            $this->app->enableAsyncMode();
        }

        $this->configureDatabasePool();

        if (($view = $this->app->make('view')) instanceof \Spawn\Laravel\View\AsyncViewFactory) {
            $view->bootCompleted();
        }

        if ($this->app->bound(\Spatie\Permission\PermissionRegistrar::class)) {
            $registrar = $this->app->make(\Spatie\Permission\PermissionRegistrar::class);
            if ($registrar instanceof \Spawn\Laravel\Permission\AsyncPermissionRegistrar) {
                $registrar->bootCompleted();
            }
        }

        if ($this->app->bound(\Inertia\ResponseFactory::class)) {
            $inertia = $this->app->make(\Inertia\ResponseFactory::class);
            if ($inertia instanceof \Spawn\Laravel\Inertia\AsyncResponseFactory) {
                $inertia->bootCompleted();
            }
        }

        if (($translator = $this->app->make('translator')) instanceof \Spawn\Laravel\Translation\AsyncTranslator) {
            $translator->bootCompleted();
        }

        if (($config = $this->app->make('config')) instanceof \Spawn\Laravel\Config\AsyncConfig) {
            $config->bootCompleted();
        }
    }

    public function start(): void
    {
        if (!class_exists(FrankenHttpServer::class)) {
            throw new \RuntimeException(
                'FrankenPHP extension is not available. ' .
                'Make sure you are running under the TrueAsync FrankenPHP server.'
            );
        }

        // Worker mode: onRequest() blocks indefinitely — disable PHP execution time limit.
        set_time_limit(0);

        // NOTE: warmUpDatabasePool() is intentionally NOT called here.
        // The TrueAsync scheduler starts only when onRequest() is called.
        // Calling warmUpDatabasePool() before that would try to use the PDO Pool
        // without a running scheduler — causing a hang.
        // The pool initializes lazily on the first DB access inside a request coroutine.

        FrankenHttpServer::onRequest(function (FrankenRequest $frankenRequest, FrankenResponse $frankenResponse) {
            try {
                $request = $this->buildRequest($frankenRequest);

                current_context()->set(ScopedService::REQUEST, $request);

                $kernel = $this->app->make(Kernel::class);
                $laravelResponse = $kernel->handle($request);

                $this->sendResponse($frankenResponse, $laravelResponse);

                $kernel->terminate($request, $laravelResponse);
            } catch (\Throwable $e) {
                error_log('[async:franken] ' . $e::class . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());

                $frankenResponse->setStatus(500);
                $frankenResponse->setHeader('Content-Type', 'text/plain');
                $frankenResponse->write($e->getMessage());
                $frankenResponse->end();
            }
        });
    }

    /**
     * Build an Illuminate Request from the FrankenPHP request.
     *
     * TrueAsync FrankenPHP async mode does NOT populate $_SERVER superglobals.
     * We build the request manually from FrankenPHP\Request methods:
     *   getUri()     — original request URI (path + query string)
     *   getMethod()  — HTTP method
     *   getHeaders() — request headers in UPPERCASE keys
     *   getBody()    — raw request body
     */
    private function buildRequest(FrankenRequest $frankenRequest): Request
    {
        $uri     = $frankenRequest->getUri();
        $method  = strtoupper($frankenRequest->getMethod());
        $headers = $frankenRequest->getHeaders();
        $body    = $frankenRequest->getBody();

        $parsedUrl   = parse_url($uri);
        $path        = $parsedUrl['path'] ?? '/';
        $queryString = $parsedUrl['query'] ?? '';

        $queryParams = [];
        if ($queryString !== '') {
            parse_str($queryString, $queryParams);
        }

        // Parse SERVER_NAME and SERVER_PORT from the Host header.
        // FrankenPHP/Caddy strips the port from the Host header before passing to PHP,
        // so fall back to APP_URL when no port is present in the Host header.
        $hostHeader = $headers['HOST'] ?? 'localhost';
        if (str_contains($hostHeader, ':')) {
            [$serverName, $serverPort] = explode(':', $hostHeader, 2);
        } else {
            $serverName = $hostHeader;
            $appUrl     = $this->app->make('config')->get('app.url', '');
            $appPort    = parse_url($appUrl, PHP_URL_PORT);
            $serverPort = $appPort ? (string) $appPort : '80';
        }

        // Build a $_SERVER-equivalent array from available headers
        $server = [
            'REQUEST_METHOD'  => $method,
            'REQUEST_URI'     => $path . ($queryString !== '' ? '?' . $queryString : ''),
            'QUERY_STRING'    => $queryString,
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'SERVER_NAME'     => $serverName,
            'SERVER_PORT'     => $serverPort,
        ];

        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));

            match ($key) {
                'CONTENT_TYPE'   => $server['CONTENT_TYPE'] = $value,
                'CONTENT_LENGTH' => $server['CONTENT_LENGTH'] = $value,
                'HOST'           => $server['HTTP_HOST'] = $value,
                default          => $server['HTTP_' . $key] = $value,
            };
        }

        // Caddy strips the port from the Host header — restore it so Symfony's
        // Request::getPort() / getHttpHost() return the correct value.
        if ($serverPort !== '80') {
            $server['HTTP_HOST'] = $serverName . ':' . $serverPort;
        }

        // Parse cookies from the Cookie header
        $cookies = [];
        $cookieHeader = $headers['COOKIE'] ?? '';
        if ($cookieHeader !== '') {
            foreach (explode('; ', $cookieHeader) as $pair) {
                $parts = explode('=', $pair, 2);
                if (count($parts) === 2) {
                    $cookies[trim($parts[0])] = trim($parts[1]);
                }
            }
        }

        // Parse POST body for form submissions
        $postData    = [];
        $contentType = $headers['CONTENT_TYPE'] ?? '';
        if ($method === 'POST' && str_contains($contentType, 'application/x-www-form-urlencoded') && $body !== '') {
            parse_str($body, $postData);
        }

        return Request::create(
            uri: $server['REQUEST_URI'],
            method: $method,
            parameters: in_array($method, ['GET', 'HEAD']) ? $queryParams : $postData,
            cookies: $cookies,
            files: [],
            server: $server,
            content: $body !== '' ? $body : null,
        );
    }

    private function sendResponse(FrankenResponse $frankenResponse, SymfonyResponse $response): void
    {
        $frankenResponse->setStatus($response->getStatusCode());

        // Regular headers — setHeader replaces (correct for single-value headers).
        foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            $first = true;
            foreach ($values as $value) {
                if ($first) {
                    $frankenResponse->setHeader($name, $value);
                    $first = false;
                } else {
                    $frankenResponse->addHeader($name, $value);
                }
            }
        }

        // Cookies — addHeader to allow multiple Set-Cookie headers.
        foreach ($response->headers->getCookies() as $cookie) {
            $frankenResponse->addHeader('Set-Cookie', (string) $cookie);
        }

        $frankenResponse->write((string) $response->getContent());
        $frankenResponse->end();
    }
}
