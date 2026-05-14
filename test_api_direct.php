<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Http\Kernel');

$request = Illuminate\Http\Request::create('/api/v1/customers', 'POST');
$request->headers->set('Content-Type', 'application/json');
$request->headers->set('Accept', 'application/json');
$request->headers->set('Authorization', 'Bearer 8|Oqs6qB6pTXiIJ5mlEMvhoZRn1u0CqkREzLDsQe8Y8eebf032');
$data = [
    'full_name' => 'Test User',
    'phone' => '050' . rand(10000000, 99999999),
];
$request->merge($data);

try {
    $response = $kernel->handle($request);
    echo "Status: " . $response->getStatusCode() . "\n";
    echo "Content: " . $response->getContent() . "\n";
    $kernel->terminate($request, $response);
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}
