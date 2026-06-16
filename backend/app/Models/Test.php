<?php

namespace App\Models;

use App\Traits\BelongsToInstitution;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Test extends Model
{
    use BelongsToInstitution, HasUuid, SoftDeletes;

    protected $fillable = [
        'institution_id', 'created_by', 'template_id', 'title', 'description',
        'exam_type', 'test_category', 'duration_minutes', 'has_sectional_timing',
        'allow_section_switching', 'scheduled_start', 'scheduled_end', 'result_publish_at',
        'shuffle_questions', 'shuffle_options', 'show_result_immediately', 'show_solutions_after',
        'max_attempts', 'fullscreen_required', 'tab_switch_limit', 'disable_copy_paste',
        'disable_right_click', 'auto_submit_on_violation', 'instructions', 'status',
        'total_marks',
    ];

    protected $casts = [
        'has_sectional_timing'      => 'boolean',
        'allow_section_switching'   => 'boolean',
        'shuffle_questions'         => 'boolean',
        'shuffle_options'           => 'boolean',
        'show_result_immediately'   => 'boolean',
        'show_solutions_after'      => 'boolean',
        'fullscreen_required'       => 'boolean',
        'disable_copy_paste'        => 'boolean',
        'disable_right_click'       => 'boolean',
        'auto_submit_on_violation'  => 'boolean',
        'scheduled_start'           => 'datetime',
        'scheduled_end'             => 'datetime',
        'result_publish_at'         => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ExamTemplate::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(TestSection::class)->orderBy('display_order');
    }

    public function testQuestions(): HasMany
    {
        return $this->hasMany(TestQuestion::class)->orderBy('question_number');
    }

    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(Question::class, 'test_questions')
                    ->withPivot(['question_number', 'positive_marks', 'negative_marks', 'section_id']);
    }

    public function batches(): BelongsToMany
    {
        return $this->belongsToMany(Batch::class, 'test_batches');
    }

    public function links(): HasMany
    {
        return $this->hasMany(TestLink::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(TestAttempt::class);
    }

    public function completedAttempts(): HasMany
    {
        return $this->hasMany(TestAttempt::class)->whereIn('status', ['submitted','completed']);
    }

    public function isLive(): bool
    {
        return $this->status === 'live';
    }

    public function isAccessible(): bool
    {
        $now = now();
        if ($this->scheduled_start && $now->lt($this->scheduled_start)) {
            return false;
        }
        if ($this->scheduled_end && $now->gt($this->scheduled_end)) {
            return false;
        }
        return in_array($this->status, ['live', 'scheduled']);
    }

    public function scopeLive($query)
    {
        return $query->where('status', 'live');
    }

    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled');
    }
}
