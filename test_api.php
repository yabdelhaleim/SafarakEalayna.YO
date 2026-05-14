<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Http;
use App\Models\User;

try {
    // 1. Get or create user
    $user = User::first();
    if (!$user) {
        $user = User::forceCreate([
            'name' => 'Admin Test',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'is_active' => 1,
            'role' => 'admin'
        ]);
    } else {
        // Ensure user is active to pass the 'active' middleware
        $user->is_active = 1;
        $user->role = 'admin';
        $user->save();
    }
    
    // Create Token
    $token = $user->createToken('test-token')->plainTextToken;
    echo "✅ Token generated successfully for user {$user->email}\n";

    $baseUrl = 'http://127.0.0.1:8000/api';
    
    // 2. Test Flights API
    echo "\n--- ✈️ Testing Flights API ---\n";
    $flightResponse = Http::withToken($token)->acceptJson()->get("$baseUrl/v1/flight/bookings");
    echo "GET /api/v1/flight/bookings -> Status: " . $flightResponse->status() . "\n";
    if ($flightResponse->successful()) {
        echo "Response: " . substr(json_encode($flightResponse->json(), JSON_UNESCAPED_UNICODE), 0, 200) . "...\n";
    } else {
        echo "Error Response: " . json_encode($flightResponse->json(), JSON_UNESCAPED_UNICODE) . "\n";
    }

    $createFlight = Http::withToken($token)->acceptJson()->post("$baseUrl/v1/flight/bookings", [
        'customer_id' => 1,
        'booking_number' => 'TEST-' . rand(1000, 9999),
        'system_type' => 'Manual',
        'status' => 'pending',
    ]);
    echo "POST /api/v1/flight/bookings -> Status: " . $createFlight->status() . "\n";
    echo "Response: " . json_encode($createFlight->json(), JSON_UNESCAPED_UNICODE) . "\n";

    // 3. Test Online Services API
    echo "\n--- 🌐 Testing Online Services API ---\n";
    $typesResponse = Http::withToken($token)->acceptJson()->get("$baseUrl/v1/online/service-types");
    echo "GET /api/v1/online/service-types -> Status: " . $typesResponse->status() . "\n";
    if ($typesResponse->successful()) {
         echo "Response: " . substr(json_encode($typesResponse->json(), JSON_UNESCAPED_UNICODE), 0, 200) . "...\n";
    } else {
        echo "Error Response: " . json_encode($typesResponse->json(), JSON_UNESCAPED_UNICODE) . "\n";
    }
    
    $createType = Http::withToken($token)->acceptJson()->post("$baseUrl/v1/online/service-types", [
        'name' => 'خدمة تجريبية ' . rand(1, 100),
        'category' => 'other',
        'provider' => 'test',
        'cost_price' => 10,
        'selling_price' => 20,
        'is_active' => true
    ]);
    echo "POST /api/v1/online/service-types -> Status: " . $createType->status() . "\n";
    echo "Response: " . json_encode($createType->json(), JSON_UNESCAPED_UNICODE) . "\n";

    // 4. Test Services API
    echo "\n--- 🛎️ Testing Services API ---\n";
    $servicesResponse = Http::withToken($token)->acceptJson()->get("$baseUrl/v1/service/services");
    echo "GET /api/v1/service/services -> Status: " . $servicesResponse->status() . "\n";
    if ($servicesResponse->successful()) {
         echo "Response: " . substr(json_encode($servicesResponse->json(), JSON_UNESCAPED_UNICODE), 0, 200) . "...\n";
    } else {
        echo "Error Response: " . json_encode($servicesResponse->json(), JSON_UNESCAPED_UNICODE) . "\n";
    }

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
