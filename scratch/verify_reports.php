<?php

use App\Services\Reports\FinancialReportService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$service = resolve(FinancialReportService::class);

echo "--- Profit By Module (Current Month) ---\n";
$profitRes = $service->getProfitByModule();
print_r($profitRes);

echo "\n--- Debts Report (tourism department, all directions) ---\n";
$debtsRes = $service->getDebtsReport([
    'department' => 'tourism',
    'direction' => 'all',
]);
echo "Total Receivables: {$debtsRes['total_receivables']}\n";
echo "Total Payables: {$debtsRes['total_payables']}\n";
echo "Net Balance: {$debtsRes['net_balance']}\n";
echo 'Items Count: '.count($debtsRes['items'])."\n";
foreach (array_slice($debtsRes['items'], 0, 10) as $item) {
    echo "- Name: {$item['name']}, Type: {$item['entity_type_label']}, Balance: {$item['balance']}\n";
}
