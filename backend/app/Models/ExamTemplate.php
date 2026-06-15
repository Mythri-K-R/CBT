<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamTemplate extends Model
{
    protected $fillable = [
        'institution_id', 'name', 'exam_type', 'total_duration_minutes',
        'total_questions', 'total_marks', 'has_sectional_timing',
        'allow_section_switching', 'section_config', 'instructions', 'is_active',
    ];

    protected $casts = [
        'has_sectional_timing'    => 'boolean',
        'allow_section_switching' => 'boolean',
        'section_config'          => 'array',
        'is_active'               => 'boolean',
    ];

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(ExamTemplateSection::class, 'template_id')->orderBy('display_order');
    }

    public function tests(): HasMany
    {
        return $this->hasMany(Test::class, 'template_id');
    }
}
