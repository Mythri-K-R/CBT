<?php

namespace App\Console\Commands;

use App\Events\ExamExpired;
use App\Models\TestAttempt;
use App\Services\ExamEngine\TestSubmitService;
use App\Services\Infrastructure\DistributedLockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class AutoSubmitExpiredTests extends Command
{
    protected $signature   = 'examsphere:auto-submit-expired';
    protected $description = 'Force-submit test attempts that have exceeded their server end time';

    public function handle(TestSubmitService $submitService, DistributedLockService $locks): void
    {
        // Write heartbeat BEFORE processing so the health endpoint knows the cron
        // ran even if zero attempts were expired (no exams active is still a valid run).
        // TTL of 180 s = 3 cron cycles — health check flags failure if key is absent.
        Cache::put('health:cron:auto_submit', now()->timestamp, 180);

        $expired = TestAttempt::where('status', 'in_progress')
            ->where('server_end_time', '<=', now()->subSeconds(config('examsphere.exam.auto_submit_grace_seconds', 60)))
            ->get();

        $count = 0;
        foreach ($expired as $attempt) {
            try {
                // Non-blocking pre-check: if another server already holds the submit
                // lock for this attempt, skip it rather than queuing a blocking wait.
                //   - withoutOverlapping() prevents two runs on the SAME server.
                //   - isLocked() prevents duplicate work when two DIFFERENT servers
                //     both run their cron at the same instant and pick up the same
                //     batch of expired attempts.
                // The attempt will be processed by the other server. If that server
                // fails mid-submission, the next cron run will pick it up again
                // (attempt stays 'in_progress' until submit succeeds).
                if ($locks->isLocked($locks->examSubmitKey($attempt->id))) {
                    continue;
                }

                $result = $submitService->submit($attempt, 'auto_timeout');

                // Fire ExamExpired ONLY when this server performed the actual
                // submission (not already_submitted, not a lock-contention error).
                // This guarantees: (a) the event fires after a successful submit,
                // (b) exactly one server fires it per attempt in multi-server envs.
                $wasFirstSubmission = !isset($result['error']) && !isset($result['already_submitted']);
                if ($wasFirstSubmission) {
                    event(new ExamExpired($attempt));
                    $count++;
                }
            } catch (\Throwable $e) {
                $this->error("Failed to auto-submit attempt {$attempt->uuid}: {$e->getMessage()}");
            }
        }

        if ($count > 0) {
            $this->info("Auto-submitted {$count} expired attempt(s).");
        }
    }
}
