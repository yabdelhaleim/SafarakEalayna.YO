<?php
/**
 * VERIFY — Confirms the math before deletion.
 * Run via:  php scripts/verify_tx_e2e_projection.php
 *
 * Read-only. No DB writes.
 * Predicts the expected balance after deleting TX-FULL-E2E-* test data.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$env = app()->environment();
echo "Environment: {$env}\n";
if ($env !== 'production' && $env !== 'local') {
    echo "(unknown env — proceeding anyway)\n";
}
echo "\n";

// 1. Current balance on cash drawer (id=6)
$balance = (float) DB::table('accounts')->where('id', 6)->value('balance');
echo "Current balance (id=6):    " . number_format($balance, 2) . " EGP\n";

// 2. Sum of all transactions on id=6 (matches our audit: 19,084)
$totalTx = DB::selectOne("
    SELECT COALESCE(SUM(CASE WHEN to_account_id=6 THEN amount ELSE -amount END), 0) AS net
    FROM transactions
    WHERE from_account_id=6 OR to_account_id=6
");
$totalTxSum = (float) ($totalTx->net ?? 0);
echo "All tx sum (incl E2E):     " . number_format($totalTxSum, 2) . " EGP\n";

// 3. Detect opening balance (the audit found 3,136 here)
$opening = $balance - $totalTxSum;
echo "Inferred opening balance:  " . number_format($opening, 2) . " EGP\n\n";

// 4. Sum of transactions excluding E2E accounts
$realTx = DB::selectOne("
    SELECT COALESCE(SUM(CASE WHEN t.to_account_id=6 THEN t.amount ELSE -t.amount END), 0) AS net
    FROM transactions t
    WHERE (t.from_account_id=6 OR t.to_account_id=6)
      AND t.from_account_id NOT IN (SELECT id FROM accounts WHERE name LIKE 'TX-FULL-E2E-%')
      AND t.to_account_id   NOT IN (SELECT id FROM accounts WHERE name LIKE 'TX-FULL-E2E-%')
");
$realSum = (float) ($realTx->net ?? 0);
echo "Real tx sum (excl E2E):    " . number_format($realSum, 2) . " EGP\n";

// 5. Expected balance after removing E2E
$expected = $opening + $realSum;
echo "─────────────────────────────────\n";
echo "Predicted after cleanup:   " . number_format($expected, 2) . " EGP\n";
echo "Target expected:           2,220.00 EGP\n";
echo "─────────────────────────────────\n";

if (abs($expected - 2220) < 0.01) {
    echo "✅ MATCH — safe to delete TX-FULL-E2E test data\n";
} else {
    echo "❌ MISMATCH — do NOT delete. Got " . number_format($expected, 2) . " EGP instead of 2,220.00\n";
    echo "   Possible cause: undetected transactions or different account id (not 6)\n";
}
echo "\n";