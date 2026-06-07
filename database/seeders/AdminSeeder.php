<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@safarakalayna.com',
            'password' => bcrypt('admin123'),
            'role' => 'admin',
            'is_active' => true,
        ]);
    }
}
