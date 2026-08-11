<?php
/**
 * ════════════════════════════════════════════════════════════════════════
 *  DESTRUCTIVE — Deletes TX-FULL-E2E-* test data from production
 * ════════════════════════════════════════════════════════════════════════
 *
 *  ⚠️  This script permanently removes test data from the database.
 *      It MUST be run with the --confirm flag, AND --backup-name flag.
 *
 *  Pre-requisites:
 *    1. Run scripts/verify_tx_e2e_projection.php first → must show "✅ MATCH"
 *    2. Backup tables are created before any delete (then DB::transaction wraps everything)
 *    3. Post-flight verify confirms expected balance (2,220)
 *
 *  Usage:
 *    # DRY-RUN (shows plan, no changes):
 *    php scripts/delete_tx_e2e_test_data.php
 *
 *    # Actually delete:
 *    php scripts/delete_tx_e2e_test_data.php --confirm --backup-name=e2e_20260811
 *
 *  Rollback:
 *    Backup tables (_bck_e2e_20260811_*) contain a full copy of deleted rows.
 *    Restore via: SELECT * INTO accounts FROM _bck_e2e_20260811_accounts; etc.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ─── CLI args ─────────────────────────────────────────────────────────────
$args   = $argv ?? [];
$confirmed = in_array('--confirm', $args, true);
$backupNameRaw = null;
foreach ($args as $i => $a) {
    if (str_starts_with($a, '--backup-name=')) {
        $backupNameRaw = substr($a, strlen('--backup-name='));
    }
}
$backupName = $backupNameRaw ? preg_replace('/[^a-zA-Z0-9_]/', '_', $backupNameRaw) : null;

$dryRun = !$confirmed;

echo "\n";
echo "═══════════════════════════════════════════════════════════════════════\n";
echo "  DELETE TX-FULL-E2E-* TEST DATA — " . ($dryRun ? "DRY-RUN (no changes)" : "CONFIRMED EXECUTION") . "\n";
echo "═══════════════════════════════════════════════════════════════════════\n\n";
echo "Environment:  " . app()->environment() . "\n";
echo "Backup name:  " . ($backupName ?: '(none — DRY-RUN will not backup)') . "\n";
echo "Confirmed:    " . ($confirmed ? '✅ YES' : '❌ NO — DRY-RUN mode') . "\n\n";

if (!$dryRun && !$backupName) {
    echo "❌ ABORTING — you used --confirm but no --backup-name=\n";
    echo "   Pass both flags together:\n";
    echo "   php scripts/delete_tx_e2e_test_data.php --confirm --backup-name=e2e_<date>\n\n";
    exit(2);
}

if (!$dryRun && app()->environment() !== 'production') {
    echo "⚠️  WARNING — current env is '" . app()->environment() . "', not 'production'!\n";
    echo "   Press Ctrl+C within 5 seconds to abort, otherwise it continues on this env.\n";
    sleep(5);
}

// ─── PRE-FLIGHT VERIFY (same as verify script) ──────────────────────────
echo "▶ [1/6] PRE-FLIGHT verify: checking projection...\n";
$current = (float) DB::table('accounts')->where('id', 6)->value('balance');
$allTx = (float) DB::selectOne("
    SELECT COALESCE(SUM(CASE WHEN to_account_id=6 THEN amount ELSE -amount END), 0) AS net
    FROM transactions WHERE from_account_id=6 OR to_account_id=6
")->net;
$opening = $current - $allTx;
$realTx = (float) DB::selectOne("
    SELECT COALESCE(SUM(CASE WHEN t.to_account_id=6 THEN t.amount ELSE -t.amount END), 0) AS net
    FROM transactions t
    WHERE (t.from_account_id=6 OR t.to_account_id=6)
      AND t.from_account_id NOT IN (SELECT id FROM accounts WHERE name LIKE 'TX-FULL-E2E-%')
      AND t.to_account_id   NOT IN (SELECT id FROM accounts WHERE name LIKE 'TX-FULL-E2E-%')
")->net;
$expected = $opening + $realTx;
echo "    current balance:     " . number_format($current, 2) . "\n";
echo "    opening (inferred):  " . number_format($opening, 2) . "\n";
echo "    real tx (excl E2E):  " . number_format($realTx, 2) . "\n";
echo "    expected after:      " . number_format($expected, 2) . "\n";

if (abs($expected - 2220) >= 0.01) {
    echo "    ❌ MISMATCH — aborting. Run scripts/verify_tx_e2e_projection.php to investigate.\n\n";
    exit(3);
}
echo "    ✅ MATCH — projected balance will be 2,220.00\n\n";

// ─── ENUMERATE rows that will be touched ─────────────────────────────────
echo "▶ [2/6] ENUMERATE: counting rows about to be deleted...\n";

$e2eAccountIds = DB::table('accounts')
    ->where('name', 'like', 'TX-FULL-E2E-%')
    ->pluck('id')->toArray();

$e2eCustomerIds = DB::table('customers')
    ->where(function ($q) {
        $q->where('full_name', 'like', 'TX-FULL-E2E-%')
          ->orWhere('phone',    'like', 'TX-FULL-E2E-%')
          ->orWhere('email',    'like', 'TX-FULL-E2E-%');
    })->pluck('id')->toArray();

// Double check: also include accounts whose customer_id is in E2E customers
// (handles case where account name doesn't match pattern but customer does)
if (Schema::hasColumn('accounts', 'customer_id') && !empty($e2eCustomerIds)) {
    $linked = DB::table('accounts')
        ->whereIn('customer_id', $e2eCustomerIds)
        ->pluck('id')->toArray();
    foreach ($linked as $id) {
        if (!in_array($id, $e2eAccountIds)) $e2eAccountIds[] = $id;
    }
}

$txCount = DB::table('transactions')
    ->whereIn('from_account_id', $e2eAccountIds)
    ->orWhereIn('to_account_id', $e2eAccountIds)
    ->count();

$bookingsCounts = [];
foreach (['bus_bookings', 'flight_bookings', 'visa_bookings', 'hajj_umra_bookings'] as $tbl) {
    if (!Schema::hasTable($tbl)) continue;
    $c = DB::table($tbl)->whereIn('customer_id', $e2eCustomerIds)->count();
    if ($c > 0) $bookingsCounts[$tbl] = $c;
}

echo "    E2E accounts to delete:        " . count($e2eAccountIds) . "\n";
echo "    E2E customers to delete:       " . count($e2eCustomerIds) . "\n";
echo "    transactions to delete:        " . $txCount . "\n";
foreach ($bookingsCounts as $t => $c) {
    echo "    {$t} to delete:                {$c}\n";
}
echo "\n";

if (count($e2eAccountIds) === 0 && count($e2eCustomerIds) === 0) {
    echo "    ℹ️  Nothing to delete. Already clean.\n";
    if (!$dryRun) echo "    → Run already happened previously, or test data has been removed manually.\n";
    exit(0);
}

// ─── DRY-RUN: stop here if not confirmed ────────────────────────────────
if ($dryRun) {
    echo "▶ [3/6] DRY-RUN MODE — no changes will be made.\n";
    echo "    To actually delete, run with:\n";
    echo "      php scripts/delete_tx_e2e_test_data.php --confirm --backup-name=e2e_<date>\n\n";
    exit(0);
}

// ─── BACKUP TABLES ───────────────────────────────────────────────────────
echo "▶ [3/6] BACKUP: creating snapshot tables...\n";

$bkPrefix = "_bck_{$backupName}_";

// Drop existing if collisions
DB::statement("DROP TABLE IF EXISTS {$bkPrefix}transactions");
DB::statement("DROP TABLE IF EXISTS {$bkPrefix}accounts");
DB::statement("DROP TABLE IF EXISTS {$bkPrefix}customers");
foreach (array_keys($bookingsCounts) as $t) {
    DB::statement("DROP TABLE IF EXISTS {$bkPrefix}{$t}");
}

// Create backups
DB::statement("CREATE TABLE {$bkPrefix}transactions AS SELECT * FROM transactions WHERE id IN (
    SELECT id FROM transactions WHERE from_account_id IN (" . implode(',', $e2eAccountIds) . ")
                                 OR to_account_id   IN (" . implode(',', $e2eAccountIds) . ")
)");
DB::statement("CREATE TABLE {$bkPrefix}accounts AS SELECT * FROM accounts WHERE id IN (" . implode(',', $e2eAccountIds) . ")");
DB::statement("CREATE TABLE {$bkPrefix}customers AS SELECT * FROM customers WHERE id IN (" . implode(',', $e2eCustomerIds) . ")");
foreach (array_keys($bookingsCounts) as $t) {
    DB::statement("CREATE TABLE {$bkPrefix}{$t} AS SELECT * FROM {$t} WHERE customer_id IN (" . implode(',', $e2eCustomerIds) . ")");
}

echo "    ✅ backups created with prefix: {$bkPrefix}\n\n";

// ─── DELETE (wrapped in DB transaction) ──────────────────────────────────
echo "▶ [4/6] DELETE: removing test data inside DB transaction...\n";

try {
    DB::transaction(function () use ($e2eAccountIds, $e2eCustomerIds, $bookingsCounts) {
        // 1. Delete all transactions touching E2E accounts
        $tx = DB::table('transactions')
            ->whereIn('from_account_id', $e2eAccountIds)
            ->orWhereIn('to_account_id', $e2eAccountIds)
            ->delete();
        echo "    ✓ deleted {$tx} transactions\n";

        // 2. Delete bookings (flight_bookings, etc.)
        foreach ($bookingsCounts as $t => $cnt) {
            $d = DB::table($t)->whereIn('customer_id', $e2eCustomerIds)->delete();
            echo "    ✓ deleted {$d} rows from {$t}\n";
        }

        // 3. Delete customers
        $cu = DB::table('customers')->whereIn('id', $e2eCustomerIds)->delete();
        echo "    ✓ deleted {$cu} customers\n";

        // 4. Delete accounts
        $ac = DB::table('accounts')->whereIn('id', $e2eAccountIds)->delete();
        echo "    ✓ deleted {$ac} accounts\n";

        // 5. Update cash drawer balance manually
        DB::table('accounts')->where('id', 6)->update(['balance' => 2220.00, 'updated_at' => now()]);
        echo "    ✓ updated cash drawer balance → 2,220.00\n";
    });
} catch (\Throwable $e) {
    echo "    ❌ TRANSACTION FAILED — all changes rolled back: " . $e->getMessage() . "\n\n";
    exit(4);
}

echo "\n";

// ─── POST-FLIGHT VERIFY ──────────────────────────────────────────────────
echo "▶ [5/6] POST-FLIGHT verify: confirming new balance...\n";
$newBalance = (float) DB::table('accounts')->where('id', 6)->value('balance');
$e2eRemaining = DB::table('accounts')->where('name', 'like', 'TX-FULL-E2E-%')->count();

echo "    cash drawer balance now: " . number_format($newBalance, 2) . "\n";
echo "    E2E accounts remaining:  {$e2eRemaining}\n";

if (abs($newBalance - 2220) < 0.01 && $e2eRemaining === 0) {
    echo "    ✅ POST-FLIGHT PASSED\n\n";
} else {
    echo "    ⚠️  POST-FLIGHT WARNING — something unexpected. Investigate before finalizing.\n\n";
}

// ─── REMIND rollback path ────────────────────────────────────────────────
echo "▶ [6/6] ROLLBACK REFERENCE — backup tables you can restore from:\n";
echo "    backup tables: {$bkPrefix}*\n";
echo "    to restore:\n";
echo "      DB::statement(\"INSERT INTO transactions SELECT * FROM {$bkPrefix}transactions\");\n";
echo "      DB::statement(\"INSERT INTO accounts    SELECT * FROM {$bkPrefix}accounts\");\n";
echo "      DB::statement(\"INSERT INTO customers   SELECT * FROM {$bkPrefix}customers\");\n";
foreach (array_keys($bookingsCounts) as $t) {
    echo "      DB::statement(\"INSERT INTO {$t} SELECT * FROM {$bkPrefix}{$t}\");\n";
}
echo "\n";

echo "═══════════════════════════════════════════════════════════════════════\n";
echo "  CLEANUP COMPLETE\n";
echo "═══════════════════════════════════════════════════════════════════════\n\n";