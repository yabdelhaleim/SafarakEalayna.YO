<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Helpers\ApiResponse;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->handle(Illuminate\Http\Request::capture()); // This ensures session/db are ready if needed, though we will override it.

function simulateRequest($method, $uri, $user = null, $data = []) {
    global $app;
    
    if ($user) {
        \Laravel\Sanctum\Sanctum::actingAs($user, ['*']);
    }
    
    $request = Request::create($uri, $method, $data);
    $request->headers->set('Accept', 'application/json');
    
    $response = $app->handle($request);
    return [
        'status' => $response->getStatusCode(),
        'content' => json_decode($response->getContent(), true)
    ];
}

$admin = User::where('role', 'admin')->first();
$employee = User::where('role', 'employee')->first();

echo "=== BACKEND SECURITY & PERFORMANCE AUDIT ===\n\n";

// 1. SECURITY TEST: Admin Endpoints
echo "1. Security Re-validation\n";

$testEndpoints = [
    ['GET', '/api/v1/dashboard', 'Dashboard'],
    ['GET', '/api/v1/finance/accounts', 'Finance Accounts'],
    ['GET', '/api/v1/reports/financial/summary', 'Financial Summary Report'],
    ['GET', '/api/v1/users', 'User Management'],
];

foreach ($testEndpoints as [$method, $uri, $name]) {
    echo "Testing $name ($uri):\n";
    
    // Unauthenticated
    $resp = simulateRequest($method, $uri);
    echo "  - Unauthenticated: " . ($resp['status'] === 401 ? "PASS (401)" : "FAIL ({$resp['status']})") . "\n";
    
    // Employee
    $resp = simulateRequest($method, $uri, $employee);
    echo "  - Employee: " . ($resp['status'] === 403 ? "PASS (403)" : "FAIL ({$resp['status']})") . "\n";
    
    // Admin
    $resp = simulateRequest($method, $uri, $admin);
    echo "  - Admin: " . ($resp['status'] === 200 ? "PASS (200)" : "FAIL ({$resp['status']})") . "\n";
}

// 2. PERFORMANCE TEST: Dashboard
echo "\n2. Performance Stress Test\n";

DB::enableQueryLog();
$start = microtime(true);
$resp = simulateRequest('GET', '/api/v1/dashboard', $admin);
$end = microtime(true);
$queries = count(DB::getQueryLog());
DB::disableQueryLog();

echo "Dashboard Response Time: " . round(($end - $start) * 1000, 2) . "ms\n";
echo "Dashboard Query Count: $queries\n";

if ($resp['status'] === 200) {
    echo "Dashboard Data Sample: " . (isset($resp['content']['data']['overview']) ? "VALID STRUCTURE" : "INVALID STRUCTURE") . "\n";
}

// 3. API STABILITY CHECK
echo "\n3. API Stability Check\n";
if (isset($resp['content']['status']) && isset($resp['content']['message']) && array_key_exists('data', $resp['content'])) {
    echo "JSON Structure (ApiResponse): PASS\n";
} else {
    echo "JSON Structure (ApiResponse): FAIL\n";
    print_r($resp['content']);
}

// 4. FILAMENT ISOLATION (Partial check)
echo "\n4. Filament Access Check\n";
$resp = simulateRequest('GET', '/admin', $employee);
echo "Employee access to /admin: " . ($resp['status'] === 403 || $resp['status'] === 302 ? "PASS (Restricted)" : "FAIL ({$resp['status']})") . "\n";

$resp = simulateRequest('GET', '/admin', $admin);
echo "Admin access to /admin: " . ($resp['status'] === 200 || $resp['status'] === 302 ? "PASS (Allowed)" : "FAIL ({$resp['status']})") . "\n";

echo "\n=== AUDIT COMPLETE ===\n";
