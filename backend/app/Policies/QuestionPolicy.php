<?php

namespace App\Policies;

use App\Models\Question;
use App\Models\User;

class QuestionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Question $question): bool
    {
        if ($user->isSuperAdmin()) return true;
        return $question->institution_id === null || $question->institution_id === $user->institution_id;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin()
            || $user->isInstitutionAdmin()
            || $user->hasPermission('can_create_questions');
    }

    public function update(User $user, Question $question): bool
    {
        if ($user->isSuperAdmin()) return true;
        if ($question->institution_id !== $user->institution_id) return false;
        return $user->isInstitutionAdmin() || $user->hasPermission('can_edit_questions');
    }

    public function delete(User $user, Question $question): bool
    {
        return $this->update($user, $question);
    }
}
