<?php

namespace App\Listeners;

use App\Events\TestSubmitted;
use App\Services\Analytics\BatchAnalyticsService;
use App\Services\Analytics\StudentAnalyticsService;
use App\Services\QuestionBank\QuestionStatsService;
use Illuminate\Contracts\Queue\ShouldQueue;

class EvaluateTestAttempt implements ShouldQueue
{
    public string $queue = 'analytics';

    public function __construct(
        private readonly StudentAnalyticsService $studentAnalytics,
        private readonly BatchAnalyticsService   $batchAnalytics,
        private readonly QuestionStatsService    $questionStats
    ) {}

    public function handle(TestSubmitted $event): void
    {
        $attempt = $event->attempt->fresh();

        $this->studentAnalytics->updateStudentStats($attempt->student_id, $attempt->test_id);
        $this->batchAnalytics->updateBatchStats($attempt->test_id);
        $this->questionStats->updateStatsForAttempt($attempt->id);
    }
}
