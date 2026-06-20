<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Log;

/**
 * Synchronous security event logger — runs in-process, never queued.
 * Critical events (session conflicts, violations) must be recorded before
 * the response is sent to avoid losing audit trails if the queue is down.
 *
 * Handles: SessionConflictDetected, TabSwitchDetected,
 *          FullscreenExitDetected, SuspiciousActivityDetected
 */
class LogSecurityEvent
{
    public function handle(object $event): void
    {
        $context = ['event' => class_basename($event)];

        if (property_exists($event, 'attempt')) {
            $context['attempt_id']   = $event->attempt->id;
            $context['attempt_uuid'] = $event->attempt->uuid;
            $context['student_id']   = $event->attempt->student_id;
            $context['test_id']      = $event->attempt->test_id;
        }

        if (property_exists($event, 'ip')) {
            $context['ip'] = $event->ip;
        }

        if (property_exists($event, 'conflictingSessionPrefix')) {
            $context['conflicting_session'] = $event->conflictingSessionPrefix;
        }

        if (property_exists($event, 'eventType')) {
            $context['violation_type'] = $event->eventType;
        }

        if (property_exists($event, 'violationCount')) {
            $context['violation_count'] = $event->violationCount;
        }

        if (property_exists($event, 'switchCount')) {
            $context['switch_count'] = $event->switchCount;
        }

        Log::warning('[SecurityEvent] ' . class_basename($event), $context);
    }
}
