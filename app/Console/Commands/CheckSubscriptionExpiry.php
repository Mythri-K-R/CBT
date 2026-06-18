<?php

namespace App\Console\Commands;

use App\Services\SubscriptionService;
use Illuminate\Console\Command;

class CheckSubscriptionExpiry extends Command
{
    protected $signature   = 'examsphere:check-subscription-expiry';
    protected $description = 'Check subscription expiry and send warning notifications';

    public function handle(SubscriptionService $service): void
    {
        $service->checkExpiryWarnings();
        $deactivated = $service->deactivateExpired();

        if ($deactivated > 0) {
            $this->info("Deactivated {$deactivated} expired institution(s).");
        }

        $this->info('Subscription expiry check complete.');
    }
}
