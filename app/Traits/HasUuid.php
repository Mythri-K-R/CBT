<?php

namespace App\Traits;

use Illuminate\Support\Str;

trait HasUuid
{
    public static function bootHasUuid(): void
    {
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public static function findByUuid(string $uuid): ?static
    {
        return static::where('uuid', $uuid)->first();
    }

    public static function findByUuidOrFail(string $uuid): static
    {
        return static::where('uuid', $uuid)->firstOrFail();
    }
}
