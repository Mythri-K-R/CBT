<?php

namespace App\Console\Commands;

use App\Services\Infrastructure\DeadLetterQueueService;
use Illuminate\Console\Command;

/**
 * Replay or purge failed jobs from the dead letter queue (failed_jobs table).
 *
 * WHEN TO RUN:
 *   1. After resolving a MySQL outage → replay critical scoring jobs
 *   2. After Horizon OOM crash       → replay all failed jobs
 *   3. Routine maintenance           → purge old non-critical failures
 *
 * JOB CATEGORIES:
 *   critical      — CalculateTestResults, CalculateTestRankings
 *                   Students are waiting for scores. NEVER silently discard.
 *   analytics     — LogExamActivity, UpdateTestDenormalizedCounts
 *                   Aggregate counts may be slightly stale after purge.
 *   notifications — NotifyResultReady, NotifyAutoSubmit
 *                   Students may not have received notifications; acceptable.
 *   monitoring    — UpdateMonitoringState
 *                   Dashboard data is rebuilt from heartbeats; safe to discard.
 *
 * IDEMPOTENCY GUARANTEE:
 *   CalculateTestResults checks `total_score !== null` before scoring.
 *   Replaying a job whose attempt was already scored is safe — it exits immediately.
 *
 * USAGE:
 *   php artisan examsphere:recover-failed-jobs --stats
 *   php artisan examsphere:recover-failed-jobs --critical-only
 *   php artisan examsphere:recover-failed-jobs --category=analytics
 *   php artisan examsphere:recover-failed-jobs --all
 *   php artisan examsphere:recover-failed-jobs --purge-non-critical
 *   php artisan examsphere:recover-failed-jobs --purge-non-critical --older-than=48
 */
class RecoverFailedExamJobs extends Command
{
    protected $signature = 'examsphere:recover-failed-jobs
                            {--stats           : Show DLQ statistics and exit}
                            {--critical-only   : Replay only critical jobs (scoring, ranking)}
                            {--category=       : Replay jobs in a specific category}
                            {--all             : Replay ALL failed jobs (use with caution)}
                            {--purge-non-critical : Delete non-critical jobs older than --older-than hours}
                            {--older-than=24   : Hours threshold for --purge-non-critical}
                            {--list-critical   : List critical failed jobs with detail}';

    protected $description = 'Replay or purge failed exam jobs from the dead letter queue';

    public function handle(DeadLetterQueueService $dlq): int
    {
        if ($this->option('stats')) {
            return $this->showStats($dlq);
        }

        if ($this->option('list-critical')) {
            return $this->listCritical($dlq);
        }

        if ($this->option('purge-non-critical')) {
            return $this->purgeNonCritical($dlq);
        }

        if ($this->option('critical-only')) {
            return $this->replayCategory($dlq, 'critical');
        }

        if ($category = $this->option('category')) {
            return $this->replayCategory($dlq, $category);
        }

        if ($this->option('all')) {
            return $this->replayAll($dlq);
        }

        $this->showHelp();
        return self::SUCCESS;
    }

    private function showStats(DeadLetterQueueService $dlq): int
    {
        $stats = $dlq->getStats();

        $this->info('Failed Job Statistics');
        $this->table(
            ['Category', 'Count'],
            [
                ['critical',       $stats['critical']],
                ['analytics',      $stats['analytics']],
                ['notifications',  $stats['notifications']],
                ['monitoring',     $stats['monitoring']],
                ['unknown',        $stats['unknown']],
                ['─────',          '─────'],
                ['TOTAL',          $stats['total']],
            ]
        );

        $threshold = config('resilience.dlq.alert_threshold', 5);
        if ($stats['critical'] >= $threshold) {
            $this->error("ALERT: {$stats['critical']} critical failed jobs >= threshold ({$threshold})");
            $this->error('Run: php artisan examsphere:recover-failed-jobs --critical-only');
        } elseif ($stats['critical'] > 0) {
            $this->warn("{$stats['critical']} critical failed jobs pending — review and replay");
        }

        return $stats['critical'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function listCritical(DeadLetterQueueService $dlq): int
    {
        $jobs = $dlq->getCriticalJobs(50);

        if (empty($jobs)) {
            $this->info('No critical failed jobs.');
            return self::SUCCESS;
        }

        $this->warn(count($jobs) . ' critical failed job(s):');
        $this->table(
            ['ID', 'UUID', 'Class', 'Queue', 'Failed At', 'Exception (truncated)'],
            array_map(fn ($j) => [
                $j['id'],
                substr($j['uuid'], 0, 8) . '...',
                class_basename($j['class']),
                $j['queue'],
                $j['failed_at'],
                $j['exception'],
            ], $jobs)
        );

        return self::FAILURE;
    }

    private function replayCategory(DeadLetterQueueService $dlq, string $category): int
    {
        $this->info("Replaying failed jobs in category: [{$category}]...");

        if (!$this->confirmAction("Re-queue all failed [{$category}] jobs? This may cause duplicate processing if not idempotent.")) {
            return self::SUCCESS;
        }

        $result = $dlq->replay($category);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Attempted', $result['attempted']],
                ['Succeeded', $result['succeeded']],
                ['Failed',    $result['failed']],
            ]
        );

        if ($result['failed'] > 0) {
            $this->warn("{$result['failed']} jobs failed to re-queue — check laravel.log");
            return self::FAILURE;
        }

        $this->info("✓  {$result['succeeded']} jobs re-queued successfully.");
        return self::SUCCESS;
    }

    private function replayAll(DeadLetterQueueService $dlq): int
    {
        $this->warn('This will re-queue ALL failed jobs across all categories.');

        if (!$this->confirmAction('Replay all failed jobs?')) {
            return self::SUCCESS;
        }

        $result = $dlq->replay('all');

        $this->table(
            ['Metric', 'Value'],
            [
                ['Attempted', $result['attempted']],
                ['Succeeded', $result['succeeded']],
                ['Failed',    $result['failed']],
            ]
        );

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function purgeNonCritical(DeadLetterQueueService $dlq): int
    {
        $hours   = (int) $this->option('older-than');
        $message = "Delete non-critical failed jobs older than {$hours} hours?";
        $message .= ' (Critical jobs are never deleted by this command.)';

        if (!$this->confirmAction($message)) {
            return self::SUCCESS;
        }

        $deleted = $dlq->purgeNonCritical($hours);
        $this->info("✓  Purged {$deleted} non-critical failed jobs older than {$hours}h.");
        return self::SUCCESS;
    }

    private function showHelp(): void
    {
        $this->info('Usage:');
        $this->line('  --stats                Show failed job counts by category');
        $this->line('  --critical-only        Replay critical scoring/ranking jobs');
        $this->line('  --category=<name>      Replay jobs in a specific category');
        $this->line('  --all                  Replay ALL failed jobs');
        $this->line('  --purge-non-critical   Delete non-critical jobs (--older-than=24h default)');
        $this->line('  --list-critical        List critical jobs with details');
    }

    private function confirmAction(string $message): bool
    {
        if ($this->option('no-interaction')) {
            return true;
        }
        return $this->confirm($message);
    }
}
