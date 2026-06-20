<?php

namespace App\Services\ExamEngine;

use App\Models\TestAttempt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Offline Answer Sync Service — Feature 3
 *
 * Accepts a batch of answers queued offline and writes them to the database
 * using timestamp-based conflict resolution.
 *
 * CONFLICT RESOLUTION (last-writer-wins by client_timestamp):
 *   1. client_timestamp > server last_modified_at  → apply (offline answer is newer)
 *   2. client_timestamp ≤ server last_modified_at  → reject as 'stale' (server is newer)
 *   3. client_timestamp > server_end_time          → reject as 'expired' (exam had ended)
 *
 * IDEMPOTENCY:
 *   The offline_id (client UUID v4) is sent to the server but is NOT stored — the
 *   response uses it as a key so the SW can match results back to queue entries.
 *   Re-submitting the same offline_id is safe: if the answer was already applied, its
 *   client_timestamp will now be ≤ last_modified_at → rejected as 'stale' (harmless).
 *
 * STALE ANSWER PROTECTION:
 *   Answers timestamped AFTER server_end_time are rejected regardless of conflict state.
 *   Answers timestamped before server_end_time but synced after (e.g., 30 s late due to
 *   network outage) are accepted — this is intentional.
 *
 * DEDUPLICATION:
 *   If the SW batches multiple saves for the same question before reconnecting, only
 *   the latest client_timestamp for each test_question_id is processed. The rest are
 *   discarded before the DB call to avoid redundant writes and double conflict checks.
 */
class OfflineSyncService
{
    public function sync(TestAttempt $attempt, array $answers): array
    {
        // Idempotent shortcut: exam already finished — accept everything as success.
        // The DB answers are canonical; offline queue is irrelevant at this point.
        if (in_array($attempt->status, ['submitted', 'completed'], true)) {
            return [
                'attempt_status' => $attempt->status,
                'synced'         => count($answers),
                'rejected'       => [],
            ];
        }

        $deduped = $this->deduplicate($answers);

        // Bulk-load existing response rows in one query
        $questionIds = array_column($deduped, 'test_question_id');
        $existing    = DB::table('test_responses')
            ->where('attempt_id', $attempt->id)
            ->whereIn('test_question_id', $questionIds)
            ->select(['id', 'test_question_id', 'last_modified_at', 'selected_answer', 'answer_changes'])
            ->get()
            ->keyBy('test_question_id');

        $serverEndTime = $attempt->server_end_time;
        $synced        = 0;
        $rejected      = [];
        $staleCount    = 0;

        // Wrap all UPDATE calls in a single transaction so the batch is applied
        // atomically. On partial DB failure the whole batch rolls back; the SW
        // retries and idempotency handles re-application correctly.
        DB::transaction(function () use (
            $deduped, $existing, $serverEndTime, &$synced, &$rejected
        ): void {
            foreach ($deduped as $answer) {
                $offlineId = $answer['offline_id'];
                $qid       = (int) $answer['test_question_id'];
                $clientTs  = Carbon::parse($answer['client_timestamp']);

                // ── Guard 1: answer made after exam window + clock-skew grace ──
                // Allow 60 s of client clock skew to avoid rejecting answers from
                // students whose device clock runs slightly fast.
                if ($clientTs->gt($serverEndTime->copy()->addSeconds(60))) {
                    $rejected[] = [
                        'offline_id' => $offlineId,
                        'reason'     => 'expired',
                        'detail'     => 'Answer timestamp is after the exam end time.',
                    ];
                    continue;
                }

                $row = $existing->get($qid);

                if (!$row) {
                    $rejected[] = [
                        'offline_id' => $offlineId,
                        'reason'     => 'not_found',
                        'detail'     => 'Question not found in this attempt.',
                    ];
                    continue;
                }

                // ── Guard 2: server has a more recent modification ────────────
                if ($row->last_modified_at) {
                    $serverTs = Carbon::parse($row->last_modified_at);
                    if ($serverTs->gt($clientTs)) {
                        $rejected[] = [
                            'offline_id'       => $offlineId,
                            'reason'           => 'stale',
                            'detail'           => 'Server has a more recent answer for this question.',
                            'server_timestamp' => $serverTs->toIso8601String(),
                        ];
                        $staleCount++;
                        continue;
                    }
                }

                // ── Apply ─────────────────────────────────────────────────────
                // Build audit trail using client timestamp so the change history
                // reflects when the student actually made the choice, not sync time.
                $changes = json_decode($row->answer_changes ?? '[]', true) ?? [];
                if ($row->selected_answer !== $answer['selected_answer']) {
                    $changes[] = [
                        'from'    => $row->selected_answer,
                        'to'      => $answer['selected_answer'],
                        'at'      => $clientTs->toIso8601String(),
                        'offline' => true,
                    ];
                }

                DB::table('test_responses')
                    ->where('id', $row->id)
                    ->update([
                        'selected_answer'    => $answer['selected_answer'],
                        'status'             => $answer['status'],
                        'time_spent_seconds' => DB::raw('time_spent_seconds + ' . max(0, (int) ($answer['time_spent_seconds'] ?? 0))),
                        'last_modified_at'   => now(),
                        'answer_changes'     => json_encode($changes),
                    ]);

                $synced++;
            }
        });

        if ($staleCount > 0) {
            // Stale rejections most commonly indicate device clock skew. Log at warning
            // so ops can identify students whose answers were not applied due to this.
            Log::warning('OfflineSyncService: stale answers rejected — possible device clock skew', [
                'attempt_id'  => $attempt->id,
                'stale_count' => $staleCount,
                'synced'      => $synced,
            ]);
        }

        Log::info('OfflineSyncService: batch complete', [
            'attempt_id' => $attempt->id,
            'input'      => count($answers),
            'synced'     => $synced,
            'rejected'   => count($rejected),
            'stale'      => $staleCount,
        ]);

        return [
            'attempt_status' => $attempt->status,
            'synced'         => $synced,
            'stale_count'    => $staleCount,
            'rejected'       => $rejected,
        ];
    }

    /**
     * When the same question was saved multiple times while offline, keep only
     * the entry with the latest client_timestamp — the others are superseded.
     */
    private function deduplicate(array $answers): array
    {
        $byQuestion = [];
        foreach ($answers as $answer) {
            $qid = (int) $answer['test_question_id'];
            if (!isset($byQuestion[$qid])) {
                $byQuestion[$qid] = $answer;
                continue;
            }
            if (Carbon::parse($answer['client_timestamp'])->gt(Carbon::parse($byQuestion[$qid]['client_timestamp']))) {
                $byQuestion[$qid] = $answer;
            }
        }
        return array_values($byQuestion);
    }
}
