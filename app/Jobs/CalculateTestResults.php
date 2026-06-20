<?php

namespace App\Jobs;

use App\Events\ResultCalculated;
use App\Events\TestSubmitted;
use App\Models\TestAttempt;
use App\Models\TestResponse;
use App\Services\Infrastructure\DistributedLockService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WHY THIS JOB EXISTS:
 * Scoring 180 questions synchronously during submission adds 300–800 ms to
 * every submit request under load, which collapses under 20,000 concurrent
 * students. This job moves all heavy work off the critical path:
 *   1. TestSubmitService marks the attempt "submitted" (<50 ms).
 *   2. This job calculates scores, updates DB, fires the analytics event.
 *
 * Event ordering is preserved: TestSubmitted fires AFTER scores are written,
 * so the EvaluateTestAttempt analytics listener always sees complete data.
 *
 * LOCKING STRATEGY (two layers):
 *   Layer 1 — Redis distributed lock (cross-server):
 *     exam:score:{attemptId} with try-once semantics. If another Horizon worker
 *     on a different server is already scoring this attempt, this job re-queues
 *     itself rather than blocking (prevents two workers scoring simultaneously).
 *   Layer 2 — DB::lockForUpdate() inside DB::transaction (single-server safety):
 *     Preserved from Phase 1. Also provides idempotency: if total_score is
 *     already set (scoring already completed), the job exits immediately.
 */
class CalculateTestResults implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 5;    // up to 5 attempts (10s + 30s + 2m + 5m + 10m ≈ 18 min)
    public int $timeout = 300;  // 5 minutes max per attempt

    public function __construct(public readonly int $attemptId) {}

    /**
     * Exponential backoff: each retry waits progressively longer.
     * If MySQL is restarting, later retries are more likely to succeed.
     */
    public function backoff(): array
    {
        return [10, 30, 120, 300, 600]; // 10s, 30s, 2m, 5m, 10m
    }

    /**
     * Absolute retry deadline — 30 minutes from dispatch.
     * After this window, the job moves to failed_jobs even if $tries remain.
     * A student waiting >30 min for their result is an ops escalation, not a retry.
     */
    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(
            config('resilience.retry_policies.exam_scoring.retry_until_minutes', 30)
        );
    }

    public function handle(DistributedLockService $locks): void
    {
        $lockKey = $locks->examScoreKey($this->attemptId);

        $locks->withLock(
            key:         $lockKey,
            ttl:         DistributedLockService::TTL_SCORE,
            waitSeconds: 0,   // Try-once: do not block waiting for the lock
            callback:    function (): void {
                // ── Exact Phase 1 scoring logic — unchanged ───────────────────
                DB::transaction(function () {
                    // Re-acquire a row-level lock so two workers can't score the same
                    // attempt simultaneously (e.g., if a stuck worker retries).
                    $attempt = TestAttempt::lockForUpdate()->find($this->attemptId);

                    if (!$attempt) {
                        Log::warning('CalculateTestResults: attempt not found', ['id' => $this->attemptId]);
                        return;
                    }

                    // If total_score is already set, scoring already completed — idempotent.
                    if ($attempt->total_score !== null) {
                        return;
                    }

                    // Eager-load responses with question + subject in ONE query (fixes N+1).
                    $responses = TestResponse::where('attempt_id', $attempt->id)
                        ->with([
                            'question:id,subject_id,type,positive_marks,negative_marks,correct_answer,options',
                            'question.subject:id,name',
                        ])
                        ->get();

                    $totalScore       = 0.0;
                    $maxPossibleScore = 0.0;
                    $totalCorrect     = 0;
                    $totalWrong       = 0;
                    $totalUnattempted = 0;
                    $totalMarked      = 0;
                    $subjectScores    = [];
                    $timeSpent        = 0;

                    // Accumulate per-response evaluation results.
                    $responseUpdates = [];

                    foreach ($responses as $response) {
                        $question         = $response->question;
                        $maxPossibleScore += $question->positive_marks;

                        if (in_array($response->status, ['marked_review', 'answered_marked_review'])) {
                            $totalMarked++;
                        }

                        $timeSpent += $response->time_spent_seconds;

                        if (!$response->isAnswered()) {
                            $totalUnattempted++;
                            $marksAwarded = 0.0;
                            $isCorrect    = null;
                        } else {
                            $marksAwarded = (float) $question->calculateMarks($response->selected_answer);
                            $isCorrect    = $marksAwarded > 0;

                            if ($isCorrect) {
                                $totalCorrect++;
                            } else {
                                $totalWrong++;
                            }
                            $totalScore += $marksAwarded;
                        }

                        $responseUpdates[] = [
                            'id'            => $response->id,
                            'is_correct'    => $isCorrect,
                            'marks_awarded' => $marksAwarded,
                        ];

                        // Subject-level aggregation (avoids extra queries for report).
                        $sid = $question->subject_id;
                        if (!isset($subjectScores[$sid])) {
                            $subjectScores[$sid] = [
                                'subject_id'   => $sid,
                                'subject_name' => $question->subject->name ?? '',
                                'score'        => 0.0,
                                'max_score'    => 0.0,
                                'correct'      => 0,
                                'wrong'        => 0,
                                'unattempted'  => 0,
                                'time_seconds' => 0,
                            ];
                        }
                        $subjectScores[$sid]['score']        += $marksAwarded;
                        $subjectScores[$sid]['max_score']    += $question->positive_marks;
                        $subjectScores[$sid]['time_seconds'] += $response->time_spent_seconds;
                        if ($isCorrect === true)       $subjectScores[$sid]['correct']++;
                        elseif ($isCorrect === false)  $subjectScores[$sid]['wrong']++;
                        else                           $subjectScores[$sid]['unattempted']++;
                    }

                    // Bulk-update response evaluations in chunks of 500 to avoid
                    // hitting MySQL's max_allowed_packet or prepared-statement limits.
                    foreach (array_chunk($responseUpdates, 500) as $chunk) {
                        $this->bulkUpdateResponses($chunk);
                    }

                    // Derived metrics
                    $attempted  = $totalCorrect + $totalWrong;
                    $accuracy   = $attempted > 0
                        ? round($totalCorrect / $attempted * 100, 2)
                        : 0.0;
                    $percentage = $maxPossibleScore > 0
                        ? round($totalScore / $maxPossibleScore * 100, 2)
                        : 0.0;

                    foreach ($subjectScores as &$ss) {
                        $att            = $ss['correct'] + $ss['wrong'];
                        $ss['accuracy'] = $att > 0 ? round($ss['correct'] / $att * 100, 2) : 0.0;
                    }
                    unset($ss);

                    $attempt->update([
                        'total_score'         => $totalScore,
                        'max_possible_score'  => $maxPossibleScore,
                        'percentage'          => $percentage,
                        'total_correct'       => $totalCorrect,
                        'total_wrong'         => $totalWrong,
                        'total_unattempted'   => $totalUnattempted,
                        'total_marked_review' => $totalMarked,
                        'accuracy'            => $accuracy,
                        'time_spent_seconds'  => $timeSpent,
                        'subject_scores'      => array_values($subjectScores),
                    ]);

                    $freshAttempt = $attempt->fresh();

                    // Fire TestSubmitted AFTER scores are written so the queued
                    // analytics listener (EvaluateTestAttempt) always sees complete data.
                    event(new TestSubmitted($freshAttempt));

                    // Feature 4: ResultCalculated fires at the same point.
                    // Semantically distinct from TestSubmitted — carries the meaning
                    // "scoring is done, result is available" rather than "submission happened."
                    event(new ResultCalculated($freshAttempt));
                });
            },
            fallback: function (): void {
                // Another worker on a different server is already scoring this attempt.
                // Re-queue with a 30 s delay rather than having two workers compete.
                Log::info('CalculateTestResults: scoring lock held by another worker, re-queuing', [
                    'attempt_id' => $this->attemptId,
                ]);
                $this->release(30);
            }
        );
    }

    /**
     * Bulk-update is_correct + marks_awarded using a single CASE WHEN query
     * instead of N individual UPDATE statements — 100x faster at scale.
     */
    private function bulkUpdateResponses(array $updates): void
    {
        if (empty($updates)) {
            return;
        }

        $ids             = [];
        $correctParts    = [];
        $marksParts      = [];
        $correctBindings = [];
        $marksBindings   = [];

        foreach ($updates as $u) {
            $ids[]             = (int) $u['id'];
            $correctParts[]    = 'WHEN ? THEN ?';
            $marksParts[]      = 'WHEN ? THEN ?';
            $correctBindings[] = (int) $u['id'];
            $correctBindings[] = $u['is_correct'];
            $marksBindings[]   = (int) $u['id'];
            $marksBindings[]   = $u['marks_awarded'];
        }

        // Bindings must be ordered to match the SQL left-to-right:
        //   [all is_correct CASE params] + [all marks_awarded CASE params] + [WHERE IN params]
        // The original interleaved loop produced [id,c,id,m, id,c,id,m, ...] which caused
        // PDO to assign the second half of responses to the wrong CASE expression.
        $bindings = array_merge($correctBindings, $marksBindings, $ids);

        $correctSql = implode(' ', $correctParts);
        $marksSql   = implode(' ', $marksParts);
        $inList     = implode(',', array_fill(0, count($ids), '?'));

        DB::statement("
            UPDATE test_responses
            SET    is_correct    = CASE id {$correctSql} END,
                   marks_awarded = CASE id {$marksSql}   END
            WHERE  id IN ({$inList})
        ", $bindings);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('CalculateTestResults failed', [
            'attempt_id' => $this->attemptId,
            'error'      => $e->getMessage(),
            'trace'      => $e->getTraceAsString(),
        ]);
    }
}
