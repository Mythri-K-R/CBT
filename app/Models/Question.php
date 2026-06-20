<?php

namespace App\Models;

use App\Traits\BelongsToInstitution;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Question extends Model
{
    use BelongsToInstitution, HasUuid, SoftDeletes;

    protected $fillable = [
        'institution_id', 'created_by', 'exam_type', 'subject_id', 'chapter_id', 'topic_id',
        'difficulty', 'type', 'question_text', 'question_image', 'options',
        'correct_answer', 'answer_tolerance', 'positive_marks', 'negative_marks', 'partial_marks',
        'explanation', 'explanation_image', 'source', 'tags', 'language',
        'has_latex', 'has_image', 'source_type', 'original_question_id',
        'ai_review_status', 'ai_reviewed_by', 'status',
    ];

    protected $casts = [
        'options'    => 'array',
        'tags'       => 'array',
        'has_latex'  => 'boolean',
        'has_image'  => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(QuestionImage::class)->orderBy('display_order');
    }

    public function tests(): BelongsToMany
    {
        return $this->belongsToMany(Test::class, 'test_questions')
                    ->withPivot(['question_number', 'positive_marks', 'negative_marks', 'partial_marks']);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByDifficulty($query, string $difficulty)
    {
        return $query->where('difficulty', $difficulty);
    }

    public function scopeByChapter($query, int $chapterId)
    {
        return $query->where('chapter_id', $chapterId);
    }

    public function isCorrect(string $answer): bool
    {
        if ($this->type === 'numerical') {
            $tolerance = $this->answer_tolerance ?? 0;
            return abs((float)$answer - (float)$this->correct_answer) <= $tolerance;
        }
        return strtoupper(trim($answer)) === strtoupper(trim($this->correct_answer));
    }

    public function calculateMarks(string|null $answer): float
    {
        if ($answer === null || $answer === '') {
            return 0;
        }

        if ($this->type === 'multiple_mcq') {
            return $this->calculatePartialMarks($answer);
        }

        if ($this->isCorrect($answer)) {
            return (float) $this->positive_marks;
        }

        return -(float) $this->negative_marks;
    }

    private function calculatePartialMarks(string $answer): float
    {
        $selected = array_map('trim', explode(',', strtoupper($answer)));
        $correct  = array_map('trim', explode(',', strtoupper($this->correct_answer)));

        if (empty(array_diff($selected, $correct)) && empty(array_diff($correct, $selected))) {
            return (float) $this->positive_marks;
        }

        $correctPicked = count(array_intersect($selected, $correct));
        $wrongPicked   = count(array_diff($selected, $correct));

        if ($wrongPicked > 0) {
            return -(float) $this->negative_marks;
        }

        if ($this->partial_marks && $correctPicked > 0) {
            return (float) $this->partial_marks * $correctPicked;
        }

        return -(float) $this->negative_marks;
    }
}
