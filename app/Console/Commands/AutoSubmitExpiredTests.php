<?php

namespace App\Console\Commands;

use App\Models\TestAttempt;
use App\Services\ExamEngine\TestSubmitService;
use Illuminate\Console\Command;

class AutoSubmitExpiredTests extends Command
{
    protected $signature   = 'examsphere:auto-submit-expired';
    protected $description = 'Force-submit test attempts that have exceeded their server end time';

    public function handle(TestSubmitService $submitService): void
    {
        $expired = TestAttempt::where('status', 'in_progress')
            ->where('server_end_time', '<=', now()->subSeconds(config('examsphere.exam.auto_submit_grace_seconds', 60)))
            ->get();

        $count = 0;
        foreach ($expired as $attempt) {
            try {
                $submitService->submit($attempt, 'auto_timeout');
                $count++;
            } catch (\Throwable $e) {
                $this->error("Failed to auto-submit attempt {$attempt->uuid}: {$e->getMessage()}");
            }
        }

        if ($count > 0) {
            $this->info("Auto-submitted {$count} expired attempt(s).");
        }
    }
}
