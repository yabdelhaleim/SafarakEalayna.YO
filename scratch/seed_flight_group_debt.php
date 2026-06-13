<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\FlightGroupTransaction;
use Illuminate\Support\Facades\DB;

try {
    // Check if the carrier already exists
    $carrier = FlightCarrier::where('code', 'TCR')->first();
    if (!$carrier) {
        $carrier = FlightCarrier::create([
            'name' => 'شركة طيران تجريبية',
            'code' => 'TCR',
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
        ]);
        echo "Created carrier '{$carrier->name}'\n";
    }

    // Check if the flight group already exists
    $group = FlightGroup::where('code', 'DTG')->first();
    if (!$group) {
        $group = FlightGroup::create([
            'flight_carrier_id' => $carrier->id,
            'name' => 'مجموعة الديون التجريبية',
            'code' => 'DTG',
            'is_active' => true,
        ]);
        echo "Created flight group '{$group->name}'\n";
    }

    // Delete existing transactions for this group to avoid duplicate seeding
    FlightGroupTransaction::where('flight_group_id', $group->id)->delete();

    // Create a debt (tickets purchase)
    FlightGroupTransaction::create([
        'flight_group_id' => $group->id,
        'type' => 'debt',
        'amount' => 15000.00,
        'notes' => 'شراء تذاكر طيران بالأجل لتجربة التقارير',
        'created_by' => 1,
    ]);

    // Create a payment
    FlightGroupTransaction::create([
        'flight_group_id' => $group->id,
        'type' => 'payment',
        'amount' => 5000.00,
        'notes' => 'سداد جزء من مديونية المجموعة',
        'created_by' => 1,
    ]);

    echo "Successfully seeded flight group transactions. Net balance should be -10000.00 (Payable).\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
