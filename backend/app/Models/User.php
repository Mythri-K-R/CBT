<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasUuid, Notifiable, SoftDeletes;

    protected $fillable = [
        'institution_id', 'role', 'name', 'email', 'phone', 'username', 'password',
        'avatar_path', 'is_active', 'force_password_change', 'settings',
        'can_create_questions', 'can_edit_questions', 'can_create_tests',
        'can_schedule_tests', 'can_view_results', 'can_view_analytics',
        'can_generate_links', 'can_manage_students', 'assigned_subjects',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at'      => 'datetime',
        'last_login_at'          => 'datetime',
        'is_active'              => 'boolean',
        'force_password_change'  => 'boolean',
        'settings'               => 'array',
        'assigned_subjects'      => 'array',
        'can_create_questions'   => 'boolean',
        'can_edit_questions'     => 'boolean',
        'can_create_tests'       => 'boolean',
        'can_schedule_tests'     => 'boolean',
        'can_view_results'       => 'boolean',
        'can_view_analytics'     => 'boolean',
        'can_generate_links'     => 'boolean',
        'can_manage_students'    => 'boolean',
    ];

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function batches(): BelongsToMany
    {
        return $this->belongsToMany(Batch::class, 'batch_faculty', 'faculty_id', 'batch_id');
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'faculty_subjects', 'faculty_id', 'subject_id');
    }

    public function createdQuestions(): HasMany
    {
        return $this->hasMany(Question::class, 'created_by');
    }

    public function createdTests(): HasMany
    {
        return $this->hasMany(Test::class, 'created_by');
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isInstitutionAdmin(): bool
    {
        return $this->role === 'institution_admin';
    }

    public function isFaculty(): bool
    {
        return $this->role === 'faculty';
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->role !== 'faculty') {
            return true;
        }
        return (bool) ($this->$permission ?? false);
    }

    public function getFacultyPermissions(): array
    {
        return [
            'can_create_questions' => $this->can_create_questions,
            'can_edit_questions'   => $this->can_edit_questions,
            'can_create_tests'     => $this->can_create_tests,
            'can_schedule_tests'   => $this->can_schedule_tests,
            'can_view_results'     => $this->can_view_results,
            'can_view_analytics'   => $this->can_view_analytics,
            'can_generate_links'   => $this->can_generate_links,
            'can_manage_students'  => $this->can_manage_students,
        ];
    }
}
