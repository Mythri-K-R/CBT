<?php

namespace Database\Seeders;

use App\Models\PlatformSetting;
use Illuminate\Database\Seeder;

class PlatformSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            ['key' => 'platform_name',         'value' => 'ExamSphere',           'type' => 'string',  'desc' => 'Platform display name'],
            ['key' => 'platform_tagline',       'value' => 'Smart CBT for Smarter Results', 'type' => 'string', 'desc' => 'Tagline'],
            ['key' => 'support_email',          'value' => 'support@examsphere.in','type' => 'string',  'desc' => 'Support email'],
            ['key' => 'maintenance_mode',       'value' => '0',                    'type' => 'boolean', 'desc' => 'Maintenance mode flag'],
            ['key' => 'allow_self_registration','value' => '1',                    'type' => 'boolean', 'desc' => 'Allow institutions to self-register for trial'],
            ['key' => 'default_timer_sync_sec', 'value' => '30',                   'type' => 'integer', 'desc' => 'Timer sync interval in seconds'],
            ['key' => 'max_questions_per_test', 'value' => '360',                  'type' => 'integer', 'desc' => 'Maximum questions in a single test'],
        ];

        foreach ($settings as $s) {
            PlatformSetting::updateOrCreate(
                ['setting_key' => $s['key']],
                ['setting_value' => $s['value'], 'setting_type' => $s['type'], 'description' => $s['desc']]
            );
        }
    }
}
