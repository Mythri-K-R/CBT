<?php

namespace App\Events;

use App\Models\TestAttempt;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired alongside ExamSubmitted when submission was triggered by the system
 * rather than the student (submissionType: 'auto_timeout' | 'auto_violation').
 */
class ExamAutoSubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly TestAttempt $attempt,
        public readonly string      $submissionType,
    ) {}
}
