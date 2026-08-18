<?php
require_once __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Program;
use App\Support\UserPermissions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

// Mimic what the test does
$admin = User::query()->create([
    'name' => 'DBG_Admin_'.uniqid(),
    'email' => 'dbg_admin_'.uniqid('', true).'@dbg.local',
    'password' => Hash::make('x'),
    'role' => 'admin',
    'is_active' => true,
    'permissions' => [],
]);

// Defer to real test instead
echo "See test_a01 output\n";
