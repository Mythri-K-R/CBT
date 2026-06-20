<?php

namespace App\Listeners;

use App\Events\ExamAutoSubmitted;
use App\Events\ExamExpired;
use App\Events\ExamStarted;
use App\Events\ExamSubmitted;
use App\Events\FullscreenExitDetected;
use App\Events\SuspiciousActivityDetected;
use App\Events\TabSwitchDetected;
use App\Services\ExamEngine\ExamMonitoringService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Async listener that writes exam lifecycle events to the Redis monitoring layer.
 *
 * Runs on the dedicated 'monitoring' queue (lightweight, fast Redis writes).
 * Does NOT handle ExamHeartbeatReceived — that runs synchronously via
 * UpdateHeartbeatStatus to avoid creating ~667 queue jobs/second.
 *
 * Handles:
 *   ExamStarted             → recordExamStart()  (eager-loads student + test)
 *   ExamSubmitted           → recordSubmission()
 *   ExamAutoSubmitted       → recordSubmission()
 *   ExamExpired             → recordSubmission()
 *   TabSwitchDetected       → recordSuspiciousActivity()
 *   FullscreenExitDetected  → recordSuspiciousActivity()
 *   SuspiciousActivityDetected → recordSuspiciousActivity()
 */
class UpdateMonitoringState implements ShouldQueue
{
    public string $queue   = 'monitoring';
    public int    $tries   = 2;
    public int    $backoff = 5;

    public function __construct(private readonly ExamMonitoringService $monitoring) {}

    public function handle(object $event): void
    {
        match (true) {
            $event instanceof ExamStarted              => $this->onExamStarted($event),
            $event instanceof ExamSubmitted,
            $event instanceof ExamAutoSubmitted,
            $event instanceof ExamExpired              => $this->monitoring->recordSubmission($event->attempt),
            $event instanceof TabSwitchDetected        => $this->monitoring->recordSuspiciousActivity(
                $event->attempt,
                'tab_switch',
                ['switch_count' => $event->switchCount ?? null]
            ),
            $event instanceof FullscreenExitDetected   => $this->monitoring->recordSuspiciousActivity(
                $event->attempt,
                'fullscreen_exit',
                ['violation_count' => $event->violationCount ?? null]
            ),
            $event instanceof SuspiciousActivityDetected => $this->monitoring->recordSuspiciousActivity(
                $event->attempt,
                $event->eventType,
                ['violation_count' => $event->violationCount]
            ),
            default => null,
        };
    }

    public function failed(object $event, \Throwable $e): void
    {
        Log::error('UpdateMonitoringState failed', [
            'event' => class_basename($event),
            'error' => $e->getMessage(),
        ]);
    }

    private function onExamStarted(ExamStarted $event): void
    {
        // Relations are NOT serialized — reload them in the worker process.
        $attempt = $event->attempt;
        $attempt->loadMissing('student:id,name', 'test:id,institution_id');
        $this->monitoring->recordExamStart($attempt);
    }
}
