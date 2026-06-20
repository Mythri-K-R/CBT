<?php

/**
 * Resilience & Failure Recovery Configuration — Feature 7
 *
 * Covers:
 *   - Circuit breaker thresholds (per dependency)
 *   - Automatic retry policies (per job category)
 *   - Dead Letter Queue (DLQ) classification
 *   - Recovery monitoring thresholds
 *
 * All values are safe defaults. Tune per environment via .env overrides where
 * env() wrappers are provided. For tight exam windows, lower failure_threshold
 * and reset_timeout so the circuit opens and closes faster.
 */

return [

    // ── Circuit Breakers ──────────────────────────────────────────────────────
    //
    // Each circuit has three states:
    //   CLOSED    — requests pass through normally
    //   OPEN      — requests fail fast without attempting the call
    //   HALF_OPEN — one probe request is allowed to test recovery
    //
    // Storage: in-process static + APCu (when available) + Redis (for non-Redis circuits).
    // The Redis circuit NEVER uses Redis for its own state storage.
    'circuit_breakers' => [

        'redis' => [
            // After this many consecutive failures, open the circuit.
            'failure_threshold'  => (int)  env('CB_REDIS_FAILURE_THRESHOLD', 5),
            // Seconds in OPEN state before a probe is allowed.
            'reset_timeout'      => (int)  env('CB_REDIS_RESET_TIMEOUT', 30),
            // Consecutive successes in HALF_OPEN before closing.
            'success_threshold'  => (int)  env('CB_REDIS_SUCCESS_THRESHOLD', 2),
            // State storage: static only (APCu if available). Never Redis (circular dep).
            'storage'            => 'local',
        ],

        'mysql' => [
            'failure_threshold'  => (int)  env('CB_MYSQL_FAILURE_THRESHOLD', 3),
            'reset_timeout'      => (int)  env('CB_MYSQL_RESET_TIMEOUT', 60),
            'success_threshold'  => (int)  env('CB_MYSQL_SUCCESS_THRESHOLD', 1),
            // Redis-backed when available (cross-worker MySQL circuit sync)
            'storage'            => 'redis',
        ],

        'queue' => [
            'failure_threshold'  => (int)  env('CB_QUEUE_FAILURE_THRESHOLD', 10),
            'reset_timeout'      => (int)  env('CB_QUEUE_RESET_TIMEOUT', 60),
            'success_threshold'  => (int)  env('CB_QUEUE_SUCCESS_THRESHOLD', 3),
            'storage'            => 'redis',
        ],
    ],

    // ── Automatic Retry Policies ──────────────────────────────────────────────
    //
    // backoff: array of seconds to wait before each successive retry attempt.
    //          Last value repeats for remaining retries beyond the array length.
    // retry_until_minutes: max window (from dispatch time) to keep retrying.
    //   After this window, the job goes to failed_jobs regardless of $tries.
    'retry_policies' => [

        // Exam scoring — student waits for result. Retry aggressively for 30 min.
        'exam_scoring' => [
            'tries'                => (int) env('RETRY_SCORING_TRIES', 5),
            'backoff'              => [10, 30, 120, 300, 600],  // 10s, 30s, 2m, 5m, 10m
            'retry_until_minutes'  => (int) env('RETRY_SCORING_WINDOW_MIN', 30),
            'timeout_seconds'      => 300,
        ],

        // Rank calculation — runs once after all students submit.
        'ranking' => [
            'tries'                => (int) env('RETRY_RANKING_TRIES', 3),
            'backoff'              => [30, 120, 600],
            'retry_until_minutes'  => (int) env('RETRY_RANKING_WINDOW_MIN', 60),
            'timeout_seconds'      => 120,
        ],

        // Analytics — non-critical; can lag significantly.
        'analytics' => [
            'tries'    => 3,
            'backoff'  => [60, 300, 1800],
        ],

        // Notifications — user-facing but not blocking.
        'notifications' => [
            'tries'    => 3,
            'backoff'  => [60, 300, 900],
        ],

        // Monitoring state updates — display-only; discard on permanent failure.
        'monitoring' => [
            'tries'    => 2,
            'backoff'  => [5, 30],
        ],
    ],

    // ── Dead Letter Queue (DLQ) Classification ────────────────────────────────
    //
    // Jobs in 'critical' category trigger an alert and auto-retry when
    // detected by the RecoverFailedExamJobs command.
    'dlq' => [

        'critical_jobs' => [
            'App\\Jobs\\CalculateTestResults',
            'App\\Jobs\\CalculateTestRankings',
        ],

        'analytics_jobs' => [
            'App\\Listeners\\LogExamActivity',
            'App\\Listeners\\UpdateTestDenormalizedCounts',
        ],

        'notification_jobs' => [
            'App\\Listeners\\NotifyResultReady',
            'App\\Listeners\\NotifyAutoSubmit',
        ],

        'monitoring_jobs' => [
            'App\\Listeners\\UpdateMonitoringState',
        ],

        // Alert after this many critical failures accumulate.
        'alert_threshold'  => (int) env('DLQ_ALERT_THRESHOLD', 5),

        // Auto-retry critical jobs dispatched by recover command after this delay.
        'auto_retry_delay' => (int) env('DLQ_AUTO_RETRY_DELAY', 900),  // 15 minutes

        // Log entries older than this many days are purged by maintenance command.
        'log_retention_days' => (int) env('DLQ_LOG_RETENTION_DAYS', 30),
    ],

    // ── Recovery Monitoring Thresholds ────────────────────────────────────────
    'monitoring' => [
        // Warn when failed critical jobs exceed this count.
        'failed_critical_warn'  => (int) env('MONITOR_FAILED_CRITICAL_WARN', 5),
        'failed_critical_alert' => (int) env('MONITOR_FAILED_CRITICAL_ALERT', 20),

        // Warn when results queue depth exceeds these sizes.
        'results_queue_warn'    => (int) env('MONITOR_RESULTS_QUEUE_WARN', 500),
        'results_queue_alert'   => (int) env('MONITOR_RESULTS_QUEUE_ALERT', 2000),

        // Redis ping latency above this (ms) is flagged.
        'redis_ping_warn_ms'    => (int) env('MONITOR_REDIS_PING_WARN', 10),

        // Recovery log retention (days).
        'log_retention_days'    => (int) env('MONITOR_LOG_RETENTION_DAYS', 7),
    ],
];
