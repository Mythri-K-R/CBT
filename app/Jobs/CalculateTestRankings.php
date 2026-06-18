<?php

namespace App\Jobs;

use App\Services\ExamEngine\RankingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CalculateTestRankings implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly int $testId) {}

    public function handle(RankingService $rankingService): void
    {
        $rankingService->calculateRankings($this->testId);
    }
}
