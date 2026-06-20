<?php

namespace App\Events;

use App\Models\TestAttempt;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired inside CalculateTestResults job after scores are written to the DB.
 * Fires alongside the existing TestSubmitted event (backward compatibility).
 */
class ResultCalculated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly TestAttempt $attempt) {}
}
