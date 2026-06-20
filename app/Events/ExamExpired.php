<?php

namespace App\Events;

use App\Models\TestAttempt;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by the auto-submit cron job just before force-submitting an expired attempt.
 */
class ExamExpired
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly TestAttempt $attempt) {}
}
