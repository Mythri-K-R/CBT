<?php

use Illuminate\Support\Str;

return [

    // Use 'redis' in production, 'database' in local dev.
    // WHY redis: File/database sessions become a bottleneck at 20,000+
    // concurrent users. Redis sessions are sub-millisecond reads/writes and
    // horizontally scalable across multiple PHP servers.
    'driver' => env('SESSION_DRIVER', 'redis'),

    'lifetime'        => (int) env('SESSION_LIFETIME', 120),
    'expire_on_close' => env('SESSION_EXPIRE_ON_CLOSE', false),
    'encrypt'         => env('SESSION_ENCRYPT', false),

    'files'      => storage_path('framework/sessions'),
    'connection' => env('SESSION_CONNECTION', 'session'),  // database.redis.session → DB 3
    'table'      => env('SESSION_TABLE', 'sessions'),
    'store'      => env('SESSION_STORE'),
    'lottery'    => [2, 100],

    'cookie' => env(
        'SESSION_COOKIE',
        Str::slug(env('APP_NAME', 'examsphere'), '_') . '_session'
    ),

    'path'        => env('SESSION_PATH', '/'),
    'domain'      => env('SESSION_DOMAIN'),
    'secure'      => env('SESSION_SECURE_COOKIE', false),
    'http_only'   => env('SESSION_HTTP_ONLY', true),
    'same_site'   => env('SESSION_SAME_SITE', 'lax'),
    'partitioned' => env('SESSION_PARTITIONED_COOKIE', false),
];
