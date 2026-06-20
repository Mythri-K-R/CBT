<?php

namespace App\Console\Commands;

use App\Models\TestLink;
use Illuminate\Console\Command;

class DeactivateExpiredLinks extends Command
{
    protected $signature   = 'examsphere:deactivate-links';
    protected $description = 'Deactivate test links whose expires_at has passed';

    public function handle(): void
    {
        $count = TestLink::where('is_active', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['is_active' => false]);

        if ($count > 0) {
            $this->info("Deactivated {$count} expired link(s).");
        }
    }
}
