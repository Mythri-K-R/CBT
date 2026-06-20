<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Queued notification listener for system-triggered submissions.
 *
 * Handles: ExamAutoSubmitted (auto_timeout or auto_violation)
 *
 * TODO: Replace Log::info stub with actual notification delivery — e.g., alert
 * the institution admin that a student was auto-submitted due to a violation,
 * or notify the student that their time expired and their answers were saved.
 */
class NotifyAutoSubmit implements ShouldQueue
{
    public string $queue   = 'notifications';
    public int    $tries   = 3;
    public int    $backoff = 15;

    public function handle(object $event): void
    {
        Log::info('[NotifyAutoSubmit] ExamAutoSubmitted', [
            'attempt_id'      => $event->attempt->id,
            'student_id'      => $event->attempt->student_id,
            'test_id'         => $event->attempt->test_id,
            'submission_type' => $event->submissionType,
        ]);
    }

    public function failed(object $event, \Throwable $e): void
    {
        Log::error('[NotifyAutoSubmit] failed', [
            'attempt_id' => $event->attempt->id ?? null,
            'error'      => $e->getMessage(),
        ]);
    }
}
