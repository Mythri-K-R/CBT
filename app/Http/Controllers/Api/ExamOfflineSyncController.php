<?php

namespace App\Http\Controllers\Api;

use App\Events\OfflineAnswersSynced;
use App\Http\Controllers\Controller;
use App\Models\TestAttempt;
use App\Services\ExamEngine\ExamStateCacheService;
use App\Services\ExamEngine\OfflineSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handles offline answer synchronisation (Feature 3).
 *
 * WHY this endpoint is outside single.session middleware:
 *   The student may have gone offline in one browser tab, returned in a new tab,
 *   or resumed after a system sleep. By the time the Service Worker fires its
 *   Background Sync the exam session token may have expired or changed. Requiring
 *   a valid session token here would drop all offline answers.
 *
 *   Security: the attempt UUID is 36-character cryptographically unguessable —
 *   the same security model used by the /result and /solutions endpoints.
 *
 * REQUEST BODY:
 *   {
 *     "answers": [
 *       {
 *         "offline_id":         "uuid-v4",          ← client idempotency key
 *         "test_question_id":   123,
 *         "selected_answer":    "B",                ← null to clear
 *         "status":             "answered",
 *         "time_spent_seconds": 45,
 *         "client_timestamp":   "2025-01-01T09:59:00.000Z"
 *       }
 *     ]
 *   }
 *
 * RESPONSE:
 *   {
 *     "attempt_status": "in_progress",
 *     "server_time":    "...",
 *     "synced":         3,
 *     "rejected":       [
 *       { "offline_id": "...", "reason": "stale",   "detail": "...", "server_timestamp": "..." },
 *       { "offline_id": "...", "reason": "expired",  "detail": "..." },
 *       { "offline_id": "...", "reason": "not_found","detail": "..." }
 *     ]
 *   }
 *
 * The SW treats ALL returned entries (synced + rejected) as "done" and removes
 * them from IndexedDB. Only a 5xx response causes a retry (the SW does not retry
 * 'stale' or 'expired' — those are permanent outcomes).
 */
class ExamOfflineSyncController extends Controller
{
    public function __construct(
        private readonly OfflineSyncService    $syncService,
        private readonly ExamStateCacheService $stateCache
    ) {}

    public function sync(Request $request, string $attemptUuid): JsonResponse
    {
        $request->validate([
            'answers'                      => 'required|array|min:1|max:200',
            'answers.*.offline_id'         => 'required|string|size:36',
            'answers.*.test_question_id'   => 'required|integer|min:1',
            'answers.*.selected_answer'    => 'nullable|string|max:500',
            'answers.*.status'             => 'required|in:not_answered,answered,marked_review,answered_marked_review',
            'answers.*.time_spent_seconds' => 'nullable|integer|min:0|max:86400',
            'answers.*.client_timestamp'   => 'required|date',
        ]);

        // Accept in_progress, submitted, and completed so idempotent retries
        // after exam submission still return a clean 200 response.
        $attempt = TestAttempt::where('uuid', $attemptUuid)
            ->whereIn('status', ['in_progress', 'submitted', 'completed'])
            ->firstOrFail();

        $result = $this->syncService->sync($attempt, $request->input('answers'));

        // Update activity in exam state cache — non-critical, best-effort
        if ($attempt->status === 'in_progress' && $result['synced'] > 0) {
            $this->stateCache->updateActivity($attempt->id, 0);
        }

        if ($result['synced'] > 0 || count($result['rejected']) > 0) {
            event(new OfflineAnswersSynced($attempt, $result['synced'], count($result['rejected'])));
        }

        return response()->json([
            'attempt_status' => $result['attempt_status'],
            'server_time'    => now()->toIso8601String(),
            'synced'         => $result['synced'],
            'rejected'       => $result['rejected'],
        ]);
    }
}
