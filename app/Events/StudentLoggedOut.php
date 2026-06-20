<?php

namespace App\Events;

use App\Models\TestAttempt;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a student's exam session ends (exam submitted).
 * The exam submission IS the student logout — there is no separate logout action.
 */
class StudentLoggedOut
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly TestAttempt $attempt) {}
}
