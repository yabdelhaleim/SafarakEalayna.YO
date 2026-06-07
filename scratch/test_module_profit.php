<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Services\Finance\LedgerClearingAccounts;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$fromDate = '2020-01-01';
$toDate = '2030-01-01';

$clearingService = resolve(LedgerClearingAccounts::class);

$incomeClearingIds = [];
$expenseClearingIds = [];

$modulesKeys = ['flight', 'bus', 'hajj_umra', 'visa', 'online', 'fawry', 'wallet', 'general'];
foreach ($modulesKeys as $mod) {
    $incId = $clearingService->incomeContraIdForModule($mod);
    if ($incId) {
        $incomeClearingIds[$incId] = $mod;
    }
    $expId = $clearingService->expenseContraIdForModule($mod);
    if ($expId) {
        $expenseClearingIds[$expId] = $mod;
    }
}

$flightIncId = $clearingService->incomeContraIdForFlightBooking();
if ($flightIncId) {
    $incomeClearingIds[$flightIncId] = 'flight';
}

$query = DB::table('transactions')
    ->leftJoin('accounts as to_account', 'transactions.to_account_id', '=', 'to_account.id')
    ->select(
        'transactions.type',
        'transactions.module',
        'transactions.amount',
        'transactions.from_account_id',
        'transactions.to_account_id',
        'to_account.type as to_account_type',
        'to_account.name as to_account_name'
    )
    ->whereBetween('transactions.created_at', [$fromDate, $toDate]);

$transactions = $query->get();

$byModule = [];

foreach ($transactions as $tx) {
    $amount = (float) $tx->amount;
    $module = $tx->module ?: 'general';

    $isRevenue = false;
    $isExpense = false;

    if ($tx->type === 'income') {
        $isRevenue = true;
    } elseif ($tx->type === 'transfer' && array_key_exists($tx->from_account_id, $incomeClearingIds)) {
        $isRevenue = true;
        if ($module === 'general' || empty($module)) {
            $module = $incomeClearingIds[$tx->from_account_id];
        }
    } elseif ($tx->type === 'transfer' && array_key_exists($tx->to_account_id, $expenseClearingIds)) {
        $isExpense = true;
        if ($module === 'general' || empty($module)) {
            $module = $expenseClearingIds[$tx->to_account_id];
        }
    } elseif ($tx->type === 'expense' || $tx->to_account_type === 'expense') {
        $isExpense = true;
    } elseif ($tx->type === 'transfer' && $tx->to_account_type === 'expense') {
        $isExpense = true;
    }

    if (! isset($byModule[$module])) {
        $byModule[$module] = ['income' => 0.0, 'expense' => 0.0];
    }

    if ($isRevenue) {
        $byModule[$module]['income'] += $amount;
    } elseif ($isExpense) {
        $byModule[$module]['expense'] += $amount;
    }
}

print_r($byModule);
