<?php

namespace Spawn\Laravel\Translation;

use Illuminate\Translation\Translator;
use Spawn\Laravel\Foundation\RequestContext;

/**
 * Coroutine-safe Translator.
 *
 * Before bootCompleted(): behaves like stock Translator.
 * After bootCompleted(): $locale and $fallback are stored per request in RequestContext.
 *
 * The $loaded translations cache remains shared — translations are the same
 * for all requests, only the active locale differs per request.
 */
class AsyncTranslator extends Translator
{
    private const CTX_KEY = 'translator.locale';

    private const CTX_FALLBACK = 'translator.fallback';

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

    public function has($key, $locale = null, $fallback = true)
    {
        if ($this->async && $locale === null) {
            $locale = $this->getLocale();
        }

        return parent::has($key, $locale, $fallback);
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
