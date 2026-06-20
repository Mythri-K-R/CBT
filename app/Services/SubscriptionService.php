<?php

namespace App\Services;

use App\Events\SubscriptionExpiring;
use App\Models\Institution;

class SubscriptionService
{
    public function checkExpiryWarnings(): void
    {
        $warningDays = config('examsphere.expiry_warnings', [30, 15, 7, 3, 1]);

        foreach ($warningDays as $days) {
            $institutions = Institution::where('is_active', true)
                ->whereDate('subscription_end', now()->addDays($days)->toDateString())
                ->get();

            foreach ($institutions as $institution) {
                event(new SubscriptionExpiring($institution, $days));
            }
        }
    }

    public function deactivateExpired(): int
    {
        return Institution::where('is_active', true)
            ->where('subscription_end', '<', now()->subDay())
            ->update(['is_active' => false]);
    }
}
