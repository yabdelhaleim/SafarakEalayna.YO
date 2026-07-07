<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$service = app(\App\Services\Reports\FinancialReportService::class);

echo "=== OFFICE DEBTS ===\n";
$reportOffice = $service->getDebtsReport(['department' => 'office']);
echo "Total Receivables: " . $reportOffice['total_receivables'] . "\n";
echo "Total Payables: " . $reportOffice['total_payables'] . "\n";
echo "Items Count: " . count($reportOffice['items']) . "\n\n";

echo "=== TOURISM DEBTS ===\n";
$reportTourism = $service->getDebtsReport(['department' => 'tourism']);
echo "Total Receivables: " . $reportTourism['total_receivables'] . "\n";
echo "Total Payables: " . $reportTourism['total_payables'] . "\n";
echo "Items Count: " . count($reportTourism['items']) . "\n\n";

echo "=== ALL DEBTS ===\n";
$reportAll = $service->getDebtsReport([]);
echo "Total Receivables: " . $reportAll['total_receivables'] . "\n";
echo "Total Payables: " . $reportAll['total_payables'] . "\n";
echo "Items Count: " . count($reportAll['items']) . "\n\n";

// Show first 10 items in All Debts
foreach (array_slice($reportAll['items'], 0, 10) as $item) {
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
