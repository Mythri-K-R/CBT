<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class InstitutionScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (!Auth::check()) {
            return;
        }

        $user = Auth::user();

        // Super admin sees all institutions — no filter applied
        if ($user->role === 'super_admin') {
            return;
        }

        if ($user->institution_id) {
            $builder->where($model->getTable().'.institution_id', $user->institution_id);
        }
    }
}
