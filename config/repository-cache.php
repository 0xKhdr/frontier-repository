<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Repository Cache Enabled
    |--------------------------------------------------------------------------
    |
    | This option controls whether repository caching is enabled globally.
    | When disabled, all cache operations will be bypassed.
    |
    */
    'enabled' => env('REPOSITORY_CACHE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Default Cache Driver
    |--------------------------------------------------------------------------
    |
    | This option controls which cache driver to use for repository caching.
    | Set to null to use the default cache driver.
    |
    */
    'driver' => env('REPOSITORY_CACHE_DRIVER', null),

    /*
    |--------------------------------------------------------------------------
    | Default TTL (Time To Live)
    |--------------------------------------------------------------------------
    |
    | The default number of seconds to cache repository queries.
    | Can be overridden per-repository.
    |
    */
    'ttl' => env('REPOSITORY_CACHE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | A global prefix for all repository cache keys.
    |
    */
    'prefix' => env('REPOSITORY_CACHE_PREFIX', 'repository'),
];
