<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [];
        $employees = [];
        $now = now();

        // Admin user
        $adminId = 1;
        $users[] = [
            'id' => $adminId,
            'name' => 'System Admin',
            'email' => 'admin@admin.com',
            'password' => Hash::make('11223311'),   
            'role' => 'admin',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // 10 employee users with linked employee records
        $firstNames = ['Ahmed', 'Mohamed', 'Ali', 'Hassan', 'Omar', 'Khaled', 'Mahmoud', 'Youssef', 'Karim', 'Tarek'];
        $lastNames = ['Ibrahim', 'Mohamed', 'Ali', 'Hassan', 'Omar', 'Khaled', 'Mahmoud', 'Youssef', 'Karim', 'Tarek'];

        for ($i = 1; $i <= 10; $i++) {
            $userId = $i + 1; // Start from ID 2
            $firstName = $firstNames[$i - 1];
            $lastName = $lastNames[$i - 1];

            $users[] = [
                'id' => $userId,
                'name' => "{$firstName} {$lastName}",   
                'email' => "employee{$i}@office.com",
                'password' => Hash::make('password'),
                'role' => 'employee',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $employees[] = [
                'id' => $i,
                'user_id' => $userId,
                'salary' => rand(300000, 800000) / 100, // 3000-8000 as decimal
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Clear existing to avoid duplicate key errors during concurrent requests
        // Using safe updateOrInsert to prevent duplicate primary keys
        foreach ($users as $user) {
            DB::table('users')->updateOrInsert(['id' => $user['id']], $user);
        }

        // Insert employees
        foreach ($employees as $employee) {
            DB::table('employees')->updateOrInsert(['id' => $employee['id']], $employee);
        }

        // Cache admin ID for other seeders
        cache(['seed_admin_id' => $adminId], now()->addHour());

        // Cache employee IDs
        cache(['seed_employee_ids' => range(1, 10)], now()->addHour());

        $this->command->info('✅ UserSeeder: 11 users created (1 admin + 10 employees)');
    }
}
