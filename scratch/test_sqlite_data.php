<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Dynamically set connection to SQLite pointing to safarakealayna file
config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => base_path('safarakealayna'),
]);

// Purge connection to apply configuration changes
DB::purge('sqlite');
DB::reconnect('sqlite');

use App\Models\Customer;
use App\Models\Supplier;
use App\Models\Account;

echo "--- Connection status ---\n";
echo "Default Connection: " . DB::getDefaultConnection() . "\n";
echo "Database Path: " . DB::connection()->getDatabaseName() . "\n\n";

echo "Customer Count: " . Customer::count() . "\n";
echo "Supplier Count: " . Supplier::count() . "\n";
echo "Account Count: " . Account::count() . "\n";

echo "\n--- Accounts with non-zero balance ---\n";
$accounts = Account::where('balance', '!=', 0)->take(10)->get();
echo "Non-zero accounts count (sample): " . $accounts->count() . "\n";
foreach ($accounts as $acc) {
    echo sprintf(
        "ID: %d | Name: %s | Type: %s | ModType: %s | Balance: %s (%s)\n",
        $acc->id,
        $acc->name,
        $acc->account_type,
        $acc->module_type,
        $acc->balance,
        $acc->currency
    );
}

// Run the debts report
$service = app(\App\Services\Reports\FinancialReportService::class);
$reportOffice = $service->getDebtsReport(['department' => 'office']);
echo "\n=== OFFICE DEBTS ===\n";
echo "Total Receivables: " . $reportOffice['total_receivables'] . "\n";
echo "Total Payables: " . $reportOffice['total_payables'] . "\n";
echo "Items Count: " . count($reportOffice['items']) . "\n";

$receivablesSample = array_filter($reportOffice['items'], function($i) { return $i['balance'] > 0; });
echo "Receivables Count in Office: " . count($receivablesSample) . "\n";
foreach (array_slice($receivablesSample, 0, 10) as $item) {
    echo sprintf(
        "Name: %s | Type: %s | Dept: %s | Mod: %s | Balance: %s (%s)\n",
        $item['name'],
        $item['entity_type'],
        $item['department'],
        $item['module'],
        $item['balance'],
        $item['currency']
    );
}

$reportAll = $service->getDebtsReport([]);
echo "\n=== ALL DEBTS ===\n";
echo "Total Receivables: " . $reportAll['total_receivables'] . "\n";
echo "Total Payables: " . $reportAll['total_payables'] . "\n";
echo "Items Count: " . count($reportAll['items']) . "\n";

$allReceivables = array_filter($reportAll['items'], function($i) { return $i['balance'] > 0; });
echo "Total Receivables Count: " . count($allReceivables) . "\n";
foreach (array_slice($allReceivables, 0, 10) as $item) {
    echo sprintf(
        "Name: %s | Type: %s | Dept: %s | Mod: %s | Balance: %s (%s)\n",
        $item['name'],
        $item['entity_type'],
        $item['department'],
        $item['module'],
        $item['balance'],
        $item['currency']
    );
}
