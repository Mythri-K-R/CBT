<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PlatformSettingsSeeder::class,
            SubjectSeeder::class,
            ExamTemplateSeeder::class,
            SuperAdminSeeder::class,
            InstitutionSeeder::class,
            QuestionSeeder::class,
            TestSeeder::class,
            DemoTestSeeder::class,
        ]);
    }
}
