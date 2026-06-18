<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamTemplateSection extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'template_id', 'name', 'subject_id', 'question_count', 'mandatory_count',
        'duration_minutes', 'positive_marks', 'negative_marks', 'partial_marks',
        'question_type', 'display_order',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(ExamTemplate::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
