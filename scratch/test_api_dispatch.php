<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

$admin = User::first() ?? User::create(['name' => 'Admin Test', 'email' => 'admin@test.com', 'password' => bcrypt('password')]);
$token = $admin->createToken('test-token')->plainTextToken;

echo "Admin User ID: {$admin->id}\n";
echo "Sanctum Token Created: {$token}\n";

// Test HTTP API request dispatch
$request = Request::create('/api/v1/bus/companies', 'GET');
$request->headers->set('Authorization', 'Bearer '.$token);
$request->headers->set('Accept', 'application/json');

$response = $app->handle($request);
echo 'API Response Status Code: '.$response->getStatusCode()."\n";
echo 'API Response Body snippet: '.substr($response->getContent(), 0, 200)."\n";
