<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired on every timer sync call (~667/s at 20k concurrent students).
 * Carries scalar types only — no model serialization at this frequency.
 * No default listeners; reserved for Feature 5 real-time exam monitoring.
 */
class ExamHeartbeatReceived
{
    use Dispatchable;

    public function __construct(
        public readonly int $attemptId,
        public readonly int $remainingSeconds,
    ) {}
}
