<?php
use Illuminate\Http\Request;
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$admin = \App\Models\User::where('role', 'admin')->first();
\Laravel\Sanctum\Sanctum::actingAs($admin, ['*']);

$request = Request::create('/api/v1/dashboard', 'GET');
$request->headers->set('Accept', 'application/json');
$response = $app->handle($request);
echo 'Status: ' . $response->getStatusCode() . "\n";
echo 'Body: ' . $response->getContent() . "\n";
