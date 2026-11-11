<?php

namespace Spawn\Laravel\Config;

use Async\Context;
use Illuminate\Config\Repository;
use Illuminate\Support\Arr;
use Spawn\Laravel\Foundation\RequestContext;

use function Async\root_context;

/**
 * Coroutine-safe Config Repository.
 *
 * Before bootCompleted(): behaves like stock Repository (writes to $items).
 * After bootCompleted(): the first set() of a request copies the base $items into the
 * request's context and every write and read of that request goes to the copy, so a write
 * means exactly what it means to the stock Repository: setting a list replaces the list,
 * setting [] empties it. PHP copies an array on write, so the copy duplicates only the arrays
 * on the paths the request wrote. A request that never writes reads the base $items.
 *
 * Base $items are immutable after boot — shared read-only across all coroutines.
 */
class AsyncConfig extends Repository
{
    /**
     * One key for the whole class: an overlay left in a long-lived context answers for every
     * instance. The value is the request's copy of the items, or false where a context drops
     * the copy it would inherit.
     */
    private const CTX_KEY = 'config.overlay';

    private bool $async = false;

    private bool $diagnostics = false;

    public function bootCompleted(): void
    {
        // Read now: a coroutine writing this key would turn the report off for itself.
        $this->diagnostics = (bool) $this->get('async.diagnostics', false);
        $this->async       = true;
    }

    /**
     * Drop the overlay a context holds, so its next read falls through to the base
     * configuration.
     */
    public static function forgetOverlay(Context $ctx): void
    {
        $ctx->set(self::CTX_KEY, false, replace: true);
    }

    /**
     * The items the current request reads: its own copy once it has written, the base before.
     *
     * @return array<string, mixed>
     */
    private function currentItems(): array
    {
        $overlay = RequestContext::current()->find(self::CTX_KEY);

        return is_array($overlay) ? $overlay : $this->items;
    }

    public function set($key, $value = null)
    {
        if (! $this->async) {
            parent::set($key, $value);
            return;
        }

        $ctx = RequestContext::current();
        $overlay = $this->currentItems();

        $keys = is_array($key) ? $key : [$key => $value];

        // Root only: elsewhere a write without a request is the ordinary case.
        if ($this->diagnostics && $ctx === root_context()) {
            $this->reportRootContextWrite(array_keys($keys));
        }

        foreach ($keys as $k => $v) {
            Arr::set($overlay, $k, $v);
        }

        $ctx->set(self::CTX_KEY, $overlay, replace: true);
    }

    public function get($key, $default = null)
    {
        if (! $this->async) {
            return parent::get($key, $default);
        }

        if (is_array($key)) {
            return $this->getMany($key);
        }

        return Arr::get($this->currentItems(), $key, $default);
    }

    public function getMany($keys)
    {
        $config = [];

        foreach ($keys as $key => $default) {
            if (is_numeric($key)) {
                [$key, $default] = [$default, null];
            }

            $config[$key] = $this->get($key, $default);
        }

        return $config;
    }

    public function has($key)
    {
        if (! $this->async) {
            return parent::has($key);
        }

        return Arr::has($this->currentItems(), $key);
    }

    public function all()
    {
        if (! $this->async) {
            return parent::all();
        }

        return $this->currentItems();
    }

    /**
     * @param  string[]  $keys
     */
    private function reportRootContextWrite(array $keys): void
    {
        error_log(
            "[async] config write from the root context: '".implode("', '", $keys)
            ."'; requests run in their own scope and read the base configuration instead"
        );
    }
}
