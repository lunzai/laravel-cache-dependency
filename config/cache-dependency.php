<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cache Store
    |--------------------------------------------------------------------------
    |
    | The cache store to use for dependency tracking. Set to null to use
    | Laravel's default cache store.
    |
    */
    'store' => env('CACHE_DEPENDENCY_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | Prefix for all cache dependency internal keys (tag versions, etc.)
    |
    */
    'prefix' => env('CACHE_DEPENDENCY_PREFIX', 'cdep'),

    /*
    |--------------------------------------------------------------------------
    | Tag Version TTL
    |--------------------------------------------------------------------------
    |
    | How long to keep tag version counters (in seconds). Should be longer
    | than your longest cache TTL to prevent false cache hits.
    |
    */
    'tag_version_ttl' => env('CACHE_DEPENDENCY_TAG_TTL', 86400 * 30), // 30 days

    /*
    |--------------------------------------------------------------------------
    | Database Dependency Settings
    |--------------------------------------------------------------------------
    */
    'db' => [
        // Default database connection for DB dependencies (null = default)
        'connection' => env('CACHE_DEPENDENCY_DB_CONNECTION'),

        // Query timeout in seconds
        'timeout' => env('CACHE_DEPENDENCY_DB_TIMEOUT', 5),

        // Behavior when DB query fails:
        // - false: Return null (cache miss) - fail closed
        // - true: Return cached value (fail open)
        'fail_open' => env('CACHE_DEPENDENCY_FAIL_OPEN', false),
    ],
];
