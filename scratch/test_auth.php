<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

$user = App\Models\User::where('email', 'admin@admin.com')->first();
echo "USER FOUND: " . ($user ? $user->email : 'NO') . PHP_EOL;
if ($user) {
    echo "PASSWORD CHECK 11223311: " . (Illuminate\Support\Facades\Hash::check('11223311', $user->password) ? 'OK' : 'BAD') . PHP_EOL;
    echo "PASSWORD CHECK password: " . (Illuminate\Support\Facades\Hash::check('password', $user->password) ? 'OK' : 'BAD') . PHP_EOL;
}

$employee = App\Models\User::where('email', 'employee1@office.com')->first();
echo "EMPLOYEE FOUND: " . ($employee ? $employee->email : 'NO') . PHP_EOL;
if ($employee) {
    echo "PASSWORD CHECK password: " . (Illuminate\Support\Facades\Hash::check('password', $employee->password) ? 'OK' : 'BAD') . PHP_EOL;
}
