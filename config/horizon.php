<?php

/**
 * Laravel Horizon Configuration
 *
 * Queue priority design for 20,000 concurrent students:
 *
 *   results   (highest) — CalculateTestResults jobs. Students are waiting
 *                          for their score; these must run immediately.
 *   default              — Misc jobs (QR codes, link generation).
 *   analytics            — EvaluateTestAttempt listener. Can lag behind
 *                          results queue; no student is blocked on this.
 *   emails               — Notifications. Non-urgent.
 *   reports              — PDF generation. Lowest priority; can queue up.
 *
 * Worker sizing guide (single server):
 *   Under exam load: results×5, analytics×3, default×2, emails×1, reports×1
 *   Off-peak:        auto-scaled down by minProcesses
 */

use Laravel\Horizon\Horizon;

Horizon::routeSmsNotificationsTo(env('HORIZON_SMS', ''));
Horizon::routeSlackNotificationsTo(env('HORIZON_SLACK_WEBHOOK', ''));

Horizon::night(function () {
    // Low-priority windows (e.g. between 11 PM – 6 AM)
});

return [

    'domain' => env('HORIZON_DOMAIN'),
    'path'   => env('HORIZON_PATH', 'horizon'),

    // ── Access control ────────────────────────────────────────────────────
    // Restrict to super-admin users only. Uses the Horizon gate defined via
    //   Horizon::auth(fn($req) => ...) in HorizonServiceProvider (optional).
    'middleware' => ['web'],

    // ── Waits: alert if a queue exceeds this threshold (seconds) ─────────
    'waits' => [
        'redis:results'       => 3,    // students are waiting — alert fast
        'redis:default'       => 60,
        'redis:analytics'     => 300,
        'redis:notifications' => 300,  // result-ready + auto-submit alerts
        'redis:monitoring'    => 30,   // monitoring state should apply within 30 s
        'redis:emails'        => 300,
        'redis:reports'       => 600,
    ],

    // ── Memory limit per worker (MB) ─────────────────────────────────────
    'memory_limit' => 128,

    // ── Trim settings (keep dashboard data for N minutes) ─────────────────
    'trim' => [
        'recent'        => 60,
        'pending'       => 60,
        'completed'     => 120,
        'recent_failed' => 10080,  // 7 days
        'failed'        => 10080,
        'monitored'     => 10080,
    ],

    // ── Silenced jobs (excluded from dashboard) ───────────────────────────
    'silenced' => [],

    // ── Metrics: snapshots every N minutes ───────────────────────────────
    'metrics' => [
        'trim_snapshots' => [
            'job'   => 24,
            'queue' => 24,
        ],
    ],

    // ── Fast-terminate: graceful shutdown within N seconds ─────────────────
    'fast_termination' => false,

    // ── Queue connection to monitor ───────────────────────────────────────
    'use' => 'default',

    // ── Worker / supervisor definitions ──────────────────────────────────
    'environments' => [

        'production' => [
            // Processes exam-scoring jobs first — students are waiting.
            // 50 workers clear a 20,000-student scoring queue in ~23 minutes.
            // Each worker consumes ~128 MB RAM; 50 workers = 6.4 GB total.
            'results-supervisor' => [
                'connection'    => 'redis',
                'queue'         => ['results'],
                'balance'       => 'auto',
                'autoScalingStrategy' => 'time',
                'minProcesses'  => 5,
                'maxProcesses'  => 50,
                'balanceMaxShift'   => 5,
                'balanceCooldown'   => 3,
                'tries'         => 3,
                'timeout'       => 300,
                'maxTime'       => 3600,
                'memory'        => 128,
                'nice'          => -5,   // higher OS scheduling priority
            ],

            // Analytics: student/batch stats after scoring.
            'analytics-supervisor' => [
                'connection'   => 'redis',
                'queue'        => ['analytics'],
                'balance'      => 'auto',
                'minProcesses' => 2,
                'maxProcesses' => 10,
                'tries'        => 3,
                'timeout'      => 120,
                'memory'       => 128,
            ],

            // Notifications: result-ready alerts, auto-submit notices.
            'notifications-supervisor' => [
                'connection'   => 'redis',
                'queue'        => ['notifications'],
                'balance'      => 'auto',
                'minProcesses' => 1,
                'maxProcesses' => 6,
                'tries'        => 3,
                'timeout'      => 60,
                'memory'       => 128,
            ],

            // Monitoring: lightweight Redis writes from exam lifecycle events.
            // Low worker count — each job is a single HMSET/SADD (< 5 ms).
            'monitoring-supervisor' => [
                'connection'   => 'redis',
                'queue'        => ['monitoring'],
                'balance'      => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => 2,
                'tries'        => 2,
                'timeout'      => 30,
                'memory'       => 64,
            ],

            // General: QR codes, imports, misc.
            'default-supervisor' => [
                'connection'   => 'redis',
                'queue'        => ['default', 'emails', 'reports'],
                'balance'      => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => 4,
                'tries'        => 3,
                'timeout'      => 600,
                'memory'       => 256,
            ],
        ],

        'local' => [
            'local-supervisor' => [
                'connection'   => 'redis',
                'queue'        => ['results', 'analytics', 'notifications', 'monitoring', 'default', 'emails', 'reports'],
                'balance'      => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => 2,
                'tries'        => 1,
                'timeout'      => 300,
                'memory'       => 256,
            ],
        ],
    ],
];
