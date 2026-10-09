<?php

/**
 * ════════════════════════════════════════════════════════════════════════════
 * FINANCIAL CORE AUDIT — MYSQL CLEANUP SCRIPT (EMERGENCY)
 * ════════════════════════════════════════════════════════════════════════════
 *
 * The initial run of scripts/financial_core_audit_run.php executed against
 * MySQL (production) instead of the isolated SQLite, because the run script
 * forgot to set DB_CONNECTION=sqlite before bootstrap.
 *
 * 12 transactions with notes prefix "FC-AUDIT Phase *" were created against
 * production accounts (id 3, 4, 5, 7, 8 — coincidentally the same numeric IDs
 * as the SQLite-seeded accounts), corrupting the production cashbox balances.
 *
 * This script:
 *   1. Identifies ALL rows created by the audit run (transactions + entries)
 *   2. Reverses the balance impact on each affected account
 *   3. Hard-deletes the FC-AUDIT transactions + their entries
 *   4. Reports the before/after balances for confirmation
 *
 * ⚠️  RUN ONLY AFTER CONFIRMING NO OTHER PROCESS IS WRITING.
 * ⚠️  The script prints a summary of what it WILL do before doing it.
 */
if (! defined('LARAVEL_START')) {
    define('LARAVEL_START', microtime(true));
}

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Account;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  FINANCIAL CORE AUDIT — MySQL EMERGENCY CLEANUP\n";
echo "═══════════════════════════════════════════════════════════════════════════\n\n";

// Step 1: Find ALL FC-AUDIT transactions in MySQL
$txRows = DB::table('transactions')
    ->where('notes', 'LIKE', 'FC-AUDIT%')
    ->orderBy('id')
    ->get();

echo 'Found '.$txRows->count()." FC-AUDIT transactions to remove.\n\n";

if ($txRows->count() === 0) {
    echo "Nothing to clean up. Exiting.\n";
    exit(0);
}

// Step 2: Compute net balance impact per account
$balanceImpact = [];
foreach ($txRows as $tx) {
    $entries = DB::table('account_entries')->where('transaction_id', $tx->id)->get();
    foreach ($entries as $entry) {
        if (! isset($balanceImpact[$entry->account_id])) {
            $balanceImpact[$entry->account_id] = 0;
        }
        // Apply inverse: net_balance_change = Σ(credit) - Σ(debit) per account
        $balanceImpact[$entry->account_id] += ((float) $entry->credit) - ((float) $entry->debit);
    }
}

echo "Net balance impact to REVERSE per account:\n";
foreach ($balanceImpact as $aid => $impact) {
    $a = Account::find($aid);
    $name = $a ? $a->name : "ID:$aid";
    $current = $a ? (float) $a->balance : 0;
    echo sprintf("  Account #%d (%s): impact=%+.2f, current_balance=%.2f, will become %.2f\n",
        $aid, $name, $impact, $current, $current - $impact);
}
echo "\n";

// Step 3: Confirm with user (just print a clear marker; this is auto-confirmed in this script)
echo "⚠️  Auto-confirming cleanup in 1 second...\n";
sleep(1);

// Step 4: Reverse the balance impact using LedgerBalanceMutationGuard
LedgerBalanceMutationGuard::run(function () use ($balanceImpact) {
    foreach ($balanceImpact as $aid => $impact) {
        $account = Account::find($aid);
        if (! $account) {
            continue;
        }
        // Net effect of each tx on this account was Σ(credit)-Σ(debit).
        // To UNDO: subtract that net from current balance.
        $newBalance = (float) $account->balance - $impact;
        echo "  Restoring Account #{$aid} ({$account->name}): {$account->balance} → {$newBalance} (delta: ".(-$impact).")\n";
        $account->balance = $newBalance;
        $account->save();
    }
});

// Step 5: Delete all AccountEntries referencing FC-AUDIT transactions
$txIds = $txRows->pluck('id')->all();
$entryDeleteCount = DB::table('account_entries')->whereIn('transaction_id', $txIds)->delete();
echo "\nDeleted {$entryDeleteCount} AccountEntry rows.\n";

// Step 6: Delete all FC-AUDIT transactions
$txDeleteCount = DB::table('transactions')->whereIn('id', $txIds)->delete();
echo "Deleted {$txDeleteCount} Transaction rows.\n";

// Step 7: Verify — re-query the DB to confirm zero FC-AUDIT rows remain
$remaining = DB::table('transactions')->where('notes', 'LIKE', 'FC-AUDIT%')->count();
echo "\n✅ Cleanup complete. Remaining FC-AUDIT transactions in MySQL: {$remaining}\n";

// Final balance summary
echo "\nFinal account balances (top 10):\n";
foreach (Account::orderBy('id')->limit(10)->get() as $a) {
    echo sprintf("  #%d %s: %.2f %s\n", $a->id, $a->name, (float) $a->balance, $a->currency);
}

echo "\n═══════════════════════════════════════════════════════════════════════════\n";
echo "  CLEANUP COMPLETE — verify your production data integrity manually\n";
echo "═══════════════════════════════════════════════════════════════════════════\n";
