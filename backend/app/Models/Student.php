<?php

namespace App\Models;

use App\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    use BelongsToInstitution, SoftDeletes;

    protected $fillable = [
        'institution_id', 'name', 'roll_number', 'phone', 'parent_phone',
        'photo_path', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function batches(): BelongsToMany
    {
        return $this->belongsToMany(Batch::class, 'batch_students')
                    ->withPivot(['enrolled_at', 'status'])
                    ->wherePivot('status', 'active');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(TestAttempt::class);
    }

    public function subjectStats(): HasMany
    {
        return $this->hasMany(StudentSubjectStats::class);
    }

    public function chapterStats(): HasMany
    {
        return $this->hasMany(StudentChapterStats::class);
    }

    public function completedAttempts(): HasMany
    {
        return $this->hasMany(TestAttempt::class)->whereIn('status', ['submitted', 'completed']);
    }
}
