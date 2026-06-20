<?php

/**
 * Laravel Horizon — Production Configuration (ExamSphere)
 *
 * WORKER SIZING — derived from submit-storm calculations:
 *
 *   At each tier, N students submit in the last 5 minutes of a 3h exam.
 *   Acceptable result wait time: 30 minutes (P99 result-ready).
 *   CalculateTestResults job duration: ~5 seconds (eager-load scoring).
 *
 *   Throughput formula:
 *     required_throughput = N_students / wait_minutes
 *     workers_needed      = required_throughput × job_duration_s / 60
 *
 *   Tier     | Students | Jobs/min target | Job time | Workers (results) | RAM
 *   ---------|----------|-----------------|----------|-------------------|------
 *   1k users |   1,000  |     33 jobs/min |   5 s    |     3  (min: 2)   | 0.4 GB
 *   5k users |   5,000  |    167 jobs/min |   5 s    |    15  (min: 5)   | 1.9 GB
 *  10k users |  10,000  |    333 jobs/min |   5 s    |    28  (min: 8)   | 3.6 GB
 *  20k users |  20,000  |    667 jobs/min |   5 s    |    56  (min:10)   | 7.2 GB
 *
 *   RAM per worker: 128 MB (Horizon memory_limit)
 *
 * COPY this file to config/horizon.php for production deployment.
 * The supervisor maxProcesses values below are set for the 20k tier.
 * For smaller tiers, scale maxProcesses down using the table above.
 *
 * HORIZON ENV VARS (add to .env):
 *   HORIZON_RESULTS_MIN_PROCS=10
 *   HORIZON_RESULTS_MAX_PROCS=60
 *   HORIZON_ANALYTICS_MAX_PROCS=10
 *   HORIZON_NOTIFICATIONS_MAX_PROCS=6
 *   HORIZON_MONITORING_MAX_PROCS=3
 *   HORIZON_DEFAULT_MAX_PROCS=4
 */

use Laravel\Horizon\Horizon;

Horizon::routeSlackNotificationsTo(env('HORIZON_SLACK_WEBHOOK', ''));
Horizon::routeSmsNotificationsTo(env('HORIZON_SMS', ''));

return [

    'domain' => env('HORIZON_DOMAIN'),
    'path'   => env('HORIZON_PATH', 'horizon'),
    'middleware' => ['web'],

    // ── Alert thresholds ──────────────────────────────────────────────────
    // Time (seconds) a job waits in queue before Horizon fires an alert.
    //
    //   results: 3s   — students are watching. Alert before any student
    //                   notices (typical P50 queue wait at 20k = <1s with
    //                   60 workers; 3s catches a worker crash quickly).
    //   monitoring: 30s — admin dashboards update every 3s; 30s means 10
    //                   missed updates before the alert fires.
    'waits' => [
        'redis:results'       => 3,
        'redis:default'       => 60,
        'redis:analytics'     => 300,
        'redis:notifications' => 120,
        'redis:monitoring'    => 30,
        'redis:emails'        => 300,
        'redis:reports'       => 600,
    ],

    'memory_limit' => 128,

    'trim' => [
        'recent'        => 60,
        'pending'       => 60,
        'completed'     => 120,
        'recent_failed' => 10080,  // 7 days
        'failed'        => 10080,
        'monitored'     => 10080,
    ],

    'silenced' => [],

    'metrics' => [
        'trim_snapshots' => [
            'job'   => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,
    'use' => 'default',

    'environments' => [

        'production' => [

            // ── results-supervisor ────────────────────────────────────────
            // Highest priority. Processes CalculateTestResults jobs.
            // Students poll for results every 5s; any queue depth > 500
            // means students wait > (500 / workers × 5s) seconds.
            //
            // Sizing at 20k:
            //   60 workers × (60s / 5s per job) = 720 jobs/min throughput
            //   Submit storm: 20,000 jobs / 720 jobs/min = 27.8 min drain
            //   Within our 30-minute SLA. ✓
            //
            // Scale for your tier (see table at top of file):
            //   1k: min=2, max=5
            //   5k: min=5, max=15
            //  10k: min=8, max=28
            //  20k: min=10, max=60  ← active below
            'results-supervisor' => [
                'connection'          => 'redis',
                'queue'               => ['results'],
                'balance'             => 'auto',
                'autoScalingStrategy' => 'time',    // scale on queue wait time, not depth
                'minProcesses'        => (int) env('HORIZON_RESULTS_MIN_PROCS', 10),
                'maxProcesses'        => (int) env('HORIZON_RESULTS_MAX_PROCS', 60),
                'balanceMaxShift'     => 10,         // scale up/down by max 10 at a time
                'balanceCooldown'     => 3,           // seconds between rebalance checks
                'tries'               => 5,
                'timeout'             => 300,         // 5 min: allows large exams to score
                'maxTime'             => 3600,        // restart worker after 1h
                'memory'              => 128,
                'nice'                => -10,         // highest OS scheduling priority
            ],

            // ── analytics-supervisor ─────────────────────────────────────
            // Handles: LogExamActivity, UpdateTestDenormalizedCounts.
            // Not student-blocking. Can lag behind results queue.
            // At 20k: burst of ~20k events at exam start + submit.
            // With 10 workers × 12 jobs/min = 120 jobs/min → clears 20k in 2.8h.
            // OK for analytics (no SLA). Raise to 15 if you need faster counts.
            'analytics-supervisor' => [
                'connection'   => 'redis',
                'queue'        => ['analytics'],
                'balance'      => 'auto',
                'minProcesses' => 2,
                'maxProcesses' => (int) env('HORIZON_ANALYTICS_MAX_PROCS', 10),
                'tries'        => 3,
                'timeout'      => 120,
                'maxTime'      => 3600,
                'memory'       => 128,
            ],

            // ── notifications-supervisor ──────────────────────────────────
            // Handles: NotifyResultReady (email/push when score is ready),
            //          NotifyAutoSubmit (SMS when auto-submitted).
            // Latency target: < 5 minutes after result is ready.
            // At 20k: 20,000 notifications in a burst.
            // 6 workers × 12 jobs/min = 72 jobs/min → 4.6h drain.
            // Raise maxProcesses to 20 if email SLA is strict.
            'notifications-supervisor' => [
                'connection'   => 'redis',
                'queue'        => ['notifications'],
                'balance'      => 'auto',
                'minProcesses' => 1,
                'maxProcesses' => (int) env('HORIZON_NOTIFICATIONS_MAX_PROCS', 6),
                'tries'        => 3,
                'timeout'      => 60,
                'maxTime'      => 3600,
                'memory'       => 128,
            ],

            // ── monitoring-supervisor ─────────────────────────────────────
            // Handles: UpdateMonitoringState (async Redis writes for exam
            //          lifecycle events: ExamStarted, ExamSubmitted, etc.)
            //
            // NOT high-frequency: AnswerSaved and ExamHeartbeatReceived
            // have NO async listeners. UpdateHeartbeatStatus is synchronous.
            // This queue only sees ~60 jobs/min during exam (lifecycle events).
            //
            // 3 workers is sufficient. Keep maxProcesses low to prevent
            // monitoring workload from consuming results-supervisor capacity
            // during auto-scale (they share the same Horizon process pool).
            'monitoring-supervisor' => [
                'connection'   => 'redis',
                'queue'        => ['monitoring'],
                'balance'      => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => (int) env('HORIZON_MONITORING_MAX_PROCS', 3),
                'tries'        => 2,
                'timeout'      => 30,
                'maxTime'      => 3600,
                'memory'       => 64,    // lightweight Redis writes only
            ],

            // ── default-supervisor ────────────────────────────────────────
            // Handles: misc jobs (QR code generation, Excel imports, PDF reports).
            // Low priority. Can queue up during exam without impacting students.
            'default-supervisor' => [
                'connection'   => 'redis',
                'queue'        => ['default', 'emails', 'reports'],
                'balance'      => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => (int) env('HORIZON_DEFAULT_MAX_PROCS', 4),
                'tries'        => 3,
                'timeout'      => 600,
                'maxTime'      => 3600,
                'memory'       => 256,  // PDF generation can spike to 200+ MB
            ],
        ],

        'local' => [
            'local-supervisor' => [
                'connection'   => 'redis',
                'queue'        => ['results', 'analytics', 'notifications', 'monitoring', 'default', 'emails', 'reports'],
                'balance'      => 'simple',
                'minProcesses' => 1,
                'maxProcesses' => 4,
                'tries'        => 1,
                'timeout'      => 300,
                'memory'       => 256,
            ],
        ],
    ],
];
