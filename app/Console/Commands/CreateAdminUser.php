<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class CreateAdminUser extends Command
{
    protected $signature = 'admin:create {email=admin@travel.com} {password=Admin@2024}';
    protected $description = 'Create an admin user for Filament';

    public function handle()
    {
        $email = $this->argument('email');
        $password = $this->argument('password');
        
        $existing = User::where('email', $email)->first();
        if ($existing) {
            if ($existing->role === 'admin') {
                $this->info("✓ Admin user already exists:");
                $this->info("  Email: {$email}");
                $this->info("  Name: {$existing->name}");
                return 0;
            } else {
                $existing->update(['role' => 'admin']);
                $this->info("✓ User updated to admin:");
                $this->info("  Email: {$email}");
                return 0;
            }
        }
        
        $admin = User::create([
            'name' => 'مدير النظام',
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'admin',
            'is_active' => true,
        ]);
        
        $this->info("✓ Admin user created successfully!");
        $this->info("  Email: {$email}");
        $this->info("  Password: {$password}");
        $this->info("  Name: {$admin->name}");
        $this->info("\nLogin at: http://localhost:8000/admin");
        return 0;
    }
}