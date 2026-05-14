<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

// Authenticate as admin
$admin = User::first();
if (!$admin) {
    die("No admin user found!");
}
Auth::login($admin);

// Get a customer
$customer = Customer::first();
if (!$customer) {
    $customer = Customer::create([
        'full_name' => 'Test Customer',
        'phone' => '0123456789',
        'created_by' => $admin->id,
    ]);
    echo "Created test customer: ID {$customer->id}\n";
}

// Simulate payload
$payload = [
    'customer_id' => $customer->id,
    'system_type' => 'manual',
    'airline_name' => 'Test Airline',
    'from_airport' => 'CAI',
    'to_airport' => 'JED',
    'departure_date' => '2026-05-01',
    'departure_time' => '14:00',
    'arrival_time' => '18:00',
    'purchase_price' => 4000,
    'selling_price' => 5000,
    'currency' => 'EGP',
    'notes' => 'Test booking',
    'passengers' => [
        ['name' => 'Test Passenger', 'passenger_type' => 'adult']
    ],
    'segments' => [
        ['airline_name' => 'Test Airline', 'flight_number' => 'TX123', 'from_airport' => 'CAI', 'to_airport' => 'JED', 'departure_time' => '14:00', 'arrival_time' => '18:00']
    ]
];

try {
    $service = $app->make(\App\Services\Flight\FlightBookingService::class);
    $booking = $service->createBooking($payload);
    echo "Success! Created booking: ID {$booking->id}, Number {$booking->booking_number}\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
