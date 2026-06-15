<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestLinkClick extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'link_id', 'student_id', 'ip_address', 'user_agent', 'referrer', 'action',
    ];

    protected $casts = ['created_at' => 'datetime'];

    public function link(): BelongsTo
    {
        return $this->belongsTo(TestLink::class, 'link_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
