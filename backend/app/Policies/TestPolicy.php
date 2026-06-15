<?php

namespace App\Policies;

use App\Models\Test;
use App\Models\User;

class TestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Test $test): bool
    {
        return $user->isSuperAdmin() || $user->institution_id === $test->institution_id;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin()
            || $user->isInstitutionAdmin()
            || $user->hasPermission('can_create_tests');
    }

    public function update(User $user, Test $test): bool
    {
        if ($user->isSuperAdmin()) return true;
        if ($user->institution_id !== $test->institution_id) return false;
        if ($test->status === 'live') return false;
        return $user->isInstitutionAdmin() || $user->hasPermission('can_create_tests');
    }

    public function delete(User $user, Test $test): bool
    {
        return $this->update($user, $test);
    }

    public function schedule(User $user, Test $test): bool
    {
        if ($user->isSuperAdmin()) return true;
        if ($user->institution_id !== $test->institution_id) return false;
        return $user->isInstitutionAdmin() || $user->hasPermission('can_schedule_tests');
    }
}
