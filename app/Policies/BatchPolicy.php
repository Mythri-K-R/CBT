<?php

namespace App\Policies;

use App\Models\Batch;
use App\Models\User;

class BatchPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['institution_admin', 'faculty', 'super_admin']);
    }

    public function view(User $user, Batch $batch): bool
    {
        return $user->isSuperAdmin() || $user->institution_id === $batch->institution_id;
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['institution_admin', 'super_admin']);
    }

    public function update(User $user, Batch $batch): bool
    {
        return $user->isSuperAdmin() || ($user->isInstitutionAdmin() && $user->institution_id === $batch->institution_id);
    }

    public function delete(User $user, Batch $batch): bool
    {
        return $this->update($user, $batch);
    }
}
