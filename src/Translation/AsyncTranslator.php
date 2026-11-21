<?php

namespace Spawn\Laravel\Translation;

use Illuminate\Translation\Translator;
use Spawn\Laravel\Foundation\RequestContext;

use function Async\coroutine_context;

/**
 * Coroutine-safe Translator.
 *
 * Before bootCompleted(): behaves like stock Translator.
 * After bootCompleted(): $locale and $fallback are stored per request in RequestContext.
 *
 * The $loaded translations cache remains shared — translations are the same
 * for all requests, only the active locale differs per request.
 *
 * In async mode the handling of missing keys (`handleMissingKeysUsing()`) is switched off per
 * coroutine, not through `$handleMissingTranslationKeys`. Upstream writes that property in
 * `has()` for the length of the lookup, which loads a group and can suspend there, and around
 * the callback, which may log or report and suspend too; on the worker's one translator a
 * coroutine suspended in either switches the handling off for every other, two overlapping
 * `has()` calls leave it off for good, and so does a callback that throws. A coroutine spawned
 * inside the window does not inherit it: a callback that spawns a child translating the same
 * missing key has that child call it again.
 */
class AsyncTranslator extends Translator
{
    private const CTX_KEY = 'translator.locale';

    private const CTX_FALLBACK = 'translator.fallback';

    /** Set while this coroutine is inside has() or a missing-key callback. */
    private const CTX_MISSING_KEYS_OFF = 'translator.missing-keys.off';

    private bool $async = false;

    private string $bootLocale;

    private ?string $bootFallback;

    public function bootCompleted(): void
    {
        $this->bootLocale = $this->locale;
        $this->bootFallback = $this->fallback;
        $this->async = true;
    }

    public function setLocale($locale)
    {
        if (! $this->async) {
            parent::setLocale($locale);
            return;
        }

        // Laravel's own check, whatever the installed release refuses (path separators, and
        // `..` and NUL from 13.34 on: the locale names a directory of translation files). It
        // writes the shared property, which is put back before anything can suspend.
        $shared = $this->locale;

        try {
            parent::setLocale($locale);
        } finally {
            $this->locale = $shared;
        }

        RequestContext::current()->set(self::CTX_KEY, $locale, replace: true);
    }

    public function getFallback()
    {
        if (! $this->async) {
            return parent::getFallback();
        }

        return RequestContext::current()->find(self::CTX_FALLBACK) ?? $this->bootFallback;
    }

    public function setFallback($fallback)
    {
        if (! $this->async) {
            parent::setFallback($fallback);

            return;
        }

        RequestContext::current()->set(self::CTX_FALLBACK, $fallback, replace: true);
    }

    public function getLocale()
    {
        if (! $this->async) {
            return parent::getLocale();
        }

        return RequestContext::current()->find(self::CTX_KEY) ?? $this->bootLocale;
    }

    public function locale()
    {
        return $this->getLocale();
    }

    /**
     * Override to use getLocale() instead of $this->locale.
     */
    public function get($key, array $replace = [], $locale = null, $fallback = true)
    {
        if ($this->async && $locale === null) {
            $locale = $this->getLocale();
        }

        return parent::get($key, $replace, $locale, $fallback);
    }

    public function choice($key, $number, array $replace = [], $locale = null)
    {
        if ($this->async && $locale === null) {
            $locale = $this->getLocale();
        }

        return parent::choice($key, $number, $replace, $locale);
    }

    /**
     * Upstream's body, with missing-key handling switched off for this coroutine alone.
     */
    public function has($key, $locale = null, $fallback = true)
    {
        if (! $this->async) {
            return parent::has($key, $locale, $fallback);
        }

        $locale = $locale ?: $this->getLocale();
        $line = $this->withMissingKeysOff(fn () => $this->get($key, [], $locale, $fallback));

        // For JSON translations, the loaded files will contain the correct line.
        // Otherwise, we must assume we are handling typical translation file
        // and check if the returned line is not the same as the given key.
        if (! is_null($this->loaded['*']['*'][$locale][$key] ?? null)) {
            return true;
        }

        return $line !== $key;
    }

    /**
     * Upstream's handling, with the switch held per coroutine; the property is neither read
     * nor written, so a boot-time callback that threw leaves nothing behind either.
     */
    protected function handleMissingTranslationKey($key, $replace, $locale, $fallback)
    {
        if (! $this->async) {
            return parent::handleMissingTranslationKey($key, $replace, $locale, $fallback);
        }

        if (coroutine_context()->findLocal(self::CTX_MISSING_KEYS_OFF) === true
            || ! isset($this->missingTranslationKeyCallback)) {
            return $key;
        }

        // Switched off around the callback, as upstream does, so that a callback translating a
        // missing key does not call itself.
        return $this->withMissingKeysOff(fn () => call_user_func(
            $this->missingTranslationKeyCallback,
            $key, $replace, $locale, $fallback
        ) ?? $key);
    }

    private function withMissingKeysOff(\Closure $callback): mixed
    {
        $context = coroutine_context();
        $previous = $context->findLocal(self::CTX_MISSING_KEYS_OFF);

        $context->set(self::CTX_MISSING_KEYS_OFF, true, true);

        try {
            return $callback();
        } finally {
            $context->set(self::CTX_MISSING_KEYS_OFF, $previous, true);
        }
    }

    /**
     * Upstream's body, reading the request's locale and fallback instead of the properties.
     */
    protected function localeForChoice($key, $locale)
    {
        if (! $this->async) {
            return parent::localeForChoice($key, $locale);
        }

        $locale = $locale ?: $this->getLocale();

        return $this->hasForLocale($key, $locale) ? $locale : $this->getFallback();
    }

    /**
     * Upstream's body, reading the request's locale and fallback instead of the properties.
     */
    protected function localeArray($locale)
    {
        if (! $this->async) {
            return parent::localeArray($locale);
        }

        $locales = array_filter([$locale ?: $this->getLocale(), $this->getFallback()]);

        $determined = call_user_func($this->determineLocalesUsing ?: fn () => $locales, $locales);

        return array_values(array_unique($determined));
    }
}
