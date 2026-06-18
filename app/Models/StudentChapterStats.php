<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentChapterStats extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'student_id', 'chapter_id',
        'total_questions', 'total_correct', 'total_wrong', 'accuracy',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }
}
