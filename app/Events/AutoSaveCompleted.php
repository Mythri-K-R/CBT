<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired alongside AnswerSaved after every successful auto-save.
 * Carries scalar types only — no model serialization overhead at ~2 000/s peak.
 * No default listeners; reserved for Feature 5 real-time monitoring.
 */
class AutoSaveCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly int $attemptId,
        public readonly int $savedAt,
    ) {}
}
