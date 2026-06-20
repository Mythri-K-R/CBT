<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired on every successful answer save (~2 000/s at peak load).
 * Carries scalar types only — no model serialization overhead.
 * No default listeners; reserved for Feature 5 real-time monitoring.
 */
class AnswerSaved
{
    use Dispatchable;

    public function __construct(
        public readonly int    $attemptId,
        public readonly int    $questionId,
        public readonly string $status,
        public readonly bool   $isFirstAnswer,
    ) {}
}
