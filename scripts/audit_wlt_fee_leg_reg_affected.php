<?php

/**
 * AUDIT (read-only) — Affected customer AR accounts by the WLT-FEE-LEG-REG bug.
 *
 * Background (pre-fix bug):
 *   The registered-customer SEND path posted the customer debit
 *   for the FULL `amount_paid` (= amount + fee) on settlement,
 *   while only crediting `amount`. Net = -fee (a payable for the customer
 *   instead of a receivable for the agency).
 *
 *   Affected transactions: registered-customer SENDs whose customer AR
 *   mirror ended at -fee AFTER full settlement (i.e. amount_paid == total).
 *
 * This script audits the database and PRINTS the affected rows. It does NOT
 * mutate anything. Run with:
 *
 *   php scripts/audit_wlt_fee_leg_reg_affected.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Support\Facades\DB;

echo "=== WLT-FEE-LEG-REG AUDIT ===\n";
echo "Migrated by commit 263f7e2 — code fix landed.\n";
echo "This script checks for OLD data where the bug produced wrong entries.\n";
echo str_repeat('=', 60) . "\n\n";

// 1. Find all customer AR accounts in the wallet module.
$customerAccounts = Account::where('type', AccountType::Customer->value)
    ->where('module_type', 'wallet_transfer')
    ->get();

echo "Customer AR accounts (module_type=wallet_transfer): "
    . $customerAccounts->count() . "\n\n";

if ($customerAccounts->isEmpty()) {
    echo "No customer accounts to audit. Done.\n";
    exit(0);
}

// 2. For each, sum debit/credit from wallet-module transactions only
//    (matches the dashboard's customers_debt query).
$rows = DB::table('account_entries as ae')
    ->join('transactions as t', 'ae.transaction_id', '=', 't.id')
    ->whereIn('ae.account_id', $customerAccounts->pluck('id'))
    ->where('t.module', 'wallet')
    ->groupBy('ae.account_id')
    ->selectRaw('ae.account_id, SUM(ae.credit) AS total_credit, SUM(ae.debit) AS total_debit, COUNT(*) AS entry_count')
    ->get();

$affected = 0;
$totalWrongBalance = 0.0;

echo str_pad('Account', 8) . str_pad('Name', 38) . str_pad('Credit', 12) . str_pad('Debit', 12) . str_pad('Net', 10) . str_pad('Entries', 8) . "\n";
echo str_repeat('-', 88) . "\n";

foreach ($rows as $r) {
    $net = (float) $r->total_credit - (float) $r->total_debit;

    // An account is "affected" if its net balance is NEGATIVE — i.e.
    // it has more debits than credits from wallet transactions. For
    // a customer AR mirror, a NEGATIVE balance means we owe the customer,
    // which in the pre-fix world is what -fee looks like after full settlement.
    if ($net < -0.001) {
        $affected++;
        $totalWrongBalance += $net;

        $name = Account::find($r->account_id)?->name ?? '?';
        echo str_pad((string) $r->account_id, 8)
            . str_pad(mb_substr($name, 0, 36), 38)
            . str_pad(number_format((float) $r->total_credit, 2), 12)
            . str_pad(number_format((float) $r->total_debit, 2), 12)
            . str_pad(number_format($net, 2), 10)
            . str_pad((string) $r->entry_count, 8) . "\n";
    }
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "Affected customer accounts (net < 0): {$affected}\n";
echo "Sum of negative balances (المكشوف/المستحق للعميل): " . number_format($totalWrongBalance, 2) . " EGP\n";
echo "\nEach -X entry means: customer balance reflects the pre-fix bug\n";
echo "(settlement debited 'amount_paid' instead of 'amount').\n";
echo "\nFix option (NOT auto-applied): run scripts/fix_wlt_fee_leg_reg_data.php\n";
echo "after review. Back up your DB first.\n";
