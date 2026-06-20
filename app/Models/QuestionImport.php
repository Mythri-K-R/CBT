<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionImport extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'institution_id', 'uploaded_by', 'import_type', 'file_path',
        'exam_type', 'subject_id', 'chapter_id',
        'total_rows', 'imported', 'failed', 'duplicate_skipped',
        'error_log', 'status', 'completed_at',
    ];

    protected $casts = [
        'error_log'    => 'array',
        'created_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
