<?php

namespace App\Services\ExamEngine;

use App\Models\TestAttempt;
use App\Models\TestTimerState;
use App\Services\Infrastructure\CircuitBreakerService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Exam State Cache — Redis HASH per active attempt on DB0.
 *
 * Key:  exam:state:{attemptId}
 * TTL:  max(300, server_end_time - now() + 7200)   ← 2-hour buffer beyond exam end
 *
 * WHY DB0 (not the cache store DB1):
 *   Laravel's Cache::flush() calls FLUSHDB on DB1, which would wipe exam state
 *   for every in-flight exam. DB0 is never flushed by the application.
 *
 * WHY HASH (not a serialised JSON string):
 *   HSET/HINCRBY operate on individual fields atomically. A JSON string would
 *   require read-modify-write for field updates (e.g., incrementing answered_count),
 *   introducing race conditions. HINCRBY is atomic by definition.
 *
 * DURABILITY GUARANTEE:
 *   Timer writes go to both Redis (this service) AND the DB (write-through in
 *   TimerService). DB is never more than one sync period (~30 s) stale. On Redis
 *   restart the next timer sync falls back to DB, repopulates Redis, and continues.
 *
 * CIRCUIT BREAKER INTEGRATION (Feature 7):
 *   An optional CircuitBreakerService is injected. When the Redis circuit is OPEN:
 *   - Read methods return null (callers fall back to DB — same path as cache miss)
 *   - Write methods return immediately (writes are lost; DB has the canonical value)
 *   - Activity methods return immediately (monitoring-only; non-critical to skip)
 *   When the circuit is CLOSED/HALF_OPEN, failures are recorded so the circuit
 *   opens after `failure_threshold` consecutive errors. This avoids per-request
 *   Redis connection-timeout overhead when Redis is down.
 *   Backward compatible: if $cb is null (no injection), all methods work as before.
 *
 * FIELD INVENTORY:
 *   status              — 'in_progress' | 'submitted'
 *   server_end_time     — Unix timestamp (fixed); used to compute expiry
 *   remaining_seconds   — int; updated on each timer sync
 *   section_timers      — JSON string; section-level timer map ('' if none)
 *   last_sync_at        — Unix timestamp; used to calculate elapsed time
 *   answered_count      — int; incremented/decremented when response status changes
 *   current_question_id — int; last question the student was on
 *   last_activity_at    — Unix timestamp; last save-response call
 */
class ExamStateCacheService
{
    private const STATE_KEY  = 'exam:state:%d';   // %d = attemptId
    private const TTL_BUFFER = 7200;              // 2 hours after exam ends

    // Injected optionally — null means no circuit breaker (backward-compatible).
    public function __construct(private readonly ?CircuitBreakerService $cb = null) {}

    // ── Warmup ────────────────────────────────────────────────────────────────

    /**
     * Populate the exam state hash when a new attempt is created.
     *
     * Called from TestStartService after the DB transaction commits. Always
     * queries TestTimerState fresh (the timer row was just created inside the
     * transaction and is not yet loaded on the attempt model).
     *
     * Failures are non-fatal: the first timer sync falls back to DB automatically.
     */
    public function warmup(TestAttempt $attempt): void
    {
        if ($this->cb?->isOpen('redis')) {
            return; // fast-fail: Redis is known-down, skip without a connection attempt
        }
        try {
            $timer = TestTimerState::where('attempt_id', $attempt->id)->first();

            $key  = $this->key($attempt->id);
            $ttl  = $this->calcTtl($attempt->server_end_time?->timestamp ?? now()->addHour()->timestamp);
            $data = $this->buildHashData($attempt, $timer);

            Redis::connection('default')->hmset($key, $data);
            Redis::connection('default')->expire($key, $ttl);

            $this->cb?->recordSuccess('redis');
        } catch (\Throwable $e) {
            $this->cb?->recordFailure('redis', $e);
            Log::warning('ExamStateCache: warmup failed — first timer sync will fall back to DB', [
                'attempt_id' => $attempt->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    // ── Timer reads ───────────────────────────────────────────────────────────

    /**
     * Read the three timer fields needed by TimerService from Redis.
     *
     * Returns null on cache miss OR Redis failure; caller falls back to DB.
     *
     * @return array{remaining_seconds: int, last_sync_at: int, section_timers: string}|null
     */
    public function getTimerState(int $attemptId): ?array
    {
        if ($this->cb?->isOpen('redis')) {
            return null; // fast-fail → caller falls back to DB
        }
        try {
            $data = Redis::connection('default')->hgetall($this->key($attemptId));

            if (empty($data)) {
                return null;
            }

            // Guard against a partially-written hash (e.g., interrupted warmup).
            if (!isset($data['remaining_seconds'], $data['last_sync_at'])) {
                return null;
            }

            $this->cb?->recordSuccess('redis');

            return [
                'remaining_seconds' => (int) $data['remaining_seconds'],
                'last_sync_at'      => (int) $data['last_sync_at'],
                'section_timers'    => $data['section_timers'] ?? '',
            ];
        } catch (\Throwable $e) {
            $this->cb?->recordFailure('redis', $e);
            Log::warning('ExamStateCache: Redis read failed for timer state', [
                'attempt_id' => $attemptId,
                'error'      => $e->getMessage(),
            ]);
            return null;
        }
    }

    // ── Timer writes ──────────────────────────────────────────────────────────

    /**
     * Update timer fields in Redis after a sync.
     *
     * Called by TimerService AFTER it has already written canonical values to DB.
     * The DB write-through preserves the existing durability guarantee.
     */
    public function syncTimerState(int $attemptId, int $remainingSeconds, ?array $sectionTimers): void
    {
        if ($this->cb?->isOpen('redis')) {
            return; // DB already has the authoritative value; skip Redis update
        }
        try {
            Redis::connection('default')->hmset($this->key($attemptId), [
                'remaining_seconds' => $remainingSeconds,
                'section_timers'    => $sectionTimers ? json_encode($sectionTimers) : '',
                'last_sync_at'      => now()->timestamp,
            ]);
            $this->cb?->recordSuccess('redis');
        } catch (\Throwable $e) {
            $this->cb?->recordFailure('redis', $e);
            Log::warning('ExamStateCache: failed to sync timer state to Redis', [
                'attempt_id' => $attemptId,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    // ── Activity tracking ─────────────────────────────────────────────────────

    /**
     * Record the most recently visited question and last activity timestamp.
     *
     * Best-effort: used for real-time monitoring. Non-critical if this fails.
     * Exceptions are silently suppressed to avoid any impact on the save path.
     */
    public function updateActivity(int $attemptId, int $questionId): void
    {
        if ($this->cb?->isOpen('redis')) {
            return;
        }
        try {
            Redis::connection('default')->hmset($this->key($attemptId), [
                'current_question_id' => $questionId,
                'last_activity_at'    => now()->timestamp,
            ]);
        } catch (\Throwable) {
            // Non-critical — monitoring may miss this one data point
        }
    }

    /**
     * Atomically adjust the answered-question counter.
     *
     * Pass +1 when a response transitions TO an answered state.
     * Pass -1 when a response transitions AWAY from an answered state.
     * Pass  0 for no change (method is a no-op).
     *
     * Use the static answeredDelta() helper to compute $delta from status strings.
     */
    public function adjustAnsweredCount(int $attemptId, int $delta): void
    {
        if ($delta === 0) {
            return;
        }
        if ($this->cb?->isOpen('redis')) {
            return;
        }
        try {
            Redis::connection('default')->hincrby($this->key($attemptId), 'answered_count', $delta);
        } catch (\Throwable) {
            // Non-critical
        }
    }

    // ── Invalidation ──────────────────────────────────────────────────────────

    /**
     * Delete the exam state hash when the attempt is submitted.
     *
     * Called from TestSubmitService so it fires for BOTH manual submit and
     * auto_timeout submit. The TTL would expire it eventually anyway; explicit
     * deletion reclaims memory immediately and prevents stale state reads.
     */
    public function invalidate(int $attemptId): void
    {
        if ($this->cb?->isOpen('redis')) {
            return; // TTL will auto-expire the key
        }
        try {
            Redis::connection('default')->del($this->key($attemptId));
        } catch (\Throwable) {
            // TTL auto-expires the key — no action needed
        }
    }

    // ── Full state (for monitoring) ───────────────────────────────────────────

    /**
     * Return the entire hash as an associative array.
     * Returns null on cache miss. Used by the monitoring service (Feature 5).
     */
    public function getFullState(int $attemptId): ?array
    {
        if ($this->cb?->isOpen('redis')) {
            return null;
        }
        try {
            $data = Redis::connection('default')->hgetall($this->key($attemptId));
            return empty($data) ? null : $data;
        } catch (\Throwable) {
            return null;
        }
    }

    // ── Static helpers ────────────────────────────────────────────────────────

    /**
     * Compute the signed delta for answered_count given a response status transition.
     *
     * Examples:
     *   'not_visited'  → 'answered'               = +1
     *   'answered'     → 'answered_marked_review'  =  0  (both answered)
     *   'answered'     → 'not_answered'            = -1
     *   'not_answered' → 'not_answered'            =  0
     */
    public static function answeredDelta(string $oldStatus, string $newStatus): int
    {
        static $answered = ['answered', 'answered_marked_review'];

        $wasAnswered = in_array($oldStatus, $answered, true);
        $isAnswered  = in_array($newStatus, $answered, true);

        if (!$wasAnswered && $isAnswered) {
            return +1;
        }
        if ($wasAnswered && !$isAnswered) {
            return -1;
        }
        return 0;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function key(int $attemptId): string
    {
        return sprintf(self::STATE_KEY, $attemptId);
    }

    private function calcTtl(int $serverEndTimestamp): int
    {
        $remaining = $serverEndTimestamp - now()->timestamp;
        return max(300, $remaining + self::TTL_BUFFER);
    }

    private function buildHashData(TestAttempt $attempt, ?TestTimerState $timer): array
    {
        return [
            'status'              => $attempt->status,
            'server_end_time'     => $attempt->server_end_time?->timestamp ?? now()->addHour()->timestamp,
            'remaining_seconds'   => $timer?->remaining_seconds ?? max(0, $attempt->getRemainingSeconds()),
            'section_timers'      => $timer?->section_timers ? json_encode($timer->section_timers) : '',
            'last_sync_at'        => $timer?->last_sync_at?->timestamp ?? now()->timestamp,
            'answered_count'      => 0,   // Fresh attempt — no answers yet
            'current_question_id' => 0,
            'last_activity_at'    => now()->timestamp,
        ];
    }
}
