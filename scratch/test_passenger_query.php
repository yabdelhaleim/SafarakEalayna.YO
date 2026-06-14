<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Passenger;

use App\Http\Controllers\Api\V1\Flight\PassengerController;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;

try {
    echo "Running passenger full HTTP request...\n";
    
    // Resolve HTTP Kernel
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    
    $request = Request::create('/api/v1/flight/passengers', 'GET', [
        'trip_status' => 'upcoming',
        'search' => '',
        'departure_date_from' => '',
        'departure_date_to' => '',
        'per_page' => 15,
    ]);
    
    // Authenticate user ID 1
    $user = \App\Models\User::find(1);
    if ($user) {
        $request->setUserResolver(fn () => $user);
        Sanctum::actingAs($user, ['*']);
    }
    
    $response = $kernel->handle($request);
    
    echo "Response status: " . $response->getStatusCode() . "\n";
    echo "Response body: " . substr($response->getContent(), 0, 2000) . "\n";
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
