<?php

namespace App\Services\ExamEngine;

use App\Models\TestAttempt;
use App\Models\TestTimerState;

class TimerService
{
    public function sync(TestAttempt $attempt): array
    {
        $timer = $attempt->timerState;

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

        return ['remaining' => $newRemaining, 'sections' => $sectionTimers];
    }

    public function getRemainingSeconds(TestAttempt $attempt): int
    {
        $timer = $attempt->timerState;
        if (!$timer) {
            return max(0, $attempt->getRemainingSeconds());
        }

        $elapsed = (int) $timer->last_sync_at->diffInSeconds(now());
        return max(0, $timer->remaining_seconds - $elapsed);
    }
}
