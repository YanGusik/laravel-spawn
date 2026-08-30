<?php

namespace Spawn\Laravel\Log;

use Illuminate\Contracts\Log\ContextLogProcessor;
use Monolog\Logger as Monolog;
use Monolog\Handler\StreamHandler;
use Throwable;

/**
 * A `LogManager` that builds {@see AsyncLogger} channels.
 *
 * The channels stay memoised for the life of the worker, which is what makes a log file open
 * once rather than once per request; what moves per request is the context, and the logger is
 * where that decision lives. This class exists to put the right logger class in the four
 * places upstream names `Logger` directly, and changes nothing else.
 *
 * The four bodies are copies of Laravel's, which an upgrade can make stale in the ordinary
 * way: a channel built with the wrong class shows up as a request reading another request's
 * context, and `LogContextIsolationTest` is what notices.
 */
class AsyncLogManager extends \Illuminate\Log\LogManager
{
    /**
     * @param  string  $name
     * @param  array|null  $config
     * @return \Psr\Log\LoggerInterface
     */
    protected function get($name, ?array $config = null)
    {
        try {
            return $this->channels[$name] ?? with($this->resolve($name, $config), function ($logger) use ($name) {
                $loggerWithContext = $this->tap(
                    $name,
                    new AsyncLogger($logger, $this->app['events'])
                )->shareProcessContext($this->sharedContext);

                if (method_exists($loggerWithContext->getLogger(), 'pushProcessor')) {
                    $loggerWithContext->pushProcessor($this->app->make(ContextLogProcessor::class));
                }

                return $this->channels[$name] = $loggerWithContext;
            });
        } catch (Throwable $e) {
            return tap($this->createEmergencyLogger(), function ($logger) use ($e) {
                $logger->emergency('Unable to create configured logger. Using emergency logger.', [
                    'exception' => $e,
                ]);
            });
        }
    }

    /**
     * @param  array  $channels
     * @param  string|null  $channel
     * @return \Illuminate\Log\Logger
     */
    public function stack(array $channels, $channel = null)
    {
        return (new AsyncLogger(
            $this->createStackDriver(['channels' => $channels, 'name' => $channel]),
            $this->app['events']
        ))->shareProcessContext($this->sharedContext);
    }

    /**
     * @return \Illuminate\Log\Logger
     */
    protected function createEmergencyLogger()
    {
        $config = $this->configurationFor('emergency');

        $handler = new StreamHandler(
            $config['path'] ?? $this->app->storagePath().'/logs/laravel.log',
            $this->level(['level' => 'debug'])
        );

        return new AsyncLogger(
            new Monolog('laravel', $this->prepareHandlers([$handler])),
            $this->app['events']
        );
    }

    /**
     * Share context across channels and stacks — for the worker, not for the request that
     * happened to call it. `Log::withContext()` is the per-request half.
     *
     * @param  array  $context
     * @return $this
     */
    public function shareContext(array $context)
    {
        foreach ($this->channels as $channel) {
            $channel->shareProcessContext($context);
        }

        $this->sharedContext = array_merge($this->sharedContext, $context);

        return $this;
    }
}
