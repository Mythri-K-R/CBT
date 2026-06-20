<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TestSection extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'test_id', 'name', 'subject_id', 'duration_minutes', 'question_count',
        'mandatory_count', 'positive_marks', 'negative_marks', 'partial_marks', 'display_order',
    ];

    public function test(): BelongsTo
    {
        return $this->belongsTo(Test::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function testQuestions(): HasMany
    {
        return $this->hasMany(TestQuestion::class, 'section_id')->orderBy('question_number');
    }
}
