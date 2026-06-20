<?php

namespace App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Async activity log for all exam lifecycle events.
 * Runs on the analytics queue so it never blocks the request path.
 *
 * Handles: ExamStarted, ExamSubmitted, ExamAutoSubmitted, ExamExpired,
 *          OfflineAnswersSynced, ResultCalculated, ResultPublished,
 *          RankGenerated, StudentLoggedIn, InstitutionUserLoggedIn
 */
class LogExamActivity implements ShouldQueue
{
    public string $queue   = 'analytics';
    public int    $tries   = 3;
    public int    $backoff = 10;

    public function handle(object $event): void
    {
        $context = ['event' => class_basename($event)];

        if (property_exists($event, 'attempt')) {
            $context['attempt_id'] = $event->attempt->id;
            $context['student_id'] = $event->attempt->student_id;
            $context['test_id']    = $event->attempt->test_id;
        }

        if (property_exists($event, 'testId')) {
            $context['test_id'] = $event->testId;
        }

        if (property_exists($event, 'student')) {
            $context['student_id'] = $event->student->id;
            $context['test_id']    = $event->link->test_id ?? null;
        }

        if (property_exists($event, 'user')) {
            $context['user_id']         = $event->user->id;
            $context['institution_id']  = $event->user->institution_id;
        }

        if (property_exists($event, 'submissionType')) {
            $context['submission_type'] = $event->submissionType;
        }

        if (property_exists($event, 'synced')) {
            $context['synced']   = $event->synced;
            $context['rejected'] = $event->rejected;
        }

        if (property_exists($event, 'rankCount')) {
            $context['rank_count'] = $event->rankCount;
        }

        if (property_exists($event, 'totalParticipants')) {
            $context['total_participants'] = $event->totalParticipants;
        }

        if (property_exists($event, 'resumed')) {
            $context['resumed'] = $event->resumed;
        }

        Log::info('[ExamActivity] ' . class_basename($event), $context);
    }

    public function failed(object $event, \Throwable $e): void
    {
        Log::error('[ExamActivity] failed to log ' . class_basename($event), [
            'error' => $e->getMessage(),
        ]);
    }
}
