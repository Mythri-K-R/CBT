<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InstitutionUserLoggedIn
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly User    $user,
        public readonly string  $ip,
        public readonly ?string $userAgent = null,
    ) {}
}
