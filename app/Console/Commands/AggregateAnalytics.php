<?php

namespace App\Console\Commands;

use App\Models\Test;
use App\Services\Analytics\BatchAnalyticsService;
use App\Services\Analytics\StudentAnalyticsService;
use Illuminate\Console\Command;

class AggregateAnalytics extends Command
{
    protected $signature   = 'examsphere:aggregate-analytics {--days=7 : Recalculate for tests completed in this many days}';
    protected $description = 'Nightly recalculation of analytics aggregation tables';

    public function handle(BatchAnalyticsService $batchAnalytics): void
    {
        $days = (int) $this->option('days');

        $tests = Test::where('status', 'completed')
            ->where('updated_at', '>=', now()->subDays($days))
            ->get('id');

        $this->info("Recalculating stats for {$tests->count()} test(s)...");

        foreach ($tests as $test) {
            try {
                $batchAnalytics->updateBatchStats($test->id);
            } catch (\Throwable $e) {
                $this->warn("Test {$test->id}: {$e->getMessage()}");
            }
        }

        $this->info('Analytics aggregation complete.');
    }
}
