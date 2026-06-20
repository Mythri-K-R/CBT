<?php

namespace App\Services\Infrastructure;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Redis Distributed Locking Service
 *
 * WHY THIS EXISTS OVER DB::lockForUpdate():
 *   lockForUpdate() creates a MySQL row lock bound to a single DB connection.
 *   When PHP-FPM runs on 3 servers each with their own connection pools, two
 *   servers can both acquire the "same" row lock on separate connections before
 *   either transaction commits — MySQL only serialises them when they share a
 *   connection. Redis Cache::lock() uses atomic SET NX PX against a single
 *   shared Redis instance, making it genuinely cross-server exclusive.
 *
 * FALLBACK BEHAVIOUR:
 *   If Redis is unreachable, withLock() logs an error and runs the callback
 *   WITHOUT a lock (graceful degradation). DB transactions still protect
 *   single-server data integrity. The platform continues operating; a warning
 *   is logged so ops can investigate.
 *
 * CIRCUIT BREAKER INTEGRATION (Feature 7):
 *   When the Redis circuit is OPEN, withLock() immediately falls into the
 *   degraded path (runs callback without lock). This avoids a Redis connection
 *   attempt on every incoming request when Redis is known to be down, reducing
 *   per-request latency from ~connection-timeout to near-zero.
 */
class DistributedLockService
{
    public function __construct(private readonly ?CircuitBreakerService $cb = null) {}

    // ── Key templates ─────────────────────────────────────────────────────────
    private const KEY_EXAM_START  = 'exam:start:%d:%d';  // testId, studentId
    private const KEY_EXAM_SUBMIT = 'exam:submit:%d';     // attemptId
    private const KEY_EXAM_SCORE  = 'exam:score:%d';      // attemptId

    // ── TTL constants (seconds) ───────────────────────────────────────────────
    public const TTL_START  = 60;   // start includes question pre-load
    public const TTL_SUBMIT = 30;   // lightweight submit < 100 ms, buffer for slow DB
    public const TTL_SCORE  = 120;  // full scoring job: 180 questions + bulk UPDATE (~1-5 s typical, 120 s generous ceiling)

    // ── Key builders ──────────────────────────────────────────────────────────

    public function examStartKey(int $testId, int $studentId): string
    {
        return sprintf(self::KEY_EXAM_START, $testId, $studentId);
    }

    public function examSubmitKey(int $attemptId): string
    {
        return sprintf(self::KEY_EXAM_SUBMIT, $attemptId);
    }

    public function examScoreKey(int $attemptId): string
    {
        return sprintf(self::KEY_EXAM_SCORE, $attemptId);
    }

    // ── Core method ───────────────────────────────────────────────────────────

    /**
     * Execute $callback exclusively behind a Redis distributed lock.
     *
     * Behaviour:
     *   Lock acquired       → run $callback(), release lock, return result
     *   Lock not acquired   → return value($fallback) — $callback is NOT called
     *   Redis unavailable   → log error, run $callback() WITHOUT lock (degraded)
     *
     * @param  string   $key         Redis key (use the examXxxKey() builders)
     * @param  int      $ttl         Lock TTL in seconds — auto-release if process crashes
     * @param  int      $waitSeconds 0 = try-once; N > 0 = block up to N seconds for lock
     * @param  callable $callback    Protected operation (no arguments)
     * @param  mixed    $fallback    Returned when lock cannot be acquired.
     *                               Can be a scalar, array, or a Closure — if a Closure,
     *                               it is called and its return value is used.
     * @return mixed
     */
    public function withLock(
        string $key,
        int $ttl,
        int $waitSeconds,
        callable $callback,
        mixed $fallback = null
    ): mixed {
        // When Redis circuit is OPEN, skip lock attempt and run without lock (same
        // degraded path as a Redis connection failure, but without paying the timeout cost).
        if ($this->cb?->isOpen('redis')) {
            Log::warning('DistributedLock: Redis circuit OPEN — executing without distributed lock', ['key' => $key]);
            return $callback();
        }

        try {
            $lock = Cache::lock($key, $ttl);

            if ($waitSeconds > 0) {
                $acquired = $this->blockingAcquire($lock, $waitSeconds);
            } else {
                $acquired = $lock->get();
            }

            if (!$acquired) {
                Log::warning('DistributedLock: contention detected, returning fallback', [
                    'key'          => $key,
                    'wait_seconds' => $waitSeconds,
                ]);
                return value($fallback);
            }

            try {
                return $callback();
            } finally {
                // Always release — even when $callback throws.
                try {
                    $lock->release();
                } catch (\Throwable $releaseException) {
                    // Lock auto-expires via TTL — this does not affect data integrity.
                    // Log so stale keys in Redis are traceable during incident investigation.
                    Log::warning('DistributedLock: failed to release lock — will expire via TTL', [
                        'key'   => $key,
                        'error' => $releaseException->getMessage(),
                        'class' => $releaseException::class,
                    ]);
                }
            }

        } catch (LockTimeoutException $e) {
            // block() exhausted $waitSeconds without acquiring the lock.
            Log::warning('DistributedLock: timed out waiting for lock', [
                'key'   => $key,
                'error' => $e->getMessage(),
            ]);
            return value($fallback);

        } catch (\Throwable $e) {
            // Redis is unavailable (connection refused, timeout, serialisation error).
            // Degrade gracefully: run the operation without a distributed lock.
            // DB transactions (lockForUpdate) still enforce single-server safety.
            $this->cb?->recordFailure('redis', $e);
            Log::error('DistributedLock: Redis failure — executing without distributed lock', [
                'key'   => $key,
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);
            return $callback();
        }
    }

    /**
     * Non-blocking probe: returns true if $key is currently locked by another holder.
     *
     * Used as a fast "skip" check in batch commands to avoid N × $waitSeconds
     * blocking when two cron servers process the same queue simultaneously.
     * Falls back to false on Redis failure (safe: caller proceeds to withLock
     * which handles degradation itself).
     */
    public function isLocked(string $key): bool
    {
        try {
            $probe = Cache::lock($key, 1);
            if ($probe->get()) {
                $probe->release();
                return false; // We got it — not currently locked
            }
            return true; // Could not acquire — already locked by another holder
        } catch (\Throwable) {
            return false; // Redis down: assume unlocked, let withLock handle it
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function blockingAcquire(Lock $lock, int $seconds): bool
    {
        try {
            $lock->block($seconds);
            return true;
        } catch (LockTimeoutException) {
            return false;
        }
    }
}
