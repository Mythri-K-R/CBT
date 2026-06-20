<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['username' => 'superadmin'],
            [
                'uuid'                  => (string) Str::uuid(),
                'institution_id'        => null,
                'role'                  => 'super_admin',
                'name'                  => 'ExamSphere Admin',
                'email'                 => env('SUPER_ADMIN_EMAIL', 'admin@examsphere.in'),
                'username'              => 'superadmin',
                'password'              => Hash::make(env('SUPER_ADMIN_PASSWORD', 'change-me-immediately')),
                'is_active'             => true,
                'force_password_change' => true,
            ]
        );

        $this->command->info('Super admin created. Username: superadmin');
    }
}
