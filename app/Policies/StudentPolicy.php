<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;

class StudentPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['institution_admin', 'super_admin'])
            || $user->hasPermission('can_manage_students');
    }

    public function view(User $user, Student $student): bool
    {
        return $user->isSuperAdmin() || $user->institution_id === $student->institution_id;
    }

    public function create(User $user): bool
    {
        return $user->isInstitutionAdmin() || $user->isSuperAdmin() || $user->hasPermission('can_manage_students');
    }

    public function update(User $user, Student $student): bool
    {
        if ($user->isSuperAdmin()) return true;
        return $user->institution_id === $student->institution_id
            && ($user->isInstitutionAdmin() || $user->hasPermission('can_manage_students'));
    }

    public function delete(User $user, Student $student): bool
    {
        return $this->update($user, $student);
    }
}
