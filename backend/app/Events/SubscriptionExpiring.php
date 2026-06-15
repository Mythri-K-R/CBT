<?php

namespace App\Events;

use App\Models\Institution;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionExpiring
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Institution $institution,
        public readonly int $daysRemaining
    ) {}
}
