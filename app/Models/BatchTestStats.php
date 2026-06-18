<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BatchTestStats extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'batch_id', 'test_id', 'total_assigned', 'total_attempted',
        'avg_score', 'avg_accuracy', 'highest_score', 'lowest_score',
    ];

    protected $casts = ['created_at' => 'datetime'];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(Test::class);
    }
}
