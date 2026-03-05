<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Repository Cache Enabled
    |--------------------------------------------------------------------------
    |
    | Global toggle for repository caching. When disabled, all read operations
    | bypass the cache entirely and write operations skip cache invalidation.
    |
    */
    'enabled' => env('REPOSITORY_CACHE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Default Cache Driver
    |--------------------------------------------------------------------------
    |
    | The cache store to use for repository caching. Set to null to use the
    | application's default cache driver.
    |
    | For clearCache() (tag-based invalidation) to work, use a tag-aware driver:
    | Redis (cache.stores.redis) or Memcached (cache.stores.memcached).
    |
    | File-based and array drivers do not support tags — clearCache() will log a
    | warning and return false for these drivers.
    |
    */
    'driver' => env('REPOSITORY_CACHE_DRIVER', null),

    /*
    |--------------------------------------------------------------------------
    | Default TTL (Time To Live)
    |--------------------------------------------------------------------------
    |
    | The default number of seconds to cache repository read results.
    |
    | This can be overridden at multiple levels:
    |   - Per-repository constructor: new MyRepositoryCache(ttl: 300)
    |   - Per-call:                   $repo->cacheFor(30)->findById($id)
    |   - Per-model (table):          set 'model_ttl' below
    |
    */
    'ttl' => env('REPOSITORY_CACHE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | A global prefix prepended to all repository cache keys. The per-repository
    | prefix defaults to the model's table name (e.g. "users:findById:...").
    |
    */
    'prefix' => env('REPOSITORY_CACHE_PREFIX', 'repository'),

    /*
    |--------------------------------------------------------------------------
    | Cache Empty Results
    |--------------------------------------------------------------------------
    |
    | When true (default), null returns and empty collections are cached like
    | any other result. This prevents repeated database hits for non-existent
    | records ("negative caching").
    |
    | Set to false if empty results indicate a temporary or transitional state
    | (e.g., a background job has not completed yet) so the database is always
    | consulted when the result would be empty.
    |
    */
    'cache_empty_results' => env('REPOSITORY_CACHE_EMPTY_RESULTS', true),

    /*
    |--------------------------------------------------------------------------
    | Excluded Methods
    |--------------------------------------------------------------------------
    |
    | Method names listed here are never cached, even when caching is globally
    | enabled. Useful for highly volatile aggregates or methods where stale
    | results would be harmful.
    |
    | Example: ['count', 'exists']
    |
    */
    'excluded_methods' => [],

    /*
    |--------------------------------------------------------------------------
    | Per-Model TTL Overrides
    |--------------------------------------------------------------------------
    |
    | Override the default TTL for specific models, keyed by the model's table
    | name. Takes precedence over the global 'ttl' setting but is overridden by
    | per-repository constructor TTLs and per-call cacheFor() calls.
    |
    | Example:
    |   'model_ttl' => [
    |       'settings'      => 86400,   // 24 hours — rarely changes
    |       'notifications' => 60,      // 1 minute — high churn
    |       'users'         => 3600,    // 1 hour
    |   ],
    |
    */
    'model_ttl' => [],

];
