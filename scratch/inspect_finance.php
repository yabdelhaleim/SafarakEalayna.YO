<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Account;
use App\Models\Transaction;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Reports\ProfitLossReportService;
use App\Services\Reports\ReportFinanceService;

echo "--- Clearing Account Maps ---\n";
$clearing = app(LedgerClearingAccounts::class);
$maps = $clearing->moduleAccountMaps();
echo "Income Clearing Accounts:\n";
foreach ($maps['income'] as $id => $mod) {
    $acc = Account::find($id);
    echo "  ID: {$id}, Module: {$mod}, Name: " . ($acc ? $acc->name : 'N/A') . ", Active: " . ($acc ? $acc->is_active : 'N/A') . "\n";
}
echo "Expense Clearing Accounts:\n";
foreach ($maps['expense'] as $id => $mod) {
    $acc = Account::find($id);
    echo "  ID: {$id}, Module: {$mod}, Name: " . ($acc ? $acc->name : 'N/A') . ", Active: " . ($acc ? $acc->is_active : 'N/A') . "\n";
}

echo "\n--- Last 5 Transactions ---\n";
$txs = Transaction::latest('id')->take(10)->get();
foreach ($txs as $tx) {
echo "ID: {$tx->id}, Type: " . (is_object($tx->type) ? $tx->type->value : $tx->type) . ", Module: " . (is_object($tx->module) ? $tx->module->value : $tx->module) . ", Amount: {$tx->amount}, From: {$tx->from_account_id}, To: {$tx->to_account_id}, Notes: {$tx->notes}\n";
}

echo "\n--- Profit & Loss / Financial Summary --- \n";
$reportService = app(\App\Services\Reports\FinancialReportService::class);
$plReportService = app(\App\Services\Reports\ProfitLossReportService::class);

$filters = [
    'from_date' => now()->subDays(30)->toDateString(),
    'to_date' => now()->toDateString(),
];

try {
    $summary = $reportService->getFinancialSummary($filters);
    echo "FinancialReportService getFinancialSummary:\n";
    print_r($summary);
} catch (\Exception $e) {
    echo "Error in getFinancialSummary: " . $e->getMessage() . "\n";
}

try {
    $plReport = $plReportService->report($filters);
    echo "\nPL Report:\n";
    print_r($plReport);
} catch (\Exception $e) {
    echo "Error in PL report: " . $e->getMessage() . "\n";
}
