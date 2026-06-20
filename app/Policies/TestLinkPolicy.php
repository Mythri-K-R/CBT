<?php

namespace App\Policies;

use App\Models\TestLink;
use App\Models\User;

class TestLinkPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin()
            || $user->isInstitutionAdmin()
            || $user->hasPermission('can_generate_links');
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, TestLink $link): bool
    {
        if ($user->isSuperAdmin()) return true;
        return $user->institution_id === $link->institution_id
            && ($user->isInstitutionAdmin() || $user->hasPermission('can_generate_links'));
    }

    public function delete(User $user, TestLink $link): bool
    {
        return $this->update($user, $link);
    }
}
