<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        DB::table('users')->updateOrInsert(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'System Admin',
                'email' => 'admin@admin.com',
                'password' => Hash::make('11223311'),
                'role' => 'admin',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $this->command->info('✅ UserSeeder: حساب الدخول الافتراضي — admin@admin.com / 11223311');
    }
}
