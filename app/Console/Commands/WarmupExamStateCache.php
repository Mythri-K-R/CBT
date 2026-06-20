<?php

namespace App\Console\Commands;

use App\Services\Infrastructure\ExamSessionRecoveryService;
use Illuminate\Console\Command;

/**
 * Rebuild Redis exam state after Redis restart or server reboot.
 *
 * WHEN TO RUN:
 *   1. Immediately after any Redis restart (before resuming Horizon workers)
 *   2. After a full server reboot (Redis data is lost without AOF/RDB)
 *   3. After promoting a Redis replica to primary (if AOF was not in sync)
 *   4. When a student reports their timer is wrong / dashboard shows them offline
 *
 * WHAT IT DOES:
 *   Queries MySQL for all in-progress TestAttempts and repopulates:
 *   - exam:state:{id} HASH on Redis DB0   (timer state, answered count)
 *   - mon:attempt:{id}, mon:exam:*        (monitoring metadata)
 *
 * MULTI-SERVER SAFETY:
 *   Uses a distributed lock so only one server runs warmup at a time.
 *   Other servers return immediately with a "skipped" message.
 *
 * PERFORMANCE:
 *   20,000 in-progress attempts → ~40 s typical (200 attempts/batch,
 *   2 Redis pipelines per batch). Run before Horizon restarts to avoid
 *   DB fallback storms on first timer sync after Redis restart.
 *
 * USAGE:
 *   php artisan examsphere:warmup-exam-cache
 *   php artisan examsphere:warmup-exam-cache --diagnose   (dry-run: shows missing count)
 */
class WarmupExamStateCache extends Command
{
    protected $signature = 'examsphere:warmup-exam-cache
                            {--diagnose : Only report how many attempts are missing from Redis; do not warmup}';

    protected $description = 'Rebuild Redis exam state from MySQL after Redis restart or server reboot';

    public function handle(ExamSessionRecoveryService $recovery): int
    {
        if ($this->option('diagnose')) {
            return $this->runDiagnose($recovery);
        }

        return $this->runWarmup($recovery);
    }

    private function runDiagnose(ExamSessionRecoveryService $recovery): int
    {
        $this->info('Diagnosing Redis vs MySQL exam state gap...');

        $diag = $recovery->diagnose();

        $this->table(
            ['Metric', 'Value'],
            [
                ['In-progress attempts (MySQL)', $diag['db_in_progress']],
                ['Redis cache present',           $diag['redis_cache_present']],
                ['Missing in Redis',              $diag['missing_in_redis']],
            ]
        );

        if ($diag['missing_in_redis'] > 0) {
            $this->warn("⚠  {$diag['missing_in_redis']} attempts are missing from Redis.");
            $this->warn('   Run without --diagnose to rebuild them.');
            return self::FAILURE;
        }

        $this->info('✓  Redis state is complete — no warmup needed.');
        return self::SUCCESS;
    }

    private function runWarmup(ExamSessionRecoveryService $recovery): int
    {
        $this->info('Starting exam state cache warmup...');

        $bar = null;

        $result = $recovery->warmupAllActiveAttempts(function (int $current, int $total) use (&$bar) {
            if (!$bar) {
                $bar = $this->output->createProgressBar($total);
                $bar->start();
            }
            $bar->setProgress($current);
        });

        if ($bar) {
            $bar->finish();
            $this->newLine();
        }

        if ($result['skipped'] === -1) {
            $this->warn('Warmup skipped — another server is already running warmup.');
            $this->warn('Redis will be fully warm once the other server completes.');
            return self::SUCCESS;
        }

        $this->table(
            ['Metric', 'Value'],
            [
                ['Attempts warmed',  $result['warmed']],
                ['Errors',           $result['errors']],
                ['Duration (ms)',    $result['duration_ms']],
            ]
        );

        if ($result['errors'] > 0) {
            $this->warn("⚠  {$result['errors']} attempts failed to warmup — check laravel.log");
            return self::FAILURE;
        }

        $this->info("✓  Warmup complete. {$result['warmed']} attempts rebuilt in {$result['duration_ms']}ms.");
        $this->info('   You can now restart Horizon workers.');
        return self::SUCCESS;
    }
}
