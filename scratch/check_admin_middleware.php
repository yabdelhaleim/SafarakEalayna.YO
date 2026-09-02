<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

$user = User::where('role', 'user')->orWhere('is_admin', false)->first() ?? User::create(['name' => 'Regular User', 'email' => 'regular@user.com', 'password' => bcrypt('password')]);

echo "User ID: {$user->id}\n";
echo 'User role: '.($user->role ?? 'N/A')."\n";
echo 'User is_admin: '.(var_export($user->is_admin ?? null, true))."\n";
