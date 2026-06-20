<?php

namespace App\Events;

use App\Models\TestAttempt;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FullscreenExitDetected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly TestAttempt $attempt,
        public readonly int         $violationCount,
    ) {}
}
