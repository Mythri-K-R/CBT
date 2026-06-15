<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Subject extends Model
{
    protected $fillable = [
        'institution_id', 'exam_type', 'name', 'code', 'display_order', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class)->orderBy('display_order');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    public function faculty(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'faculty_subjects', 'subject_id', 'faculty_id');
    }

    public function scopeForExam($query, string $examType)
    {
        return $query->where('exam_type', $examType);
    }

    public function scopePlatformWide($query)
    {
        return $query->whereNull('institution_id');
    }
}
