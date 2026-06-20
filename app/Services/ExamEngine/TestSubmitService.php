<?php

namespace App\Services\ExamEngine;

use App\Events\ExamAutoSubmitted;
use App\Events\ExamSubmitted;
use App\Events\StudentLoggedOut;
use App\Jobs\CalculateTestResults;
use App\Models\TestAttempt;
use App\Services\ExamEngine\ExamStateCacheService;
use App\Services\Infrastructure\DistributedLockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * LIGHTWEIGHT SUBMISSION DESIGN
 * ─────────────────────────────
 * Old flow  : validate → score 180 questions → update N responses → update
 *             attempt → fire analytics  (~300–800 ms under load)
 *
 * New flow  : validate → mark as submitted → return (~20–50 ms)
 *             CalculateTestResults job handles scoring asynchronously.
 *
 * Idempotency: Redis distributed lock (cross-server) + lockForUpdate() inside
 *             the DB transaction (single-server fallback) guarantee exactly-once
 *             semantics even when the student double-clicks Submit, two auto-submit
 *             workers race, or a browser retries after a timeout.
 *
 * The scoring job is dispatched AFTER the transaction commits to avoid the
 * edge case where a transaction rolls back after a job is already enqueued.
 */
class TestSubmitService
{
    public function __construct(
        private readonly DistributedLockService $locks,
        private readonly ExamStateCacheService  $stateCache
    ) {}

    public function submit(TestAttempt $attempt, string $submissionType = 'manual'): array
    {
        $lockKey = $this->locks->examSubmitKey($attempt->id);

        return $this->locks->withLock(
            key:         $lockKey,
            ttl:         DistributedLockService::TTL_SUBMIT,
            waitSeconds: 5,
            callback:    function () use ($attempt, $submissionType): array {
                // ── Exact Phase 1 submission logic — unchanged ────────────────
                $needsScoring = false;

                $result = DB::transaction(function () use ($attempt, $submissionType, &$needsScoring) {
                    // Row-level lock: only one request can enter this block for a
                    // given attempt at a time. All others block until the lock is
                    // released when the transaction commits.
                    $locked = TestAttempt::lockForUpdate()->find($attempt->id);

                    if (!$locked) {
                        return ['error' => 'Attempt not found.'];
                    }

                    // ── Idempotent check ──────────────────────────────────────
                    // Already submitted (by another tab / worker / auto-submit).
                    if ($locked->status !== 'in_progress') {
                        return [
                            'already_submitted' => true,
                            'processing'        => $locked->total_score === null,
                            'show_result'       => (bool) $locked->test->show_result_immediately,
                        ];
                    }

                    // ── Lightweight update ────────────────────────────────────
                    // Only the minimum fields needed to lock the attempt.
                    // Scores, subject_scores, rankings are all computed in the job.
                    $locked->update([
                        'status'          => 'submitted',
                        'submitted_at'    => now(),
                        'submission_type' => $submissionType,
                    ]);

                    $needsScoring = true;

                    return [
                        'processing'  => true,
                        'show_result' => (bool) $locked->test->show_result_immediately,
                    ];
                });

                // Dispatch AFTER the transaction commits so the job is never
                // enqueued for a rolled-back submission.
                if ($needsScoring) {
                    CalculateTestResults::dispatch($attempt->id)
                        ->onQueue('results');
                }

                // Invalidate exam state cache — exam is now submitted.
                // Covers both manual submit (from controller) and auto_timeout submit.
                $this->stateCache->invalidate($attempt->id);

                // Fire events only when submission actually happened ($needsScoring).
                // The idempotent path (already_submitted) must not re-fire events.
                if ($needsScoring) {
                    event(new ExamSubmitted($attempt, $submissionType));

                    if ($submissionType !== 'manual') {
                        event(new ExamAutoSubmitted($attempt, $submissionType));
                    }

                    event(new StudentLoggedOut($attempt));
                }

                return $result;
            },
            fallback: function () use ($attempt): array {
                // Lock contention: another server is already holding the submit lock
                // for this attempt. Read current state and return idempotent response
                // so the client is not left waiting indefinitely.
                $fresh = TestAttempt::select(['id', 'status', 'total_score'])
                                    ->with('test:id,show_result_immediately')
                                    ->find($attempt->id);

                if ($fresh && $fresh->status !== 'in_progress') {
                    return [
                        'already_submitted' => true,
                        'processing'        => $fresh->total_score === null,
                        'show_result'       => (bool) optional($fresh->test)->show_result_immediately,
                    ];
                }

                Log::warning('TestSubmitService: could not acquire submit lock', [
                    'attempt_id' => $attempt->id,
                ]);

                return ['error' => 'Submission is being processed. Please wait a moment and try again.'];
            }
        );
    }
}
