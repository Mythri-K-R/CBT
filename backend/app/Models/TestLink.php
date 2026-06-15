<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TestLink extends Model
{
    protected $fillable = [
        'test_id', 'institution_id', 'slug', 'access_code', 'is_active',
        'expires_at', 'qr_code_path',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function test(): BelongsTo
    {
        return $this->belongsTo(Test::class);
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(TestLinkClick::class, 'link_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(TestAttempt::class, 'link_id');
    }

    public function isValid(): bool
    {
        if (!$this->is_active) {
            return false;
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }
        return true;
    }

    public function getUrlAttribute(): string
    {
        return config('app.frontend_url').'/test/'.$this->slug;
    }
}
