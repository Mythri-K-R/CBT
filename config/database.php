<?php

use Illuminate\Support\Str;

return [

    'default' => env('DB_CONNECTION', 'mysql'),

    'connections' => [

        'sqlite' => [
            'driver'                  => 'sqlite',
            'url'                     => env('DB_URL'),
            'database'                => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix'                  => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
        ],

        // ── Primary MySQL connection ───────────────────────────────────────
        'mysql' => [
            'driver'      => 'mysql',
            'url'         => env('DB_URL'),
            'host'        => env('DB_HOST', '127.0.0.1'),
            'port'        => env('DB_PORT', '3306'),
            'database'    => env('DB_DATABASE', 'examsphere'),
            'username'    => env('DB_USERNAME', 'root'),
            'password'    => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset'     => env('DB_CHARSET', 'utf8mb4'),
            'collation'   => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix'      => '',
            'prefix_indexes' => true,
            'strict'      => true,
            'engine'      => null,
            'options'     => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
                // Connection timeout: fail fast rather than queue behind a dead DB
                PDO::ATTR_TIMEOUT      => 5,
            ]) : [],
        ],

        // ── MySQL read replica (optional, for horizontal scaling) ─────────
        // Set DB_READ_HOST in .env to activate. Laravel will automatically
        // route SELECT queries here and writes to 'mysql' above.
        //
        // 'mysql_read' => [
        //     'driver'   => 'mysql',
        //     'host'     => env('DB_READ_HOST', env('DB_HOST', '127.0.0.1')),
        //     'port'     => env('DB_PORT', '3306'),
        //     'database' => env('DB_DATABASE', 'examsphere'),
        //     'username' => env('DB_READ_USERNAME', env('DB_USERNAME', 'root')),
        //     'password' => env('DB_READ_PASSWORD', env('DB_PASSWORD', '')),
        //     'charset'  => 'utf8mb4',
        //     'collation'=> 'utf8mb4_unicode_ci',
        //     'prefix'   => '',
        //     'strict'   => true,
        // ],
    ],

    'migrations' => [
        'table'                => 'migrations',
        'update_date_on_publish' => true,
    ],

    // ── Redis ─────────────────────────────────────────────────────────────────
    //
    // P0-4: Production client is phpredis (C extension).
    //   phpredis maintains one persistent TCP connection per PHP-FPM worker.
    //   At 500 FPM workers this means 500 stable connections regardless of
    //   request rate. predis opens a new TCP connection on every request —
    //   at 20k concurrent students (~2,000 saves/s) predis creates thousands
    //   of ephemeral connections per second, pushing Redis to its accept limit.
    //
    // Install phpredis on production:
    //   apt install php8.3-redis
    //   phpenmod redis
    //   systemctl restart php8.3-fpm
    //
    // Local dev without ext-redis: set REDIS_CLIENT=predis in your local .env.
    //
    // WHY SEPARATE DATABASES:
    //   Redis uses numbered databases (0–15). Separating cache / queue /
    //   session prevents a cache FLUSHDB from wiping active exam sessions.

    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix'  => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'examsphere'), '_') . ':'),
        ],

        // DB 0 — general / locks / rate-limiting
        'default' => [
            'url'      => env('REDIS_URL'),
            'host'     => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port'     => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        // DB 1 — application cache (safe to flush independently)
        'cache' => [
            'url'      => env('REDIS_URL'),
            'host'     => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port'     => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

        // DB 2 — queue jobs
        'queue' => [
            'url'      => env('REDIS_URL'),
            'host'     => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port'     => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_QUEUE_DB', '2'),
        ],

        // DB 3 — user sessions (never flush unless deliberately clearing sessions)
        'session' => [
            'url'      => env('REDIS_URL'),
            'host'     => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port'     => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_SESSION_DB', '3'),
        ],
    ],
];
