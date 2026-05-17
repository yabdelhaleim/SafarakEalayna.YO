<?php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Schema;

$tables = ['flight_payments', 'flight_bookings', 'flight_segments', 'passengers'];
foreach ($tables as $t) {
    echo "Table: $t\n";
    if (Schema::hasTable($t)) {
        print_r(Schema::getColumnListing($t));
    } else {
        echo "Table does not exist!\n";
    }
    echo "-------------------\n";
}
