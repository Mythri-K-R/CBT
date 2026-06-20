<?php

namespace App\Console\Commands;

use App\Models\Test;
use Illuminate\Console\Command;

class ActivateScheduledTests extends Command
{
    protected $signature   = 'examsphere:activate-scheduled';
    protected $description = 'Set tests to live status when their scheduled_start time arrives';

    public function handle(): void
    {
        $count = Test::where('status', 'scheduled')
            ->where('scheduled_start', '<=', now())
            ->update(['status' => 'live']);

        if ($count > 0) {
            $this->info("Activated {$count} test(s).");
        }
    }
}
