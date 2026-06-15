<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'institution_name',
        'city',
        'exam_types',
        'student_count',
        'notes',
        'status',
        'institution_id',
    ];
}
