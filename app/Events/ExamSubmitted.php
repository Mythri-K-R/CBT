<?php

namespace App\Events;

use App\Models\TestAttempt;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired immediately when a student submits — before scoring starts.
 * submissionType: 'manual' | 'auto_timeout' | 'auto_violation'
 */
class ExamSubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly TestAttempt $attempt,
        public readonly string      $submissionType,
    ) {}
}
