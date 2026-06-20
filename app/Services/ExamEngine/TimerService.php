<?php

namespace App\Services\ExamEngine;

use App\Models\TestAttempt;
use App\Models\TestTimerState;
use Illuminate\Support\Facades\DB;

class TimerService
{
    public function __construct(private readonly ExamStateCacheService $stateCache) {}

    /**
     * Sync the timer for an active attempt.
     *
     * Hot path (Redis warm):
     *   1. Read {remaining_seconds, last_sync_at, section_timers} from Redis HASH
     *   2. Subtract elapsed seconds from remaining + section values
     *   3. Write-through to DB (preserves durability — DB never more than ~30s stale)
     *   4. Update Redis with new values
     *
     * Cold path (Redis miss / restart recovery):
     *   Same as Phase 1 DB-only logic — reads TestTimerState, calculates, writes.
     *   Populates Redis at the end so the next sync uses the hot path.
     *
     * The return shape is identical to Phase 1 — no API contract change.
     */
    public function sync(TestAttempt $attempt): array
    {
        // ── Try Redis first ────────────────────────────────────────────────────
        $cached = $this->stateCache->getTimerState($attempt->id);

        if ($cached !== null) {
            $elapsed      = now()->timestamp - $cached['last_sync_at'];
            $newRemaining = max(0, $cached['remaining_seconds'] - $elapsed);

            $sectionTimers = $cached['section_timers']
                ? json_decode($cached['section_timers'], true)
                : null;

            if ($sectionTimers) {
                foreach ($sectionTimers as $id => $rem) {
                    $sectionTimers[$id] = max(0, (int) $rem - $elapsed);
                }
            }

            // Write-through to DB — ensures recovery after Redis restart
            $this->writeTimerToDB($attempt->id, $newRemaining, $sectionTimers);

            // Update Redis with post-sync values
            $this->stateCache->syncTimerState($attempt->id, $newRemaining, $sectionTimers);

            return ['remaining' => $newRemaining, 'sections' => $sectionTimers];
        }

        // ── DB fallback (cache miss or Redis unavailable) ──────────────────────
        return $this->syncFromDB($attempt);
    }

    /**
     * Return remaining seconds without writing to DB or updating the sync timestamp.
     * Used for read-only checks (e.g., AutoSubmit grace period logic).
     */
    public function getRemainingSeconds(TestAttempt $attempt): int
    {
        $cached = $this->stateCache->getTimerState($attempt->id);

        if ($cached !== null) {
            $elapsed = now()->timestamp - $cached['last_sync_at'];
            return max(0, $cached['remaining_seconds'] - $elapsed);
        }

        // DB fallback
        $timer = $attempt->relationLoaded('timerState')
            ? $attempt->timerState
            : TestTimerState::where('attempt_id', $attempt->id)->first();

        if (!$timer) {
            return max(0, $attempt->getRemainingSeconds());
        }

        $elapsed = (int) $timer->last_sync_at->diffInSeconds(now());
        return max(0, $timer->remaining_seconds - $elapsed);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * DB-only sync path — exact Phase 1 logic, extracted verbatim.
     * Called on cache miss; also populates Redis so subsequent syncs are fast.
     */
    private function syncFromDB(TestAttempt $attempt): array
    {
        $timer = $attempt->relationLoaded('timerState')
            ? $attempt->timerState
            : TestTimerState::where('attempt_id', $attempt->id)->first();

        if (!$timer) {
            $remaining = max(0, $attempt->getRemainingSeconds());
            return ['remaining' => $remaining, 'sections' => null];
        }

        // Calculate elapsed since last sync
        $elapsed = (int) $timer->last_sync_at->diffInSeconds(now());

        $newRemaining = max(0, $timer->remaining_seconds - $elapsed);

        // Update section timers if applicable
        $sectionTimers = $timer->section_timers;
        if ($sectionTimers) {
            foreach ($sectionTimers as $sectionId => $sectionRemaining) {
                $sectionTimers[$sectionId] = max(0, $sectionRemaining - $elapsed);
            }
        }

        $timer->update([
            'remaining_seconds' => $newRemaining,
            'section_timers'    => $sectionTimers,
            'last_sync_at'      => now(),
        ]);

        // Lazy warmup: populate Redis for subsequent syncs (handles Redis restart)
        $this->stateCache->syncTimerState($attempt->id, $newRemaining, $sectionTimers);

        return ['remaining' => $newRemaining, 'sections' => $sectionTimers];
    }

    /**
     * Write-through: keep DB in sync with Redis so recovery is always possible.
     * Uses a raw DB::table() update to avoid loading the model (we already have
     * all values from Redis; no need to fetch the model just to call ->update()).
     */
    private function writeTimerToDB(int $attemptId, int $remainingSeconds, ?array $sectionTimers): void
    {
        DB::table('test_timer_state')
            ->where('attempt_id', $attemptId)
            ->update([
                'remaining_seconds' => $remainingSeconds,
                'section_timers'    => $sectionTimers ? json_encode($sectionTimers) : null,
                'last_sync_at'      => now(),
            ]);
    }
}
