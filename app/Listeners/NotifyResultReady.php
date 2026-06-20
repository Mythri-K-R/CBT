<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Queued notification listener: informs relevant parties that results are ready.
 *
 * Handles: ResultCalculated (per-student score available),
 *          ResultPublished  (all rankings computed, test complete)
 *
 * TODO: Replace Log::info stub with actual notification delivery once the
 * student notification channel is implemented (email, SMS, push, or platform
 * in-app notification via the NotificationController).
 */
class NotifyResultReady implements ShouldQueue
{
    public string $queue   = 'notifications';
    public int    $tries   = 3;
    public int    $backoff = 30;

    public function handle(object $event): void
    {
        $context = ['event' => class_basename($event)];

        if (property_exists($event, 'attempt')) {
            $context['attempt_id'] = $event->attempt->id;
            $context['student_id'] = $event->attempt->student_id;
            $context['test_id']    = $event->attempt->test_id;
        }

        if (property_exists($event, 'testId')) {
            $context['test_id']    = $event->testId;
            $context['rank_count'] = $event->rankCount ?? null;
        }

        Log::info('[NotifyResultReady] ' . class_basename($event), $context);
    }

    public function failed(object $event, \Throwable $e): void
    {
        Log::error('[NotifyResultReady] failed to notify for ' . class_basename($event), [
            'error' => $e->getMessage(),
        ]);
    }
}
