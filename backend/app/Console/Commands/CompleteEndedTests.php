<?php

namespace App\Console\Commands;

use App\Jobs\CalculateTestRankings;
use App\Models\Test;
use Illuminate\Console\Command;

class CompleteEndedTests extends Command
{
    protected $signature   = 'examsphere:complete-ended';
    protected $description = 'Mark tests as completed when their scheduled_end has passed and trigger ranking calculation';

    public function handle(): void
    {
        $tests = Test::where('status', 'live')
            ->whereNotNull('scheduled_end')
            ->where('scheduled_end', '<=', now())
            ->get();

        foreach ($tests as $test) {
            $test->update(['status' => 'completed']);
            CalculateTestRankings::dispatch($test->id);
            $this->info("Completed test: {$test->title} (ID: {$test->id})");
        }
    }
}
