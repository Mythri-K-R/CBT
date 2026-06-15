<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestResponse extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'attempt_id', 'test_question_id', 'question_id', 'section_id',
        'selected_answer', 'status', 'is_correct', 'marks_awarded',
        'time_spent_seconds', 'first_visited_at', 'last_modified_at',
        'visit_count', 'answer_changes',
    ];

    protected $casts = [
        'is_correct'       => 'boolean',
        'answer_changes'   => 'array',
        'first_visited_at' => 'datetime',
        'last_modified_at' => 'datetime',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(TestAttempt::class, 'attempt_id');
    }

    public function testQuestion(): BelongsTo
    {
        return $this->belongsTo(TestQuestion::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(TestSection::class);
    }

    public function isAnswered(): bool
    {
        return in_array($this->status, ['answered', 'answered_marked_review'])
            && $this->selected_answer !== null;
    }
}
