<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

use Illuminate\Http\Request;

try {
    $request = Request::create('/api/v1/auth/login', 'POST', [
        'email' => 'admin@admin.com',
        'password' => '11223311',
    ]);
    
    $response = $app->handle($request);
    echo "Status code: " . $response->getStatusCode() . "\n";
    echo "Content: " . $response->getContent() . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
