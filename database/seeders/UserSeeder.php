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
            ['email' => 'admin@safarakealayna.com'],
            [
                'name' => 'System Admin',
                'email' => 'admin@safarakealayna.com',
                'password' => Hash::make('Sf@2026#Admin!'),
                'role' => 'admin',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $this->command->info('✅ UserSeeder: تم إنشاء حساب المدير.');
    }
}
