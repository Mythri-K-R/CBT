<?php

namespace App\Listeners;

use App\Events\SubscriptionExpiring;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendExpiryNotification implements ShouldQueue
{
    public function handle(SubscriptionExpiring $event): void
    {
        $institution = $event->institution;
        $days        = $event->daysRemaining;

        // Notify institution admins
        $admins = User::where('institution_id', $institution->id)
                      ->where('role', 'institution_admin')
                      ->where('is_active', true)
                      ->get();

        foreach ($admins as $admin) {
            Notification::create([
                'institution_id' => $institution->id,
                'user_id'        => $admin->id,
                'type'           => 'subscription_expiry',
                'title'          => "Subscription Expiring in {$days} Day(s)",
                'message'        => "Your {$institution->plan} plan subscription expires in {$days} day(s) on {$institution->subscription_end->format('d M Y')}. Please renew to avoid service interruption.",
                'data'           => ['days_remaining' => $days, 'plan' => $institution->plan],
            ]);
        }
    }
}
