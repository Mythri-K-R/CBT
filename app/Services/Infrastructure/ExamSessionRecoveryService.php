<?php

namespace App\Services\Infrastructure;

use App\Models\TestAttempt;
use App\Services\ExamEngine\ExamMonitoringService;
use App\Services\ExamEngine\ExamStateCacheService;
use Illuminate\Support\Facades\Log;

/**
 * Exam Session Recovery Service — Feature 7
 *
 * Rebuilds transient Redis state from durable MySQL data after any event that
 * clears Redis (restart, failover, AOF corruption, server reboot).
 *
 * WHAT IT RECOVERS:
 *   1. exam:state:{attemptId} hashes (ExamStateCacheService keys on DB0)
 *      → Used by TimerService and the SSE monitoring read path.
 *      → On cache miss, TimerService falls back to DB anyway — warmup just
 *        prevents that first-request DB fallback at scale.
 *   2. mon:* monitoring keys (ExamMonitoringService keys on DB0)
 *      → Rebuilt so the SSE dashboard shows in-progress students immediately
 *        after a Redis restart, rather than waiting for the next heartbeat.
 *
 * WHAT IT DOES NOT RECOVER:
 *   - HTTP sessions (mon:session:* on DB3). Session loss redirects students to
 *     re-identify and rejoin — the exam state they left behind is still in MySQL,
 *     so their answers and timer are not lost. Re-identify is the correct UX.
 *   - Distributed lock keys (exam:start:*, exam:submit:*, exam:score:*)
 *     → These are intentionally ephemeral. Stale lock keys would prevent
 *        operations; letting them expire is safer than rebuilding them.
 *
 * MULTI-SERVER SAFETY:
 *   Uses DistributedLockService to ensure only one server runs warmup
 *   concurrently. Other servers detect the lock and skip, reading from DB
 *   fallback until the primary warmup completes.
 *
 * CALLED BY:
 *   php artisan examsphere:warmup-exam-cache
 *   → Run this immediately after Redis restart and before Horizon restart.
 */
class ExamSessionRecoveryService
{
    private const WARMUP_LOCK_KEY = 'recovery:exam-cache-warmup';
    private const WARMUP_LOCK_TTL = 300; // 5 minutes — generous for 20k attempts

    public function __construct(
        private readonly ExamStateCacheService  $stateCache,
        private readonly ExamMonitoringService  $monitoring,
        private readonly DistributedLockService $locks
    ) {}

    /**
     * Warm up Redis for every in-progress exam attempt.
     *
     * @param callable|null $progress  Optional callback($current, $total) for progress reporting
     * @return array{warmed: int, skipped: int, errors: int, duration_ms: float}
     */
    public function warmupAllActiveAttempts(?callable $progress = null): array
    {
        $start   = microtime(true);
        $warmed  = 0;
        $skipped = 0;
        $errors  = 0;

        $lockKey = $this->locks->examStartKey(0, 0); // reuse key format; override below
        $result  = $this->locks->withLock(
            key:         self::WARMUP_LOCK_KEY,
            ttl:         self::WARMUP_LOCK_TTL,
            waitSeconds: 0,  // non-blocking — another server may already be running warmup
            callback:    function () use (&$warmed, &$skipped, &$errors, $progress): void {
                // Chunk to avoid loading 20k attempts into memory at once
                TestAttempt::where('status', 'in_progress')
                    ->with([
                        'student:id,name',
                        'test:id,institution_id',
                        'timerState',
                    ])
                    ->chunkById(200, function ($attempts) use (&$warmed, &$skipped, &$errors, $progress) {
                        $total = TestAttempt::where('status', 'in_progress')->count();

                        foreach ($attempts as $attempt) {
                            try {
                                $this->warmupAttempt($attempt);
                                $warmed++;
                            } catch (\Throwable $e) {
                                $errors++;
                                Log::error('ExamSessionRecovery: failed to warmup attempt', [
                                    'attempt_id' => $attempt->id,
                                    'error'      => $e->getMessage(),
                                ]);
                            }

                            if ($progress) {
                                $progress($warmed + $skipped + $errors, $total);
                            }
                        }
                    });
            },
            fallback: function () use (&$skipped): void {
                $skipped = -1; // Signal: another server is running warmup
                Log::info('ExamSessionRecovery: warmup skipped — lock held by another server');
            }
        );

        $durationMs = round((microtime(true) - $start) * 1000, 2);

        Log::info('ExamSessionRecovery: warmup complete', [
            'warmed'      => $warmed,
            'skipped'     => $skipped,
            'errors'      => $errors,
            'duration_ms' => $durationMs,
        ]);

        return [
            'warmed'      => $warmed,
            'skipped'     => $skipped,
            'errors'      => $errors,
            'duration_ms' => $durationMs,
        ];
    }

    /**
     * Recover a single student's exam session by attempt UUID.
     * Used for targeted recovery when a specific student reports losing state.
     *
     * @return array{success: bool, message: string}
     */
    public function recoverByUuid(string $attemptUuid): array
    {
        $attempt = TestAttempt::where('uuid', $attemptUuid)
            ->where('status', 'in_progress')
            ->with(['student:id,name', 'test:id,institution_id', 'timerState'])
            ->first();

        if (!$attempt) {
            return [
                'success' => false,
                'message' => "Attempt {$attemptUuid} not found or not in_progress",
            ];
        }

        try {
            $this->warmupAttempt($attempt);
            Log::info('ExamSessionRecovery: single attempt recovered', ['uuid' => $attemptUuid]);
            return ['success' => true, 'message' => "Attempt {$attemptUuid} recovered"];
        } catch (\Throwable $e) {
            Log::error('ExamSessionRecovery: single attempt recovery failed', [
                'uuid'  => $attemptUuid,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Count of in-progress attempts in DB vs. in Redis.
     * Used to assess how much state is missing after a Redis restart.
     *
     * @return array{db_in_progress: int, redis_cache_present: int, missing_in_redis: int}
     */
    public function diagnose(): array
    {
        $inProgress = TestAttempt::where('status', 'in_progress')->pluck('id');
        $missing    = 0;

        foreach ($inProgress as $attemptId) {
            $state = $this->stateCache->getFullState($attemptId);
            if ($state === null) {
                $missing++;
            }
        }

        return [
            'db_in_progress'     => count($inProgress),
            'redis_cache_present' => count($inProgress) - $missing,
            'missing_in_redis'   => $missing,
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function warmupAttempt(TestAttempt $attempt): void
    {
        // 1. Rebuild exam state cache (timer, answered count)
        $this->stateCache->warmup($attempt);

        // 2. Rebuild monitoring data (student list, heartbeat seed)
        $this->monitoring->recordExamStart($attempt);
    }
}
