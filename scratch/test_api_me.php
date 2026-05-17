<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

use Illuminate\Http\Request;
use App\Models\User;

try {
    $user = User::find(2); // admin@admin.com
    $token = $user->createToken('test-token')->plainTextToken;
    echo "Generated Token: " . $token . "\n";
    
    $request = Request::create('/api/v1/auth/me', 'GET');
    $request->headers->set('Authorization', 'Bearer ' . $token);
    $request->headers->set('Accept', 'application/json');
    
    $response = $app->handle($request);
    echo "Status code: " . $response->getStatusCode() . "\n";
    echo "Content: " . $response->getContent() . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
