<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TestAttempt extends Model
{
    use HasUuid;

    protected $fillable = [
        'test_id', 'student_id', 'link_id', 'started_at', 'server_end_time',
        'submitted_at', 'time_spent_seconds', 'submission_type',
        'tab_switch_count', 'fullscreen_violations', 'screen_capture_attempts',
        'ip_address', 'device_info', 'active_session_id',
        'total_score', 'max_possible_score', 'percentage',
        'total_correct', 'total_wrong', 'total_unattempted', 'total_marked_review',
        'accuracy', 'rank_in_test', 'total_participants', 'percentile',
        'subject_scores', 'status',
    ];

    protected $casts = [
        'started_at'      => 'datetime',
        'server_end_time' => 'datetime',
        'submitted_at'    => 'datetime',
        'subject_scores'  => 'array',
    ];

    public function test(): BelongsTo
    {
        return $this->belongsTo(Test::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(TestLink::class, 'link_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(TestResponse::class, 'attempt_id');
    }

    public function timerState(): HasOne
    {
        return $this->hasOne(TestTimerState::class, 'attempt_id');
    }

    public function proctorEvents(): HasMany
    {
        return $this->hasMany(ProctorEvent::class, 'attempt_id');
    }

    public function isInProgress(): bool
    {
        return $this->status === 'in_progress';
    }

    public function isExpired(): bool
    {
        return now()->gt($this->server_end_time);
    }

    public function getRemainingSeconds(): int
    {
        if ($this->isExpired()) {
            return 0;
        }
        return (int) now()->diffInSeconds($this->server_end_time);
    }
}
