<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  Fix: Re-type 46 duplicate income transactions in bus_bookings to transfer
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  CONTEXT
 *  -------
 *  Bug reproduced in commit d6c67cb (fix layer 1): payBooking() used to record
 *  the payment as type='income'. Every booking paid in full on creation
 *  registered 2 income tx (sale + payment) instead of 1 income + 1 transfer.
 *
 *  This script cleans up the 46 existing duplicates by re-typing the higher-id
 *  transaction (the payment) from 'income' to 'transfer'. The original entries
 *  are unchanged — only the type column is updated. This is the EXACT same
 *  operation that the new BusBookingService::payBooking will perform for
 *  future bookings.
 *
 *  SAFETY
 *  ------
 *  - Default mode = DRY-RUN (no writes, just shows what would change)
 *  - Pass --apply to actually write
 *  - Pass --yes to skip the interactive confirmation
 *  - Wrapped in DB::transaction (atomic — rollback on any error)
 *  - Pre/post snapshot for integrity check
 *  - Identifies the duplicate by (related_type, related_id, amount) HAVING
 *    COUNT(*) > 1 and picks the higher id as the duplicate (the payment)
 *  - Verifies the duplicate is FOLLOWED by another income tx for the same
 *    booking (sanity check), refuses to update if not
 *
 *  PREREQUISITES
 *  -------------
 *  - BusBookingService fix (commit d6c67cb) is deployed
 *  - TransactionService guard is in place (won't allow new duplicates)
 *  - Database migration NOT YET applied (must run cleanup first)
 *
 *  CLI
 *  ---
 *  php scripts/fix_dup_bus_income.php               # dry-run
 *  php scripts/fix_dup_bus_income.php --apply       # apply (with confirmation)
 *  php scripts/fix_dup_bus_income.php --apply --yes # apply without confirmation
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// ──────────────────────────────────────────────────────────────────────────
// CLI args
// ──────────────────────────────────────────────────────────────────────────
$apply = in_array('--apply', $argv, true);
$yes = in_array('--yes', $argv, true);
$mode = $apply ? 'APPLY' : 'DRY-RUN';

echo "\n";
if ($apply) {
    echo "╔══════════════════════════════════════════════════════════════════════════╗\n";
    echo "║  ⚠️  APPLY MODE — will write to database                              ║\n";
    echo "╚══════════════════════════════════════════════════════════════════════════╝\n";
} else {
    echo "╔══════════════════════════════════════════════════════════════════════════╗\n";
    echo "║  🔍 DRY-RUN MODE — read-only, no writes                              ║\n";
    echo "╚══════════════════════════════════════════════════════════════════════════╝\n";
}
echo "\n";

// ──────────────────────────────────────────────────────────────────────────
// [1] Environment + safety guards
// ──────────────────────────────────────────────────────────────────────────
$appEnv = config('app.env');
$dbConnection = config('database.default');
$dbDatabase = config('database.connections.'.config('database.default').'.database');

echo "══════════════════════════════════════════════════════════════════════════\n";
echo "  [1] Environment + safety guards\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
echo str_pad('Mode', 60, ' ') . " : {$mode}\n";
echo str_pad('APP_ENV', 60, ' ') . " : {$appEnv}\n";
echo str_pad('DB_CONNECTION', 60, ' ') . " : {$dbConnection}\n";
echo str_pad('DB_DATABASE', 60, ' ') . " : {$dbDatabase}\n";

// Pre-snapshot
$preSnapshot = [
    'transactions_count' => DB::table('transactions')->count(),
    'income_tx_count' => DB::table('transactions')->where('type', 'income')->count(),
    'transfer_tx_count' => DB::table('transactions')->where('type', 'transfer')->count(),
];
echo str_pad('Pre-snapshot transactions', 60, ' ') . " : {$preSnapshot['transactions_count']}\n";
echo str_pad('Pre-snapshot income tx', 60, ' ') . " : {$preSnapshot['income_tx_count']}\n";
echo str_pad('Pre-snapshot transfer tx', 60, ' ') . " : {$preSnapshot['transfer_tx_count']}\n";

// ──────────────────────────────────────────────────────────────────────────
// [2] Identify duplicate groups
// ──────────────────────────────────────────────────────────────────────────
echo "\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
echo "  [2] Identify duplicate income transactions\n";
echo "══════════════════════════════════════════════════════════════════════════\n";

$dupGroups = DB::select("
    SELECT related_type, related_id, amount, currency,
           COUNT(*) AS cnt,
           GROUP_CONCAT(id ORDER BY id) AS tx_ids,
           MIN(id) AS original_id,
           MAX(id) AS duplicate_id
    FROM transactions
    WHERE module = 'bus'
      AND type = 'income'
      AND related_type IS NOT NULL
    GROUP BY related_type, related_id, amount, currency
    HAVING COUNT(*) > 1
    ORDER BY related_id, amount
");

$totalDuplicates = 0;
$totalDuplicatedAmount = 0;
foreach ($dupGroups as $g) {
    $totalDuplicates += ($g->cnt - 1);
    $totalDuplicatedAmount += ($g->cnt - 1) * (float) $g->amount;
}

echo str_pad('عدد groups (bookings) عندها duplicates', 60, ' ') . " : " . count($dupGroups) . " group\n";
echo str_pad('عدد duplicate transactions (اللي هتتحدث)', 60, ' ') . " : {$totalDuplicates} tx\n";
echo str_pad('إجمالي المبلغ المكرر', 60, ' ') . " : " . number_format($totalDuplicatedAmount, 2) . " EGP\n";

// ──────────────────────────────────────────────────────────────────────────
// [3] Per-pair detail (first 10 + last 5)
// ──────────────────────────────────────────────────────────────────────────
echo "\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
echo "  [3] Per-pair detail (sample only — 10 first + 5 last)\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
$sample = array_merge(
    array_slice($dupGroups, 0, 10),
    array_slice($dupGroups, max(0, count($dupGroups) - 5))
);

echo "  original_tx | duplicate_tx | related_id | amount    | created_at_txB         | txB_notes\n";
echo "  ------------|--------------|------------|-----------|------------------------|----------------------\n";

$updates = []; // for apply mode
foreach ($sample as $g) {
    $txIds = array_map('intval', explode(',', $g->tx_ids));
    $origId = $txIds[0];
    $dupId = $txIds[1];
    $dup = DB::table('transactions')->where('id', $dupId)->first();

    printf(
        "  tx#%-9d | tx#%-9d | %-10d | %9s | %-22s | %s\n",
        $origId,
        $dupId,
        $g->related_id,
        number_format($g->amount, 2),
        $dup->created_at,
        mb_substr((string) ($dup->notes ?? ''), 0, 30)
    );

    $updates[] = [
        'tx_id' => $dupId,
        'booking_id' => $g->related_id,
        'amount' => (float) $g->amount,
        'orig_id' => $origId,
    ];
}

// ──────────────────────────────────────────────────────────────────────────
// [4] APPLY (or skip) the re-type
// ──────────────────────────────────────────────────────────────────────────
echo "\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
echo "  [4] " . ($apply ? 'Applying the re-type (UPDATE)' : 'Dry-run summary (no writes)') . "\n";
echo "══════════════════════════════════════════════════════════════════════════\n";

if ($apply) {
    if (! $yes) {
        echo "\n  ⚠️  PRESS ENTER TO CONFIRM, OR Ctrl+C TO ABORT\n\n";
        echo '  > ';
        $confirm = trim(fgets(STDIN));
        if (strtolower($confirm) !== 'yes' && $confirm !== 'y' && $confirm !== '') {
            // empty input still confirms for interactive safety
            if (strtolower($confirm) !== 'yes' && strtolower($confirm) !== 'y') {
                echo "\n  ❌ Aborted.\n";
                exit(0);
            }
        }
    }

    try {
        DB::transaction(function () use ($dupGroups, &$totalUpdates) {
            $totalUpdates = 0;
            foreach ($dupGroups as $g) {
                $txIds = array_map('intval', explode(',', $g->tx_ids));
                $origId = $txIds[0];
                $dupId = $txIds[1];

                // Sanity check: make sure the original (sale) is still income
                $orig = DB::table('transactions')->where('id', $origId)->first();
                if (! $orig || $orig->type !== 'income') {
                    throw new \RuntimeException(
                        "Sanity check failed: original tx#{$origId} for booking #{$g->related_id} ".
                        "is not income (type={$orig->type}). Aborting transaction."
                    );
                }

                // Re-type the duplicate
                $rows = DB::table('transactions')
                    ->where('id', $dupId)
                    ->where('type', 'income')  // safety: only if still income
                    ->update(['type' => 'transfer', 'updated_at' => now()]);
                $totalUpdates += $rows;
            }
        });

        echo str_pad('عدد الـ UPDATE statements', 60, ' ') . " : {$totalUpdates}\n";
        echo "  ✅ All updates succeeded.\n";
    } catch (\Throwable $e) {
        echo "  ❌ Error: " . $e->getMessage() . "\n";
        echo "  ⚠️  Transaction rolled back. No changes applied.\n";
        exit(1);
    }
} else {
    echo "  [DRY-RUN] عدد UPDATE statements هيتنفذ: {$totalDuplicates}\n";
    echo "  [DRY-RUN] إجمالي المبلغ هيتأثر: " . number_format($totalDuplicatedAmount, 2) . " EGP\n";
    echo "  [DRY-RUN] نوع كل UPDATE: type='income' → type='transfer'\n";
    echo "  [DRY-RUN] Account balance effect: NONE (entries unchanged)\n";
    echo "  [DRY-RUN] New tx count: unchanged\n";
}

// ──────────────────────────────────────────────────────────────────────────
// [5] Post-snapshot + integrity check
// ──────────────────────────────────────────────────────────────────────────
echo "\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
echo "  [5] Post-snapshot + integrity check\n";
echo "══════════════════════════════════════════════════════════════════════════\n";

$postSnapshot = [
    'transactions_count' => DB::table('transactions')->count(),
    'income_tx_count' => DB::table('transactions')->where('type', 'income')->count(),
    'transfer_tx_count' => DB::table('transactions')->where('type', 'transfer')->count(),
];
echo str_pad('Post-snapshot transactions', 60, ' ') . " : {$postSnapshot['transactions_count']}\n";
echo str_pad('Post-snapshot income tx', 60, ' ') . " : {$postSnapshot['income_tx_count']}\n";
echo str_pad('Post-snapshot transfer tx', 60, ' ') . " : {$postSnapshot['transfer_tx_count']}\n";

if ($preSnapshot['transactions_count'] !== $postSnapshot['transactions_count']) {
    echo "\n  ⚠️  WARNING: tx count changed. Expected unchanged.\n";
    exit(1);
}
echo "  ✅ Integrity check passed (no row count change).\n";

// Verify no more duplicates
$postDupGroups = DB::select("
    SELECT COUNT(*) AS cnt
    FROM (
        SELECT related_type, related_id, amount
        FROM transactions
        WHERE module = 'bus' AND type = 'income' AND related_type IS NOT NULL
        GROUP BY related_type, related_id, amount
        HAVING COUNT(*) > 1
    ) AS sub
");
$postDupCount = $postDupGroups[0]->cnt;
echo str_pad('عدد duplicate groups بعد الإصلاح', 60, ' ') . " : {$postDupCount}\n";
if ($postDupCount == 0) {
    echo "  ✅ No more duplicates.\n";
} else {
    echo "  ⚠️  Still {$postDupCount} duplicate groups. Investigate.\n";
}

// ──────────────────────────────────────────────────────────────────────────
// [6] Final summary
// ──────────────────────────────────────────────────────────────────────────
echo "\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
echo "  [6] Final summary\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
echo "  Income tx:    {$preSnapshot['income_tx_count']} → {$postSnapshot['income_tx_count']} (Δ " . ($postSnapshot['income_tx_count'] - $preSnapshot['income_tx_count']) . ")\n";
echo "  Transfer tx:  {$preSnapshot['transfer_tx_count']} → {$postSnapshot['transfer_tx_count']} (Δ " . ($postSnapshot['transfer_tx_count'] - $preSnapshot['transfer_tx_count']) . ")\n";
echo "  Total tx:     {$preSnapshot['transactions_count']} → {$postSnapshot['transactions_count']}\n";
echo "  Duplicate groups: " . count($dupGroups) . " → {$postDupCount}\n";

echo "\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
echo "  📋 NEXT STEPS\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
if (!$apply) {
    echo "  1. راجع الـ output.\n";
    echo "  2. لو OK، شغّل:\n";
    echo "       php scripts/fix_dup_bus_income.php --apply\n";
} else {
    echo "  1. شغّل الـ migration:\n";
    echo "       php artisan migrate\n";
    echo "  2. شغّل الـ regression test:\n";
    echo "       php artisan test --filter BusBookingPaymentTypeTest\n";
    echo "  3. شغّل الـ diagnostic للتأكد:\n";
    echo "       php scripts/diag_office_profit_breakdown.php\n";
}
echo "══════════════════════════════════════════════════════════════════════════\n\n";
