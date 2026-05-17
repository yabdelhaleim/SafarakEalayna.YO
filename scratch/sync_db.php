<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

function renameColumnIfExist($table, $from, $to) {
    if (Schema::hasTable($table) && Schema::hasColumn($table, $from) && !Schema::hasColumn($table, $to)) {
        echo "Renaming $table.$from to $to...\n";
        Schema::table($table, function ($tableObj) use ($from, $to) {
            $tableObj->renameColumn($from, $to);
        });
        echo "Done.\n";
    }
}

echo "Starting Schema Normalization...\n";

// Flight Payments
renameColumnIfExist('flight_payments', 'booking_id', 'flight_booking_id');
renameColumnIfExist('flight_payments', 'method', 'payment_method');

// Flight Segments
renameColumnIfExist('flight_segments', 'booking_id', 'flight_booking_id');

// Flight Bookings (check if any old names exist)
renameColumnIfExist('flight_bookings', 'booking_reference', 'booking_number');
renameColumnIfExist('flight_bookings', 'airline', 'airline_name');
renameColumnIfExist('flight_bookings', 'origin', 'from_airport');
renameColumnIfExist('flight_bookings', 'destination', 'to_airport');

echo "Schema Normalization Finished.\n";

echo "Now running auto_migrate.php to fix pending migrations...\n";
include __DIR__.'/auto_migrate.php';
