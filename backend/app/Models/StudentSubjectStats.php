<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentSubjectStats extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'student_id', 'subject_id', 'exam_type',
        'total_questions', 'total_correct', 'total_wrong', 'accuracy', 'avg_time_per_question',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
