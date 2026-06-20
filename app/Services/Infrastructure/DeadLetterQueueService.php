<?php

namespace App\Services\Infrastructure;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Dead Letter Queue (DLQ) Service — Feature 7
 *
 * Laravel's failed_jobs table is the DLQ. This service adds:
 *   1. Categorization (critical / analytics / notifications / monitoring)
 *   2. Selective replay (by category or by job class)
 *   3. Purge helpers for non-critical categories
 *   4. Aggregate statistics for the recovery monitoring endpoint
 *
 * CRITICAL JOBS: CalculateTestResults, CalculateTestRankings
 *   Students are waiting for their results. These are never silently discarded.
 *   Recovery flow:
 *     a. Alert fires when failed critical jobs >= config('resilience.dlq.alert_threshold')
 *     b. `php artisan examsphere:recover-failed-jobs --critical-only` re-queues them
 *     c. If underlying failure was transient (MySQL restart), jobs succeed on retry
 *     d. If structural (bad data), manual investigation is required
 *
 * NON-CRITICAL JOBS:
 *   Analytics, notifications, monitoring state — acceptable to discard after
 *   ops review if the underlying cause (e.g., queue worker OOM) is resolved.
 *   `php artisan examsphere:recover-failed-jobs --purge-non-critical` clears them.
 *
 * IDEMPOTENCY:
 *   CalculateTestResults already has `if ($attempt->total_score !== null) return;`
 *   so replaying it for an attempt that was already scored is safe.
 */
class DeadLetterQueueService
{
    // Job class → category mapping (short class name, not FQCN, for robustness)
    private const CATEGORY_MAP = [
        'CalculateTestResults'     => 'critical',
        'CalculateTestRankings'    => 'critical',
        'LogExamActivity'          => 'analytics',
        'UpdateTestDenormalizedCounts' => 'analytics',
        'NotifyResultReady'        => 'notifications',
        'NotifyAutoSubmit'         => 'notifications',
        'UpdateMonitoringState'    => 'monitoring',
    ];

    // ── Statistics ────────────────────────────────────────────────────────────

    /**
     * Return counts of failed jobs grouped by category.
     *
     * @return array{critical: int, analytics: int, notifications: int, monitoring: int, unknown: int, total: int}
     */
    public function getStats(): array
    {
        // Cache for 30 s — after a large outage the failed_jobs table can hold
        // thousands of rows; iterating them on every monitoring poll times out.
        // 30-second staleness is acceptable for a dashboard display.
        return Cache::remember('dlq:stats', 30, function () {
            try {
                return $this->countFromTable();
            } catch (\Throwable $e) {
                Log::warning('DeadLetterQueue: failed to read stats', ['error' => $e->getMessage()]);
                return [
                    'critical' => 0, 'analytics' => 0, 'notifications' => 0,
                    'monitoring' => 0, 'unknown' => 0, 'total' => 0,
                ];
            }
        });
    }

    /**
     * Return detailed list of failed critical jobs (id, uuid, payload summary, failed_at).
     */
    public function getCriticalJobs(int $limit = 50): array
    {
        try {
            return DB::table('failed_jobs')
                ->whereRaw("payload LIKE '%CalculateTestResults%' OR payload LIKE '%CalculateTestRankings%'")
                ->orderByDesc('failed_at')
                ->limit($limit)
                ->get(['id', 'uuid', 'queue', 'payload', 'exception', 'failed_at'])
                ->map(function ($row) {
                    $payload = json_decode($row->payload, true);
                    return [
                        'id'         => $row->id,
                        'uuid'       => $row->uuid,
                        'queue'      => $row->queue,
                        'class'      => $payload['displayName'] ?? 'unknown',
                        'data'       => json_decode($payload['data']['command'] ?? '{}', true),
                        'failed_at'  => $row->failed_at,
                        'exception'  => str($row->exception)->limit(200)->value(),
                    ];
                })
                ->toArray();
        } catch (\Throwable $e) {
            Log::warning('DeadLetterQueue: failed to list critical jobs', ['error' => $e->getMessage()]);
            return [];
        }
    }

    // ── Replay ────────────────────────────────────────────────────────────────

    /**
     * Replay all failed jobs in the given category by calling queue:retry for each.
     *
     * @param string $category 'critical' | 'analytics' | 'notifications' | 'monitoring' | 'all'
     * @return array{attempted: int, succeeded: int, failed: int}
     */
    public function replay(string $category = 'critical'): array
    {
        $attempted = 0;
        $succeeded = 0;
        $errors    = 0;

        try {
            $uuids = $this->getUuidsForCategory($category);

            foreach ($uuids as $uuid) {
                try {
                    Artisan::call('queue:retry', ['id' => [$uuid]]);
                    $succeeded++;
                } catch (\Throwable $e) {
                    $errors++;
                    Log::warning('DeadLetterQueue: replay failed for job', [
                        'uuid'  => $uuid,
                        'error' => $e->getMessage(),
                    ]);
                }
                $attempted++;
            }
        } catch (\Throwable $e) {
            Log::error('DeadLetterQueue: replay batch failed', ['error' => $e->getMessage()]);
        }

        Log::info("DeadLetterQueue: replay complete [{$category}]", compact('attempted', 'succeeded', 'errors'));

        return ['attempted' => $attempted, 'succeeded' => $succeeded, 'failed' => $errors];
    }

    /**
     * Purge non-critical failed jobs older than $olderThanHours.
     *
     * Never purges critical jobs — those require manual decision.
     */
    public function purgeNonCritical(int $olderThanHours = 24): int
    {
        $criticalClasses = array_keys(array_filter(self::CATEGORY_MAP, fn ($c) => $c === 'critical'));

        try {
            $query = DB::table('failed_jobs')
                ->where('failed_at', '<=', now()->subHours($olderThanHours)->toDateTimeString());

            foreach ($criticalClasses as $class) {
                $query->where('payload', 'not like', "%{$class}%");
            }

            $deleted = $query->delete();
            Log::info("DeadLetterQueue: purged {$deleted} non-critical failed jobs (>{$olderThanHours}h old)");
            return $deleted;
        } catch (\Throwable $e) {
            Log::error('DeadLetterQueue: purge failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Check whether the alert threshold for critical failed jobs has been crossed.
     * Returns true if an alert should be sent.
     */
    public function isCriticalAlertThresholdExceeded(): bool
    {
        $stats     = $this->getStats();
        $threshold = config('resilience.dlq.alert_threshold', 5);
        return $stats['critical'] >= $threshold;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function countFromTable(): array
    {
        $counts = [
            'critical'      => 0,
            'analytics'     => 0,
            'notifications' => 0,
            'monitoring'    => 0,
            'unknown'       => 0,
            'total'         => 0,
        ];

        DB::table('failed_jobs')
            ->orderByDesc('id')
            ->lazyById(200)
            ->each(function ($row) use (&$counts) {
                $category = $this->categorize($row->payload);
                $counts[$category]++;
                $counts['total']++;
            });

        return $counts;
    }

    private function categorize(string $payload): string
    {
        foreach (self::CATEGORY_MAP as $className => $category) {
            if (str_contains($payload, $className)) {
                return $category;
            }
        }
        return 'unknown';
    }

    private function getUuidsForCategory(string $category): array
    {
        if ($category === 'all') {
            return DB::table('failed_jobs')->pluck('uuid')->toArray();
        }

        // Collect class names for the requested category
        $classNames = array_keys(array_filter(self::CATEGORY_MAP, fn ($c) => $c === $category));

        if (empty($classNames)) {
            return [];
        }

        $query = DB::table('failed_jobs');

        // Chain OR conditions: payload LIKE '%ClassName%'
        $first = true;
        foreach ($classNames as $className) {
            if ($first) {
                $query->where('payload', 'like', "%{$className}%");
                $first = false;
            } else {
                $query->orWhere('payload', 'like', "%{$className}%");
            }
        }

        return $query->pluck('uuid')->toArray();
    }
}
