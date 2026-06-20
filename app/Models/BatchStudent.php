<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BatchStudent extends Model
{
    public $timestamps = false;

    protected $fillable = ['batch_id', 'student_id', 'enrolled_at', 'status'];

    protected $casts = ['enrolled_at' => 'date'];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
