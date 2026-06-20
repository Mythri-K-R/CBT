<?php

use Illuminate\Support\Str;

return [

    // Use 'redis' in production, 'file' in local dev.
    'default' => env('CACHE_STORE', 'redis'),

    'stores' => [
        'array' => [
            'driver'    => 'array',
            'serialize' => false,
        ],

        'file' => [
            'driver'    => 'file',
            'path'      => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],

        // ── Redis cache store ─────────────────────────────────────────────
        // Uses the dedicated 'cache' Redis DB (DB 1). The 'lock_connection'
        // points to DB 0 (default) so cache tag locks use a separate key-space
        // and are never affected by a cache flush.
        'redis' => [
            'driver'          => 'redis',
            'connection'      => 'cache',    // database.redis.cache → DB 1
            'lock_connection' => 'default',  // database.redis.default → DB 0
        ],

        'database' => [
            'driver'          => 'database',
            'connection'      => env('DB_CACHE_CONNECTION'),
            'table'           => env('DB_CACHE_TABLE', 'cache'),
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
            'lock_table'      => env('DB_CACHE_LOCK_TABLE'),
        ],
    ],

    'prefix' => env('CACHE_PREFIX', Str::slug(env('APP_NAME', 'examsphere'), '_') . '_cache_'),
];
