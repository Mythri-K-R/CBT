<?php

namespace Database\Seeders;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class InstitutionSeeder extends Seeder
{
    public function run(): void
    {
        $institution = Institution::firstOrCreate(
            ['code' => 'SAI001'],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'Sai PU College',
                'slug' => 'sai-pu-college',
                'type' => 'pu_college',
                'contact_person' => 'Dr. Ramesh Kumar',
                'email' => 'contact@sai001.in',
                'phone' => '+91 98450 12345',
                'city' => 'Bangalore',
                'state' => 'Karnataka',
                'plan' => 'growth',
                'student_limit' => 500,
                'subscription_start' => now(),
                'subscription_end' => now()->addYears(1),
                'is_active' => true,
            ]
        );

        User::firstOrCreate(
            ['username' => 'admin_sai001'],
            [
                'uuid'                  => (string) Str::uuid(),
                'institution_id'        => $institution->id,
                'role'                  => 'institution_admin',
                'name'                  => 'Sai PU College Admin',
                'email'                 => 'admin@sai001.in',
                'username'              => 'admin_sai001',
                'password'              => Hash::make('admin123'),
                'is_active'             => true,
                'force_password_change' => false,
            ]
        );

        User::firstOrCreate(
            ['username' => 'faculty1_sai001'],
            [
                'uuid'                  => (string) Str::uuid(),
                'institution_id'        => $institution->id,
                'role'                  => 'faculty',
                'name'                  => 'Dr. Rajesh Kumar',
                'email'                 => 'faculty1@sai001.in',
                'username'              => 'faculty1_sai001',
                'password'              => Hash::make('faculty123'),
                'is_active'             => true,
                'force_password_change' => false,
            ]
        );

        $batch1 = \App\Models\Batch::create([
            'institution_id' => $institution->id,
            'name'           => 'NEET 2026 Droppers',
            'exam_type'      => 'NEET',
            'batch_type'     => 'Dropper',
            'target_year'    => 2026,
            'start_date'     => now()->subMonths(2),
            'max_students'   => 100,
            'status'         => 'active',
            'is_active'      => true,
        ]);

        $batch2 = \App\Models\Batch::create([
            'institution_id' => $institution->id,
            'name'           => 'JEE 2026 Mains',
            'exam_type'      => 'JEE Main',
            'batch_type'     => 'Regular',
            'target_year'    => 2026,
            'start_date'     => now()->subMonths(1),
            'max_students'   => 120,
            'status'         => 'active',
            'is_active'      => true,
        ]);

        // Create some students and attach them to batches
        $faker = \Faker\Factory::create();
        for ($i = 1; $i <= 10; $i++) {
            $student = \App\Models\Student::create([
                'institution_id' => $institution->id,
                'name'           => $faker->name,
                'roll_number'    => 'NEET26' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'phone'          => $faker->numerify('##########'),
                'parent_phone'   => $faker->numerify('##########'),
                'is_active'      => true,
            ]);
            $batch1->students()->attach($student->id, ['enrolled_at' => now(), 'status' => 'active']);
        }

        for ($i = 1; $i <= 15; $i++) {
            $student = \App\Models\Student::create([
                'institution_id' => $institution->id,
                'name'           => $faker->name,
                'roll_number'    => 'JEE26' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'phone'          => $faker->numerify('##########'),
                'parent_phone'   => $faker->numerify('##########'),
                'is_active'      => true,
            ]);
            $batch2->students()->attach($student->id, ['enrolled_at' => now(), 'status' => 'active']);
        }

        $this->command->info('Institution SAI001 created with Admin, Faculty, Batches, and Students.');
    }
}
