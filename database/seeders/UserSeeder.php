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

        // Admin user
        $adminId = 1;
        $adminUser = [
            'id' => $adminId,
            'name' => 'System Admin',
            'email' => 'admin@admin.com',
            'password' => Hash::make('11223311'),   
            'role' => 'admin',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('users')->updateOrInsert(['id' => $adminId], $adminUser);

        // Cache admin ID for other seeders
        cache(['seed_admin_id' => $adminId], now()->addHour());

        // Cache employee IDs (empty since no employees are seeded)
        cache(['seed_employee_ids' => []], now()->addHour());

        $this->command->info('✅ UserSeeder: Admin user created');
    }
}
