<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$checks = [
    // Models
    ['FlightBooking', fn() => \App\Models\Flight\FlightBooking::count()],
    ['Customer',      fn() => \App\Models\Customer::count()],
    ['Airport',       fn() => \App\Models\Airport::count()],
    ['Account',       fn() => \App\Models\Account::count()],
    ['Employee',      fn() => \App\Models\Employee\Employee::count()],
    ['Invoice',       fn() => \App\Models\Invoice::count()],
    ['Treasury',      fn() => \App\Models\Treasury::count()],
    ['VisaBooking',   fn() => \App\Models\VisaBooking::count()],
    ['Supplier',      fn() => \App\Models\Supplier::count()],
];

foreach ($checks as [$name, $fn]) {
    try {
        $count = $fn();
        echo "✓ {$name}: {$count} records\n";
    } catch (\Throwable $e) {
        echo "✗ {$name}: " . $e->getMessage() . "\n";
    }
}

// Check tasks table (used in QuickStatsWidget)
try {
    $tasks = \Illuminate\Support\Facades\DB::table('tasks')->count();
    echo "✓ tasks table: {$tasks} records\n";
} catch (\Throwable $e) {
    echo "✗ tasks table: " . $e->getMessage() . "\n";
}

// Check transactions table
try {
    $txns = \Illuminate\Support\Facades\DB::table('transactions')->count();
    echo "✓ transactions table: {$txns} records\n";
} catch (\Throwable $e) {
    echo "✗ transactions table: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";
