<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

try {
    $users = App\Models\User::all();
    echo "Total users: " . $users->count() . "\n";
    foreach ($users as $user) {
        echo "ID: {$user->id} | Email: {$user->email} | Role: {$user->role} | Active: " . ($user->is_active ? 'Yes' : 'No') . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
