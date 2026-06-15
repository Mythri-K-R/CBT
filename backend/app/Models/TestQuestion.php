<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestQuestion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'test_id', 'section_id', 'question_id', 'question_number',
        'positive_marks', 'negative_marks', 'partial_marks', 'is_mandatory',
    ];

    protected $casts = ['is_mandatory' => 'boolean'];

    public function test(): BelongsTo
    {
        return $this->belongsTo(Test::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(TestSection::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
