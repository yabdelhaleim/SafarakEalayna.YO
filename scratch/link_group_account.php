<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Account;
use App\Models\Flight\FlightGroup;

try {
    $group = FlightGroup::where('code', 'DTG')->first();
    if (!$group) {
        throw new \Exception("Flight group DTG not found. Please run seed_flight_group_debt.php first.");
    }
    
    // Create a supplier account if not already linked
    if (!$group->account_id) {
        $account = Account::create([
            'name' => 'حساب مجموعة الديون التجريبية',
            'type' => 'supplier',
            'currency' => 'EGP',
            'balance' => -10000.00, // Matches seeded transactions
            'is_active' => true,
            'owner_type' => 'office',
        ]);
        $group->account_id = $account->id;
        $group->save();
        echo "Created and linked account '{$account->name}' (ID: {$account->id}) to flight group.\n";
    } else {
        echo "Flight group is already linked to account ID: {$group->account_id}\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
