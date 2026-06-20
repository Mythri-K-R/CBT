<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestBatch extends Model
{
    public $timestamps = false;

    protected $fillable = ['test_id', 'batch_id'];

    public function test(): BelongsTo
    {
        return $this->belongsTo(Test::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
