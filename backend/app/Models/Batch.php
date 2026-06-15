<?php

namespace App\Models;

use App\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Batch extends Model
{
    use BelongsToInstitution, SoftDeletes;

    protected $fillable = [
        'institution_id', 'name', 'exam_type', 'batch_type',
        'target_year', 'start_date', 'description', 'max_students',
        'is_active', 'status',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'start_date' => 'date',
    ];

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'batch_students')
                    ->withPivot(['enrolled_at', 'status'])
                    ->wherePivot('status', 'active');
    }

    public function allStudents(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'batch_students')
                    ->withPivot(['enrolled_at', 'status']);
    }

    public function faculty(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'batch_faculty', 'batch_id', 'faculty_id');
    }

    public function tests(): BelongsToMany
    {
        return $this->belongsToMany(Test::class, 'test_batches');
    }

    public function batchTestStats(): HasMany
    {
        return $this->hasMany(BatchTestStats::class);
    }
}
