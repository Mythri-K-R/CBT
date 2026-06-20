<?php

namespace App\Events;

use App\Models\Student;
use App\Models\TestLink;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a student successfully identifies themselves via a test link.
 * Students have no separate login — this is their equivalent of authentication.
 */
class StudentLoggedIn
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Student  $student,
        public readonly TestLink $link,
        public readonly string   $ip,
    ) {}
}
