<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    echo "DB Connection: " . DB::connection()->getDatabaseName() . "\n";
    echo "Flight Carriers: " . DB::table('flight_carriers')->count() . "\n";
    echo "Flight Groups: " . DB::table('flight_groups')->count() . "\n";
    echo "Flight Bookings: " . DB::table('flight_bookings')->count() . "\n";
    echo "Flight Group Transactions: " . DB::table('flight_group_transactions')->count() . "\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
