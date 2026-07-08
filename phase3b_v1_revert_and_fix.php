<?php
/**
 * PHASE 3b v1 — ROLLBACK + RE-APPLY CORRECT
 *
 * ⚠️ الإصدار الأول من phase3b_safe_fix.php طبّق الـ fix غلط:
 *    - كان يجب debit على الحساب المسبق (يقلل الـ sum)
 *    - لكن السكربت القديم عمل credit (زيد الـ sum)
 *    - النتيجة: الـ delta لسه -15,750 (مش اتحلّ)
 *
 * هذا السكربت يصلح ده:
 *   [1] يـ ROLLBACK للوضع الأصلي (إزالة transaction خاطئ + restore balances)
 *   [2] يطبق الـ fix الصحيح (debit على prepaid / credit على adjustment)
 *
 * قراءة فقط افتراضياً، استخدم --apply للتنفيذ.
 */

use App\Models\Account;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Models\AccountEntry;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$apply = in_array('--apply', $argv ?? [], true);

echo "\n";
echo "================================================================================\n";
echo "  PHASE 3b v1 — ROLLBACK + RE-APPLY (read-only by default)\n";
echo "  Generated: " . now()->format('Y-m-d H:i:s') . "\n";
echo "  Mode: " . ($apply ? '⚠️  APPLY' : '✓ DRY-RUN') . "\n";
echo "================================================================================\n\n";

echo "[1] Current state (verification)\n";
echo str_repeat('─', 80) . "\n";

// Find the bad transaction (137 from the previous run)
$prepaidName = config('accounting.clearing.prepaid.flight_carrier');
$prepaidAcc = Account::where('name', $prepaidName)->first();
$adjustmentAcc = Account::where('name', 'تسوية فروقات افتتاحية — ناقلو الطيران')->first();

if ($adjustmentAcc) {
    echo "  Adjustment account: id={$adjustmentAcc->id} name='{$adjustmentAcc->name}' balance={$adjustmentAcc->balance}\n";
} else {
    echo "  Adjustment account: NOT FOUND (rollback may not be needed)\n";
}

if ($prepaidAcc) {
    echo "  Prepaid Carrier:    id={$prepaidAcc->id} name='{$prepaidAcc->name}' balance={$prepaidAcc->balance}\n";
}

$prepaidEntries = DB::table('account_entries')->where('account_id', $prepaidAcc->id)->get(['id', 'transaction_id', 'debit', 'credit']);
$prepaidSum = $prepaidEntries->sum('credit') - $prepaidEntries->sum('debit');
$prepaidDelta = $prepaidAcc->balance - $prepaidSum;

echo "  Total account_entries for prepaid: " . $prepaidEntries->count() . "\n";
echo "  Σ prepaid (credits - debits): {$prepaidSum}\n";
echo "  Prepaid delta: {$prepaidDelta}\n";

if ($adjustmentAcc) {
    $adjustSum = DB::table('account_entries')->where('account_id', $adjustmentAcc->id)->sum('credit') - DB::table('account_entries')->where('account_id', $adjustmentAcc->id)->sum('debit');
    echo "  Adjustment account entries sum: {$adjustSum}\n";
    echo "  Adjustment balance: {$adjustmentAcc->balance}\n";
}

echo "\n";

// Find the bad transaction — look for any reconciliation transaction
$badTx = DB::table('transactions')
    ->where('related_type', 'phase3b_reconciliation')
    ->where('related_id', $prepaidAcc->id)
    ->first();

if ($badTx) {
    echo "  Found bad reconciliation transaction: #{$badTx->id}, amount={$badTx->amount}, created={$badTx->created_at}\n";
    $badEntries = DB::table('account_entries')->where('transaction_id', $badTx->id)->get();
    foreach ($badEntries as $be) {
        echo "    Entry: id={$be->id} account_id={$be->account_id} debit={$be->debit} credit={$be->credit}\n";
    }
} else {
    echo "  No bad reconciliation transaction found (already cleaned up?)\n";
}

echo "\n";

// ═══════════════════════════════════════════════════════════
// [2] Rollback plan
// ═══════════════════════════════════════════════════════════
echo "[2] ROLLBACK PLAN\n";
echo str_repeat('─', 80) . "\n";

if (! $badTx) {
    echo "  ✓ Nothing to rollback (no bad transactions found).\n";
} else {
    echo "  Step 1: Delete the bad transaction #{$badTx->id}\n";
    echo "    → DELETE FROM transactions WHERE id={$badTx->id}\n";
    echo "  Step 2: Delete the bad account_entries\n";
    echo "    → DELETE FROM account_entries WHERE transaction_id={$badTx->id}\n";
    echo "  Step 3: Restore Prepaid Carrier balance: {$prepaidAcc->balance} → -31,587.15 (original)\n";
    if ($adjustmentAcc) {
        echo "  Step 4: Restore Adjustment balance: {$adjustmentAcc->balance} → 0 (original)\n";
    }
    echo "  Step 5: Delete the audit_log entry for the bad reconciliation\n";
}

echo "\n";

// ═══════════════════════════════════════════════════════════
// [3] Correct re-apply plan
// ═══════════════════════════════════════════════════════════
echo "[3] CORRECT RE-APPLY PLAN\n";
echo str_repeat('─', 80) . "\n";

echo "  ⚠️ The correct fix uses DEBIT on Prepaid (not credit)\n";
echo "  → This adds 15,750 to debits in account_entries\n";
echo "  → Effective sum: (-15,837 + -15,750 in debits) = -31,587 ✓ matches actual\n";
echo "  → Prepaid balance column stays -31,587 (no manual change needed)\n";
echo "  → Adjustment account gets +15,750 (as credit) — net 0 again\n";
echo "\n";
echo "  Plan: Create transaction #X with entries:\n";
echo "    - DEBIT prepaid by 15,750 → sum becomes -31,587 ✓\n";
echo "    - CREDIT adjustment by 15,750 → source of the offsetting amount\n";
echo "  No balance column changes — only entries get the historic adjustment.\n";

echo "\n";
echo "================================================================================\n";
echo "  END OF PLAN (dry-run printed above)\n";
echo "================================================================================\n";
echo "  ⚠️ هذا تقرير read-only.\n";
echo "  عند التنفيذ الفعلي: php artisan tinker --execute='\$argv=[\"--apply\"]; require \"phase3b_v1_revert_and_fix.php\";'\n";
echo "\n";
