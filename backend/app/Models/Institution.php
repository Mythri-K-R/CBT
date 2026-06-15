<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Institution extends Model
{
    use HasUuid, SoftDeletes;

    protected $fillable = [
        'code', 'name', 'slug', 'type', 'contact_person', 'email', 'phone',
        'city', 'state', 'address', 'logo_path', 'website',
        'plan', 'student_limit', 'faculty_limit', 'question_limit',
        'subscription_start', 'subscription_end', 'is_active', 'settings',
    ];

    protected $casts = [
        'settings'           => 'array',
        'is_active'          => 'boolean',
        'subscription_start' => 'date',
        'subscription_end'   => 'date',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(Batch::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function tests(): HasMany
    {
        return $this->hasMany(Test::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    public function subscriptionHistory(): HasMany
    {
        return $this->hasMany(SubscriptionHistory::class);
    }

    public function isSubscriptionActive(): bool
    {
        return $this->is_active
            && $this->subscription_end
            && $this->subscription_end->isFuture();
    }

    public function isWithinStudentLimit(): bool
    {
        return $this->students()->count() < $this->student_limit;
    }

    public function isWithinFacultyLimit(): bool
    {
        return $this->users()->where('role', 'faculty')->count() < $this->faculty_limit;
    }

    public function isWithinQuestionLimit(): bool
    {
        return $this->questions()->count() < $this->question_limit;
    }

    public function getAdmins(): HasMany
    {
        return $this->hasMany(User::class)->where('role', 'institution_admin');
    }

    public function getFaculty(): HasMany
    {
        return $this->hasMany(User::class)->where('role', 'faculty');
    }
}
