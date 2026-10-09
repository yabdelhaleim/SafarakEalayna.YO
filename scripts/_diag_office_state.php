<?php

/**
 * READ-ONLY DIAGNOSTIC: Office Liquidity Accounts
 * ──────────────────────────────────────────────────
 * Purpose: Compare each office cashbox/bank/wallet's DISPLAYED balance
 *          against the CALCULATED balance from its AccountEntry rows.
 *
 * NO WRITES. NO UPDATES. This script only SELECTs.
 *
 * Usage:
 *   php scripts/_diag_office_state.php
 *
 * Output:
 *   - List of all office liquidity accounts
 *   - For each: current balance, sum(credit-debit) from entries, delta
 *   - Total drift across all office accounts
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

echo "═══════════════════════════════════════════════════════════════\n";
echo " DIAGNOSTIC (READ-ONLY): Office Liquidity Accounts State\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo ' Timestamp: '.now()->toDateTimeString()."\n";
echo ' Environment: '.app()->environment()."\n\n";

// 1) List all office liquidity accounts
echo "┌─ [1] OFFICE LIQUIDITY ACCOUNTS ───────────────────────────┐\n";
$officeAccounts = DB::table('accounts')
    ->whereIn('type', ['cashbox', 'bank', 'wallet'])
    ->where('module_type', 'office')
    ->whereNull('deleted_at')
    ->orderByRaw("FIELD(type, 'cashbox', 'bank', 'wallet'), name")
    ->get(['id', 'name', 'type', 'currency', 'balance', 'is_active', 'is_module_vault', 'module']);

echo sprintf(
    "│ Found: %d accounts (cashbox/bank/wallet with module_type='office')\n",
    $officeAccounts->count()
);
echo "└──────────────────────────────────────────────────────────────┘\n\n";

// 2) For each account: compute calculated balance from entries
echo "┌─ [2] BALANCE vs ENTRIES (per account) ─────────────────────┐\n";
$totalDrift = 0.0;
$accountsWithDrift = [];

printf(
    "│ %-4s │ %-28s │ %-8s │ %-5s │ %14s │ %14s │ %14s │ %s\n",
    'ID', 'Name', 'Type', 'Cur', 'Displayed', 'Calc(cred-deb)', 'Drift', 'Status'
);
echo "├──────┼──────────────────────────────┼──────────┼───────┼────────────────┼────────────────┼────────────────┼─────────┤\n";

foreach ($officeAccounts as $acc) {
    $calc = DB::table('account_entries')
        ->where('account_id', $acc->id)
        ->selectRaw('COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0) AS net')
        ->value('net');

    $drift = round((float) $acc->balance - (float) $calc, 2);
    $status = abs($drift) < 0.01 ? '✅ OK' : '⚠️  DRIFT';

    printf(
        "│ %-4d │ %-28s │ %-8s │ %-5s │ %14s │ %14s │ %14s │ %s\n",
        $acc->id,
        mb_substr($acc->name, 0, 28),
        $acc->type,
        $acc->currency,
        number_format((float) $acc->balance, 2),
        number_format((float) $calc, 2),
        number_format($drift, 2),
        $status
    );

    if (abs($drift) >= 0.01) {
        $accountsWithDrift[] = [
            'id' => $acc->id,
            'name' => $acc->name,
            'type' => $acc->type,
            'currency' => $acc->currency,
            'displayed' => (float) $acc->balance,
            'calculated' => (float) $calc,
            'drift' => $drift,
        ];
        $totalDrift += abs($drift);
    }
}
echo "└──────┴──────────────────────────────┴──────────┴───────┴────────────────┴────────────────┴────────────────┴─────────┘\n\n";

// 3) Summary
echo "┌─ [3] SUMMARY ───────────────────────────────────────────────┐\n";
echo '│ Total office liquidity accounts: '.$officeAccounts->count()."\n";
echo '│ Accounts with drift:            '.count($accountsWithDrift)."\n";
echo '│ Total |drift|:                  '.number_format($totalDrift, 2)."\n";
echo "└──────────────────────────────────────────────────────────────┘\n\n";

// 4) Details of accounts with drift
if (count($accountsWithDrift) > 0) {
    echo "┌─ [4] DRIFT DETAILS ─────────────────────────────────────────┐\n";
    foreach ($accountsWithDrift as $a) {
        echo sprintf(
            "│ #%d %s (%s, %s)\n│   displayed = %s\n│   calculated = %s\n│   drift = %s\n│\n",
            $a['id'],
            $a['name'],
            $a['type'],
            $a['currency'],
            number_format($a['displayed'], 2),
            number_format($a['calculated'], 2),
            number_format($a['drift'], 2)
        );
    }
    echo "└──────────────────────────────────────────────────────────────┘\n";
}

// 5) Entry counts per account (sanity check)
echo "\n┌─ [5] ENTRY COUNTS (sanity) ─────────────────────────────────┐\n";
foreach ($officeAccounts as $acc) {
    $entryCount = DB::table('account_entries')->where('account_id', $acc->id)->count();
    $txCount = DB::table('transactions')
        ->where('from_account_id', $acc->id)
        ->orWhere('to_account_id', $acc->id)
        ->count();
    echo sprintf(
        "│ #%d %-30s  entries=%d  txns=%d\n",
        $acc->id,
        mb_substr($acc->name, 0, 30),
        $entryCount,
        $txCount
    );
}
echo "└──────────────────────────────────────────────────────────────┘\n";

echo "\n═══════════════════════════════════════════════════════════════\n";
echo " DIAGNOSTIC COMPLETE (READ-ONLY)\n";
echo "═══════════════════════════════════════════════════════════════\n";
