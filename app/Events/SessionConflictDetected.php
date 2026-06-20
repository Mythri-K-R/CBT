<?php

namespace App\Events;

use App\Models\TestAttempt;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a request arrives with a session token that doesn't match the
 * active session — another browser/tab has taken over this attempt.
 */
class SessionConflictDetected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly TestAttempt $attempt,
        public readonly string      $ip,
        public readonly string      $conflictingSessionPrefix,
    ) {}
}
