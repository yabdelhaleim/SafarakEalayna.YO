<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\FlightGroupTransaction;
use App\Services\Reports\FinancialReportService;
use Illuminate\Support\Facades\DB;

DB::beginTransaction();

try {
    // 1. Create a Carrier
    $carrier = FlightCarrier::create([
        'name' => 'Test Carrier',
        'code' => 'TCR',
        'currency' => 'EGP',
        'balance' => 0,
        'is_active' => true,
    ]);

    // 2. Create a Flight Group
    $group = FlightGroup::create([
        'flight_carrier_id' => $carrier->id,
        'name' => 'Debts Test Group',
        'code' => 'DTG',
        'is_active' => true,
    ]);

    // 3. Create transactions
    // Debt: we bought a ticket for 1000
    FlightGroupTransaction::create([
        'flight_group_id' => $group->id,
        'type' => 'debt',
        'amount' => 1000.00,
        'created_by' => 1,
    ]);

    // Payment: we paid 400
    FlightGroupTransaction::create([
        'flight_group_id' => $group->id,
        'type' => 'payment',
        'amount' => 400.00,
        'created_by' => 1,
    ]);

    // Total net balance = 400 - 1000 = -600 (payable)

    echo "Mock Flight Group and transactions created.\n";

    // 4. Run Debts Report
    $service = app(FinancialReportService::class);
    $report = $service->getDebtsReport([
        'department' => 'tourism',
        'direction' => 'all',
    ]);

    echo "Total items in report: " . count($report['items']) . "\n";
    $found = false;
    foreach ($report['items'] as $item) {
        if ($item['name'] === 'Debts Test Group') {
            $found = true;
            echo "SUCCESS: Found Debts Test Group in report!\n";
            echo json_encode($item, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        }
    }

    if (!$found) {
        echo "FAILED: Debts Test Group NOT found in report!\n";
    }

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
} finally {
    DB::rollBack();
}
