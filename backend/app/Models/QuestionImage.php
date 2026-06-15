<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionImage extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'question_id', 'image_type', 'file_path', 'alt_text', 'display_order',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
