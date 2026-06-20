<?php

return [

    // Use 'redis' in production, 'database' in local dev.
    'default' => env('QUEUE_CONNECTION', 'database'),

    'connections' => [
        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver'       => 'database',
            'connection'   => env('DB_QUEUE_CONNECTION'),
            'table'        => env('DB_QUEUE_TABLE', 'jobs'),
            'queue'        => env('DB_QUEUE', 'default'),
            'retry_after'  => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => false,
        ],

        // ── Redis queue (production) ───────────────────────────────────────
        // Uses the dedicated 'queue' Redis DB (DB 2) defined in database.php
        // so queue keys never collide with cache or session keys.
        //
        // after_commit: true — jobs are only pushed to Redis AFTER the
        // surrounding DB transaction commits. This prevents the race condition
        // where a job is dispatched but the triggering transaction rolls back.
        'redis' => [
            'driver'       => 'redis',
            'connection'   => 'queue',   // maps to database.redis.queue
            'queue'        => env('REDIS_QUEUE', '{default}'),
            'retry_after'  => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for'    => null,
            'after_commit' => true,
        ],
    ],

    'batching' => [
        'database' => env('DB_CONNECTION', 'mysql'),
        'table'    => 'job_batches',
    ],

    'failed' => [
        'driver'   => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'mysql'),
        'table'    => 'failed_jobs',
    ],
];
