<?php return array (
  'providers' => 
  array (
    0 => 'Illuminate\\View\\ViewServiceProvider',
    1 => 'Illuminate\\Filesystem\\FilesystemServiceProvider',
    2 => 'Illuminate\\Cache\\CacheServiceProvider',
    3 => 'Illuminate\\Log\\LogServiceProvider',
    4 => 'Illuminate\\Translation\\TranslationServiceProvider',
    5 => 'Illuminate\\Routing\\RoutingServiceProvider',
    6 => 'Spawn\\Laravel\\AsyncServiceProvider',
  ),
  'eager' => 
  array (
    0 => 'Illuminate\\View\\ViewServiceProvider',
    1 => 'Illuminate\\Filesystem\\FilesystemServiceProvider',
    2 => 'Illuminate\\Log\\LogServiceProvider',
    3 => 'Illuminate\\Routing\\RoutingServiceProvider',
    4 => 'Spawn\\Laravel\\AsyncServiceProvider',
  ),
  'deferred' => 
  array (
    'cache' => 'Illuminate\\Cache\\CacheServiceProvider',
    'cache.store' => 'Illuminate\\Cache\\CacheServiceProvider',
    'cache.psr6' => 'Illuminate\\Cache\\CacheServiceProvider',
    'memcached.connector' => 'Illuminate\\Cache\\CacheServiceProvider',
    'Illuminate\\Cache\\RateLimiter' => 'Illuminate\\Cache\\CacheServiceProvider',
    'translator' => 'Illuminate\\Translation\\TranslationServiceProvider',
    'translation.loader' => 'Illuminate\\Translation\\TranslationServiceProvider',
  ),
  'when' => 
  array (
    'Illuminate\\Cache\\CacheServiceProvider' => 
    array (
    ),
    'Illuminate\\Translation\\TranslationServiceProvider' => 
    array (
    ),
  ),
);