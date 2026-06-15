<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class PlatformSetting extends Model
{
    public $timestamps = false;

    protected $fillable = ['setting_key', 'setting_value', 'setting_type', 'description'];

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember("platform_setting_{$key}", 3600, function () use ($key, $default) {
            $setting = static::where('setting_key', $key)->first();
            if (!$setting) return $default;

            return match ($setting->setting_type) {
                'integer' => (int) $setting->setting_value,
                'boolean' => (bool) $setting->setting_value,
                'json'    => json_decode($setting->setting_value, true),
                default   => $setting->setting_value,
            };
        });
    }

    public static function set(string $key, mixed $value, string $type = 'string'): void
    {
        static::updateOrCreate(
            ['setting_key' => $key],
            ['setting_value' => is_array($value) ? json_encode($value) : (string) $value, 'setting_type' => $type]
        );
        Cache::forget("platform_setting_{$key}");
    }
}
