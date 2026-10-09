<?php

declare(strict_types=1);

/**
 * stress_phase_b_cleanup_legacy_opening.php
 *
 * Phase 25-B — One-off cleanup of pre-existing legacy fixture artifacts.
 *
 * The idempotency-gate + duplicate-payment-gate scripts created an opening
 * transaction (#1) with notes="STRESS-HU-OPENING" that has 2 credit entries
 * totaling 2,000,000 EGP and ZERO debit entries. This was a fixture
 * artifact that slipped through both gates' reconciliation (the gates
 * considered it a documented exception).
 *
 * The Phase B seeder creates its OWN correctly-balanced opening transaction
 * (#47) covering all 50 new liquidity accounts. The legacy TX 1 is now
 * stale and unbalanced — it inflates the vault and capital by 1M each.
 *
 * Cleanup steps (atomically inside a single transaction):
 *   1. Find all unbalanced transactions with notes='STRESS-HU-OPENING'.
 *   2. Capture affected account IDs.
 *   3. Delete account_entries for those transactions.
 *   4. Delete the transactions themselves.
 *   5. Recompute accounts.balance for affected accounts from remaining
 *      entries using the project convention: balance = SUM(credit) - SUM(debit).
 *
 * SAFETY:
 *   - Only touches the safarak_stress DB.
 *   - Only affects transactions with notes='STRESS-HU-OPENING' (known
 *     legacy fixture marker).
 *   - Hard-aborts if APP_ENV != stress.
 *   - Reports before/after for audit.
 */

require __DIR__ . '/../../vendor/autoload.php';

if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=stress');
    $_ENV['APP_ENV'] = 'stress';
}

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// Hard safety check
if (env('APP_ENV') !== 'stress') {
    fwrite(STDERR, "🛑  APP_ENV must be 'stress' (got '" . env('APP_ENV') . "'). ABORT.\n");
    exit(2);
}
$dbName = config('database.connections.mysql.database');
if ($dbName !== 'safarak_stress') {
    fwrite(STDERR, "🛑  DB_DATABASE must be 'safarak_stress' (got '{$dbName}'). ABORT.\n");
    exit(2);
}

echo "═══════════════════════════════════════════════════════════\n";
echo "  Phase B — Legacy Opening Cleanup\n";
echo "═══════════════════════════════════════════════════════════\n";
echo "APP_ENV:           " . env('APP_ENV') . "\n";
echo "DB_DATABASE:       " . $dbName . "\n";
echo "SELECT DATABASE(): " . DB::selectOne('SELECT DATABASE() AS d')->d . "\n";
echo "─────────────────────────────────────────────────────────────\n";

// 1. Find unbalanced legacy opening transactions
$legacyTxs = DB::table('transactions')
    ->where('notes', 'STRESS-HU-OPENING')
    ->get()
    ->filter(function ($tx) {
        $d = (float) DB::table('account_entries')->where('transaction_id', $tx->id)->sum('debit');
        $c = (float) DB::table('account_entries')->where('transaction_id', $tx->id)->sum('credit');
        return abs($d - $c) > 0.0001;
    });

if ($legacyTxs->isEmpty()) {
    echo "✓ No unbalanced legacy STRESS-HU-OPENING transactions found. Skipping.\n";
    exit(0);
}

echo "Found " . $legacyTxs->count() . " unbalanced legacy opening transaction(s):\n";
foreach ($legacyTxs as $tx) {
    $d = (float) DB::table('account_entries')->where('transaction_id', $tx->id)->sum('debit');
    $c = (float) DB::table('account_entries')->where('transaction_id', $tx->id)->sum('credit');
    echo sprintf("  TX %d (created=%s): debit=%.2f credit=%.2f var=%.2f\n",
        $tx->id, $tx->created_at, $d, $c, $c - $d);

    foreach (DB::table('account_entries')->where('transaction_id', $tx->id)->get() as $e) {
        echo sprintf("    account=%d debit=%.2f credit=%.2f bal_after=%.2f\n",
            $e->account_id, $e->debit, $e->credit, $e->balance_after);
    }
}

// 2. Capture affected account IDs and BEFORE balances
$affectedAccounts = DB::table('account_entries')
    ->whereIn('transaction_id', $legacyTxs->pluck('id'))
    ->distinct()
    ->pluck('account_id');

echo "\nAffected accounts: " . $affectedAccounts->implode(', ') . "\n";

$beforeBalances = [];
foreach ($affectedAccounts as $aid) {
    $beforeBalances[$aid] = (float) DB::table('accounts')->where('id', $aid)->value('balance');
    echo sprintf("  BEFORE: account %d balance = %.2f\n", $aid, $beforeBalances[$aid]);
}

// 3-5. Atomic cleanup
echo "\n--- Cleanup (atomic) ---\n";
DB::transaction(function () use ($legacyTxs, $affectedAccounts) {
    $deletedEntries = DB::table('account_entries')
        ->whereIn('transaction_id', $legacyTxs->pluck('id'))
        ->delete();
    echo "  Deleted {$deletedEntries} account_entries\n";

    $deletedTx = DB::table('transactions')
        ->whereIn('id', $legacyTxs->pluck('id'))
        ->delete();
    echo "  Deleted {$deletedTx} transactions\n";

    // Recompute balances from remaining entries
    foreach ($affectedAccounts as $aid) {
        $d = (float) DB::table('account_entries')->where('account_id', $aid)->sum('debit');
        $c = (float) DB::table('account_entries')->where('account_id', $aid)->sum('credit');
        $newBal = round($c - $d, 2);
        DB::table('accounts')->where('id', $aid)->update(['balance' => $newBal]);
        echo sprintf("  account %d: debit=%.2f credit=%.2f → balance=%.2f (recomputed)\n", $aid, $d, $c, $newBal);
    }
});

// 4. Verify
echo "\n--- AFTER ---\n";
foreach ($affectedAccounts as $aid) {
    $after = (float) DB::table('accounts')->where('id', $aid)->value('balance');
    echo sprintf("  AFTER:  account %d balance = %.2f (was %.2f, delta=%.2f)\n",
        $aid, $after, $beforeBalances[$aid], $after - $beforeBalances[$aid]);
}

// 5. Re-run reconciliation check
echo "\n--- Reconciliation recheck ---\n";
require_once __DIR__ . '/../Stress/Support/StressReconciliation.php';
$report = \Tests\Stress\Support\StressReconciliation::runAll();
echo "  per_account failed:    " . $report['per_account']['failed'] . "\n";
echo "  per_transaction failed: " . $report['per_transaction']['failed'] . "\n";
echo "  orphan entries:         " . $report['orphan_entries']['count'] . "\n";
echo "  verdict:                " . $report['verdict'] . "\n";

echo "\n✅ Legacy cleanup complete.\n";
exit($report['verdict'] === 'PASS' ? 0 : 1);