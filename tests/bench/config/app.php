<?php

return [
    'name' => 'Bench',
    'env' => 'production',
    'debug' => false,
    'url' => 'http://localhost:8888',
    'timezone' => 'UTC',
    'locale' => 'en',
    'fallback_locale' => 'en',
    'key' => 'base64:' . base64_encode(random_bytes(32)),
    'cipher' => 'AES-256-CBC',
    'providers' => [
        Illuminate\View\ViewServiceProvider::class,
        Illuminate\Filesystem\FilesystemServiceProvider::class,
        Illuminate\Cache\CacheServiceProvider::class,
        Illuminate\Log\LogServiceProvider::class,
        Illuminate\Translation\TranslationServiceProvider::class,
        Illuminate\Routing\RoutingServiceProvider::class,
        Spawn\Laravel\AsyncServiceProvider::class,
    ],
    'aliases' => [],
];
