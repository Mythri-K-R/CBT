<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestTimerState extends Model
{
    public $timestamps = false;

    protected $table = 'test_timer_state';

    protected $fillable = [
        'attempt_id', 'remaining_seconds', 'section_timers', 'last_sync_at',
    ];

    protected $casts = [
        'section_timers' => 'array',
        'last_sync_at'   => 'datetime',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(TestAttempt::class, 'attempt_id');
    }
}
