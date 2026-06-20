<?php

namespace App\Jobs;

use App\Events\RankGenerated;
use App\Models\TestAttempt;
use App\Services\ExamEngine\RankingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CalculateTestRankings implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(public readonly int $testId) {}

    public function backoff(): array
    {
        return [30, 120, 600]; // 30s, 2m, 10m
    }

    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(
            config('resilience.retry_policies.ranking.retry_until_minutes', 60)
        );
    }

    public function handle(RankingService $rankingService): void
    {
        $rankingService->calculateRankings($this->testId);

        $total = TestAttempt::where('test_id', $this->testId)
            ->whereIn('status', ['submitted', 'completed'])
            ->count();

        // Cache::add() (SET NX) prevents duplicate events on job retry.
        if (Cache::add("events:rank_generated:{$this->testId}", 1, 86400)) {
            event(new RankGenerated($this->testId, $total));
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('CalculateTestRankings failed', [
            'test_id' => $this->testId,
            'error'   => $e->getMessage(),
        ]);
    }
}
