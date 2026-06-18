<?php

namespace App\Traits;

use App\Scopes\InstitutionScope;
use Illuminate\Support\Facades\Auth;

trait BelongsToInstitution
{
    public static function bootBelongsToInstitution(): void
    {
        static::addGlobalScope(new InstitutionScope());

        static::creating(function ($model) {
            if (empty($model->institution_id) && Auth::check() && Auth::user()->institution_id) {
                $model->institution_id = Auth::user()->institution_id;
            }
        });
    }

    public function institution()
    {
        return $this->belongsTo(\App\Models\Institution::class);
    }

    public function scopeForInstitution($query, int $institutionId)
    {
        return $query->withoutGlobalScope(InstitutionScope::class)
                     ->where('institution_id', $institutionId);
    }
}
