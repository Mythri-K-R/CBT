<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstitutionEnquiry extends Model
{
    protected $fillable = [
        'institution_name', 'contact_person', 'email', 'phone', 'city', 'state',
        'student_count', 'institution_type', 'message', 'status', 'notes',
        'converted_institution_id',
    ];

    public function convertedInstitution(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'converted_institution_id');
    }

    public function scopeNewLeads($query)
    {
        return $query->where('status', 'new_lead');
    }
}
