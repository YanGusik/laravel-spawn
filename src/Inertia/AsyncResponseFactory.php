<?php

namespace Spawn\Laravel\Inertia;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Inertia\ProvidesInertiaProperties;
use Inertia\Response;
use Inertia\ResponseFactory;
use Spawn\Laravel\Foundation\RequestContext;

/**
 * Coroutine-safe Inertia ResponseFactory.
 *
 * Before bootCompleted(): behaves like stock ResponseFactory.
 * After bootCompleted(): per-request state is stored in RequestContext.
 *
 * Isolated state: sharedProps, rootView, version, encryptHistory, urlResolver.
 */
class AsyncResponseFactory extends ResponseFactory
{
    private const CTX_KEY = 'inertia.state';

    /** The version a render in progress resolved before it built its Response. */
    private const CTX_RENDERING_VERSION = 'inertia.rendering_version';

    private bool $async = false;

    /**
     * What boot configured, captured when async mode starts: each request starts from it.
     * The properties themselves cannot serve, because render() swaps them.
     *
     * @var array{sharedProps: array, rootView: string, version: mixed, encryptHistory: ?bool, urlResolver: ?Closure}
     */
    private array $bootState;

    public function bootCompleted(): void
    {
        $this->bootState = [
            'sharedProps' => $this->sharedProps,
            'rootView' => $this->rootView,
            'version' => $this->version,
            'encryptHistory' => $this->encryptHistory,
            'urlResolver' => $this->urlResolver,
        ];
        $this->async = true;
    }

    private function getState(): array
    {
        $ctx = RequestContext::current();
        $state = $ctx->find(self::CTX_KEY);

        if ($state === null) {
            $state = $this->bootState;
            $ctx->set(self::CTX_KEY, $state);
        }

        return $state;
    }

    private function setState(string $key, mixed $value): void
    {
        $state = $this->getState();
        $state[$key] = $value;
        RequestContext::current()->set(self::CTX_KEY, $state, replace: true);
    }

    public function share($key, $value = null): void
    {
        if (! $this->async) {
            parent::share($key, $value);
            return;
        }

        $state = $this->getState();
        $shared = $state['sharedProps'];

        if (is_array($key)) {
            $shared = array_merge($shared, $key);
        } elseif ($key instanceof Arrayable) {
            $shared = array_merge($shared, $key->toArray());
        } elseif ($key instanceof ProvidesInertiaProperties) {
            $shared = array_merge($shared, [$key]);
        } else {
            Arr::set($shared, $key, $value);
        }

        $this->setState('sharedProps', $shared);
    }

    public function getShared(?string $key = null, $default = null)
    {
        if (! $this->async) {
            return parent::getShared($key, $default);
        }

        $shared = $this->getState()['sharedProps'];

        if ($key) {
            return Arr::get($shared, $key, $default);
        }

        return $shared;
    }

    public function flushShared()
    {
        if (! $this->async) {
            parent::flushShared();
            return;
        }

        $this->setState('sharedProps', []);
    }

    public function setRootView(string $name): void
    {
        if (! $this->async) {
            parent::setRootView($name);
            return;
        }

        $this->setState('rootView', $name);
    }

    public function getRootView(): string
    {
        if (! $this->async) {
            return $this->rootView;
        }

        return $this->getState()['rootView'];
    }

    public function version($version): void
    {
        if (! $this->async) {
            parent::version($version);
            return;
        }

        $this->setState('version', $version);
    }

    public function getVersion(): string
    {
        if (! $this->async) {
            return parent::getVersion();
        }

        $rendering = RequestContext::current()->find(self::CTX_RENDERING_VERSION);

        if ($rendering !== null) {
            return $rendering;
        }

        $version = $this->getState()['version'];

        if ($version instanceof Closure) {
            $version = app()->call($version);
        }

        return (string) ($version ?? '');
    }

    public function encryptHistory($encrypt = true): void
    {
        if (! $this->async) {
            parent::encryptHistory($encrypt);
            return;
        }

        $this->setState('encryptHistory', $encrypt);
    }

    public function resolveUrlUsing(?Closure $urlResolver = null): void
    {
        if (! $this->async) {
            parent::resolveUrlUsing($urlResolver);
            return;
        }

        $this->setState('urlResolver', $urlResolver);
    }

    public function render($component, $props = []): Response
    {
        if (! $this->async) {
            return parent::render($component, $props);
        }

        // Temporarily inject our per-coroutine state into the parent properties
        // so that parent::render() picks them up correctly across Inertia versions.
        //
        // The swap holds only while nothing suspends: a sibling render would swap the same
        // properties under this one. The version is the part that does — the stock middleware
        // hashes the manifest file, and file I/O suspends — so it is resolved first, and
        // parent::render() gets it back from getVersion() without running the closure again.
        $ctx = RequestContext::current();
        $ctx->set(self::CTX_RENDERING_VERSION, $this->getVersion(), replace: true);

        $origShared = $this->sharedProps;
        $origRootView = $this->rootView;
        $origVersion = $this->version;
        $origEncrypt = $this->encryptHistory;
        $origUrl = $this->urlResolver;

        $state = $this->getState();
        $this->sharedProps = $state['sharedProps'];
        $this->rootView = $state['rootView'];
        $this->version = $state['version'];
        $this->encryptHistory = $state['encryptHistory'];
        $this->urlResolver = $state['urlResolver'];

        try {
            return parent::render($component, $props);
        } finally {
            $ctx->unset(self::CTX_RENDERING_VERSION);
            $this->sharedProps = $origShared;
            $this->rootView = $origRootView;
            $this->version = $origVersion;
            $this->encryptHistory = $origEncrypt;
            $this->urlResolver = $origUrl;
        }
    }
}
