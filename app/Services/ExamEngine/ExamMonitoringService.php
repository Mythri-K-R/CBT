<?php

namespace App\Services\ExamEngine;

use App\Models\TestAttempt;
use App\Services\Infrastructure\CircuitBreakerService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

/**
 * Redis-backed monitoring layer for Feature 5 — Real-Time Exam Monitoring.
 *
 * ALL state lives on Redis DB0 (the 'default' connection) — never DB1 (cache),
 * which can be flushed by Cache::flush(). DB0 is never application-flushed.
 *
 * WRITE PATH  — called from event listeners (UpdateMonitoringState, UpdateHeartbeatStatus):
 *   recordExamStart()           → ExamStarted
 *   recordHeartbeat()           → ExamHeartbeatReceived  (synchronous, ~667/s)
 *   recordSubmission()          → ExamSubmitted / ExamAutoSubmitted / ExamExpired
 *   recordSuspiciousActivity()  → security events
 *
 * READ PATH   — called from MonitoringController (SSE loop + one-shot snapshot):
 *   getExamSnapshot()           → full per-attempt data for one test
 *   getInstitutionActiveTests() → testIds with recent activity for one institution
 *   getRedisHealth()            → ping + memory info
 *   getQueueHealth()            → Horizon queue sizes
 *
 * MULTI-SERVER: all servers share the same Redis instance → reads/writes are
 * globally consistent regardless of which PHP-FPM node handles a request.
 *
 * KEY INVENTORY (DB0):
 *   mon:exam:{testId}:attempts    SET    Active attemptIds
 *   mon:exam:{testId}:submitted   ZSET   score=unix_ts, member=attemptId
 *   mon:attempt:{attemptId}       HASH   student_name, test_id, institution_id, joined_at, ends_at
 *   mon:hb:{attemptId}            STRING "1"  TTL=90s  (absence ≡ disconnected)
 *   mon:suspicious:{testId}       ZSET   score=unix_ts, member=JSON{type, ...}
 *   mon:inst:{institutionId}      SET    testIds with recent activity
 */
class ExamMonitoringService
{
    // ── Key templates (DB0) ───────────────────────────────────────────────────
    private const ATTEMPTS_KEY   = 'mon:exam:%d:attempts';   // SET
    private const SUBMITTED_KEY  = 'mon:exam:%d:submitted';  // ZSET
    private const ATTEMPT_KEY    = 'mon:attempt:%d';         // HASH
    private const HEARTBEAT_KEY  = 'mon:hb:%d';              // STRING TTL
    private const SUSPICIOUS_KEY = 'mon:suspicious:%d';      // ZSET
    private const INST_KEY       = 'mon:inst:%d';            // SET

    // ── TTL constants ─────────────────────────────────────────────────────────
    private const HEARTBEAT_TTL  = 90;        // 3 missed 30 s heartbeats = disconnected
    private const SUSPICIOUS_MAX = 500;       // max events kept per test
    private const EXAM_DATA_TTL  = 259200;    // 3 days — covers post-exam review window

    // Injected optionally — monitoring degrades silently when Redis circuit is OPEN.
    public function __construct(private readonly ?CircuitBreakerService $cb = null) {}

    // ═══════════════════════════════════════════════════════════════════════════
    // WRITE PATH
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Populate monitoring data when an exam starts.
     *
     * Called from UpdateMonitoringState listener (async, monitoring queue).
     * Requires student + test relations to be eager-loaded by the caller.
     */
    public function recordExamStart(TestAttempt $attempt): void
    {
        if ($this->cb?->isOpen('redis')) {
            return; // monitoring is display-only; skip when Redis is known-down
        }
        try {
            $redis     = Redis::connection('default');
            $testId    = $attempt->test_id;
            $attemptId = $attempt->id;
            $now       = now()->timestamp;
            $endTs     = $attempt->server_end_time?->timestamp ?? ($now + 3600);
            $ttl       = max(3600, $endTs - $now + 7200); // 2 h buffer beyond exam end

            // Student info (fetched once, avoids repeated DB queries in the SSE loop)
            $redis->hmset($this->attemptKey($attemptId), [
                'test_id'        => $testId,
                'student_id'     => $attempt->student_id,
                'student_name'   => $attempt->student?->name ?? "Student #{$attempt->student_id}",
                'institution_id' => $attempt->test?->institution_id ?? 0,
                'joined_at'      => $attempt->started_at?->timestamp ?? $now,
                'ends_at'        => $endTs,
            ]);
            $redis->expire($this->attemptKey($attemptId), $ttl);

            // Register as active for this test
            $redis->sadd($this->attemptsKey($testId), $attemptId);
            $redis->expire($this->attemptsKey($testId), $ttl);

            // Track test under institution for overview dashboard
            $institutionId = $attempt->test?->institution_id;
            if ($institutionId) {
                $redis->sadd($this->instKey($institutionId), $testId);
                $redis->expire($this->instKey($institutionId), self::EXAM_DATA_TTL);
            }

            // Prime heartbeat so the student isn't flagged as disconnected immediately
            $redis->setex($this->heartbeatKey($attemptId), self::HEARTBEAT_TTL, 1);
        } catch (\Throwable $e) {
            Log::warning('ExamMonitoring: recordExamStart failed', [
                'attempt_id' => $attempt->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Refresh heartbeat TTL — called synchronously on every timer sync (~667/s).
     *
     * A single SETEX is the only Redis write per heartbeat: < 1 ms.
     * If the key expires (90 s without renewal) the student is considered
     * disconnected in the next SSE snapshot.
     */
    public function recordHeartbeat(int $attemptId): void
    {
        if ($this->cb?->isOpen('redis')) {
            return;
        }
        try {
            Redis::connection('default')->setex($this->heartbeatKey($attemptId), self::HEARTBEAT_TTL, 1);
        } catch (\Throwable) {
            // Non-critical — monitoring misses one heartbeat update
        }
    }

    /**
     * Move the attempt from active → submitted when the exam ends.
     *
     * Covers manual submit, auto_timeout, auto_violation, and exam expiry.
     */
    public function recordSubmission(TestAttempt $attempt): void
    {
        if ($this->cb?->isOpen('redis')) {
            return;
        }
        try {
            $redis     = Redis::connection('default');
            $testId    = $attempt->test_id;
            $attemptId = $attempt->id;

            $redis->srem($this->attemptsKey($testId), $attemptId);
            $redis->zadd($this->submittedKey($testId), now()->timestamp, $attemptId);
            $redis->expire($this->submittedKey($testId), self::EXAM_DATA_TTL);

            // Clean up heartbeat immediately; no need to wait for TTL
            $redis->del($this->heartbeatKey($attemptId));
        } catch (\Throwable $e) {
            Log::warning('ExamMonitoring: recordSubmission failed', [
                'attempt_id' => $attempt->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Append a security event to the per-test suspicious activity log.
     *
     * Capped at SUSPICIOUS_MAX entries (oldest trimmed) so the ZSET never grows unbounded.
     */
    public function recordSuspiciousActivity(TestAttempt $attempt, string $eventType, array $meta = []): void
    {
        try {
            $redis  = Redis::connection('default');
            $testId = $attempt->test_id;
            $now    = now()->timestamp;

            $entry = json_encode([
                'type'       => $eventType,
                'attempt_id' => $attempt->id,
                'student_id' => $attempt->student_id,
                'ts'         => $now,
                ...$meta,
            ]);

            $key = $this->suspiciousKey($testId);
            $redis->zadd($key, $now, $entry);
            // Trim: keep only the most recent SUSPICIOUS_MAX events
            $redis->zremrangebyrank($key, 0, -(self::SUSPICIOUS_MAX + 1));
            $redis->expire($key, self::EXAM_DATA_TTL);
        } catch (\Throwable) {
            // Non-critical
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // READ PATH  (called every 3 s from SSE loop — ZERO DB queries)
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Build a full monitoring snapshot for one test.
     *
     * Uses a single Redis pipeline to batch all per-student reads into one
     * round-trip regardless of student count. Reads per-student live state
     * (answeredCount, remainingSeconds, lastActivityAt) from the existing
     * ExamStateCacheService hash (exam:state:{attemptId}) — no duplication.
     *
     * @return array{
     *   test_id: int,
     *   active_count: int,
     *   submitted_count: int,
     *   disconnected_count: int,
     *   suspicious_count: int,
     *   students: array,
     *   recent_suspicious: array,
     *   snapshot_at: int
     * }
     */
    public function getExamSnapshot(int $testId): array
    {
        $redis = Redis::connection('default');

        $activeAttemptIds = $redis->smembers($this->attemptsKey($testId));
        $submittedCount   = (int) $redis->zcard($this->submittedKey($testId));
        $suspiciousCount  = (int) $redis->zcard($this->suspiciousKey($testId));

        // Batch all per-student reads in one pipeline round-trip
        $students          = [];
        $disconnectedCount = 0;

        if (!empty($activeAttemptIds)) {
            $pipe = $redis->pipeline();
            foreach ($activeAttemptIds as $aid) {
                $pipe->hgetall($this->attemptKey((int) $aid));   // meta stored at ExamStarted
                $pipe->hgetall("exam:state:{$aid}");             // live state from ExamStateCacheService
                $pipe->exists($this->heartbeatKey((int) $aid));  // TTL-based connection status
            }
            $pipeResults = $pipe->execute();

            foreach ($activeAttemptIds as $idx => $attemptId) {
                $info     = $pipeResults[$idx * 3]     ?? [];
                $state    = $pipeResults[$idx * 3 + 1] ?? [];
                $hasHb    = (bool) ($pipeResults[$idx * 3 + 2] ?? false);

                if (!$hasHb) {
                    $disconnectedCount++;
                }

                $students[] = [
                    'attempt_id'        => (int) $attemptId,
                    'student_id'        => (int) ($info['student_id'] ?? 0),
                    'student_name'      => $info['student_name'] ?? null,
                    'joined_at'         => (int) ($info['joined_at'] ?? 0),
                    'ends_at'           => (int) ($info['ends_at'] ?? 0),
                    'answered_count'    => (int) ($state['answered_count'] ?? 0),
                    'remaining_seconds' => (int) ($state['remaining_seconds'] ?? 0),
                    'last_activity_at'  => (int) ($state['last_activity_at'] ?? 0),
                    'connected'         => $hasHb,
                ];
            }
        }

        // Last 20 suspicious events (newest first)
        $rawSuspicious    = $redis->zrevrange($this->suspiciousKey($testId), 0, 19);
        $recentSuspicious = array_map(fn ($e) => json_decode($e, true), $rawSuspicious);

        return [
            'test_id'            => $testId,
            'active_count'       => count($activeAttemptIds),
            'submitted_count'    => $submittedCount,
            'disconnected_count' => $disconnectedCount,
            'suspicious_count'   => $suspiciousCount,
            'students'           => $students,
            'recent_suspicious'  => $recentSuspicious,
            'snapshot_at'        => now()->timestamp,
        ];
    }

    /**
     * Return all testIds that have seen activity for this institution.
     * Used by the institution-level overview endpoint.
     */
    public function getInstitutionActiveTests(int $institutionId): array
    {
        try {
            $ids = Redis::connection('default')->smembers($this->instKey($institutionId));
            return array_map('intval', $ids);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Recovery monitoring dashboard data — Feature 7.
     *
     * Aggregates: circuit breaker states, failed job counts by category,
     * Redis ping, MySQL connection count.
     * Called from MonitoringController::recovery() — no caching; reads live state.
     */
    public function getRecoveryStatus(): array
    {
        $cbStates  = [];
        $dlqStats  = [];
        $mysqlOk   = true;
        $mysqlInfo = [];

        // Circuit breaker states (from CircuitBreakerService via AppServiceProvider singleton)
        try {
            $cb       = app(\App\Services\Infrastructure\CircuitBreakerService::class);
            $cbStates = $cb->getStates();
        } catch (\Throwable $e) {
            $cbStates = ['error' => $e->getMessage()];
        }

        // DLQ statistics
        try {
            $dlq      = app(\App\Services\Infrastructure\DeadLetterQueueService::class);
            $dlqStats = $dlq->getStats();
        } catch (\Throwable $e) {
            $dlqStats = ['error' => $e->getMessage()];
        }

        // MySQL connectivity check
        try {
            $row       = \Illuminate\Support\Facades\DB::selectOne(
                "SELECT VARIABLE_VALUE as cnt FROM performance_schema.global_status WHERE VARIABLE_NAME = 'Threads_connected'"
            );
            $mysqlInfo = ['threads_connected' => (int) ($row?->cnt ?? 0), 'status' => 'ok'];
        } catch (\Throwable $e) {
            $mysqlOk   = false;
            $mysqlInfo = ['status' => 'error', 'error' => $e->getMessage()];
        }

        $dlqAlertThreshold = config('resilience.dlq.alert_threshold', 5);
        $criticalCount     = $dlqStats['critical'] ?? 0;

        return [
            'circuit_breakers' => $cbStates,
            'dlq'              => [
                'stats'              => $dlqStats,
                'alert_threshold'    => $dlqAlertThreshold,
                'critical_alert'     => $criticalCount >= $dlqAlertThreshold,
            ],
            'mysql'            => $mysqlInfo,
            'redis'            => $this->getRedisHealth(),
            'queues'           => $this->getQueueHealth(),
            'overall_status'   => $this->deriveOverallStatus($cbStates, $criticalCount, $mysqlOk),
            'ts'               => now()->timestamp,
        ];
    }

    private function deriveOverallStatus(array $cbStates, int $criticalFailed, bool $mysqlOk): string
    {
        $hasOpenCircuit = collect($cbStates)
            ->contains(fn ($s) => is_array($s) && ($s['state'] ?? '') === \App\Services\Infrastructure\CircuitBreakerService::STATE_OPEN);

        if (!$mysqlOk || $criticalFailed > 0) {
            return 'critical';
        }
        if ($hasOpenCircuit) {
            return 'degraded';
        }
        return 'healthy';
    }

    /**
     * Redis health: ping latency + memory usage.
     */
    public function getRedisHealth(): array
    {
        try {
            $redis = Redis::connection('default');
            $start = microtime(true);
            $redis->ping();
            $pingMs = round((microtime(true) - $start) * 1000, 2);

            $info = $redis->info('memory');

            return [
                'status'         => 'ok',
                'ping_ms'        => $pingMs,
                'used_memory_mb' => round(($info['used_memory'] ?? 0) / 1048576, 2),
                'peak_memory_mb' => round(($info['used_memory_peak'] ?? 0) / 1048576, 2),
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    /**
     * Horizon queue health: pending job count per queue.
     * Uses Queue::size() which reads the Redis list length — no DB query.
     */
    public function getQueueHealth(): array
    {
        $queues = ['results', 'analytics', 'notifications', 'monitoring', 'default'];
        $sizes  = [];

        foreach ($queues as $q) {
            try {
                $sizes[$q] = Queue::size($q);
            } catch (\Throwable) {
                $sizes[$q] = -1; // -1 = unable to read
            }
        }

        return $sizes;
    }

    // ── Key builders ──────────────────────────────────────────────────────────

    private function attemptsKey(int $testId): string
    {
        return sprintf(self::ATTEMPTS_KEY, $testId);
    }

    private function submittedKey(int $testId): string
    {
        return sprintf(self::SUBMITTED_KEY, $testId);
    }

    private function attemptKey(int $attemptId): string
    {
        return sprintf(self::ATTEMPT_KEY, $attemptId);
    }

    private function heartbeatKey(int $attemptId): string
    {
        return sprintf(self::HEARTBEAT_KEY, $attemptId);
    }

    private function suspiciousKey(int $testId): string
    {
        return sprintf(self::SUSPICIOUS_KEY, $testId);
    }

    private function instKey(int $institutionId): string
    {
        return sprintf(self::INST_KEY, $institutionId);
    }
}
