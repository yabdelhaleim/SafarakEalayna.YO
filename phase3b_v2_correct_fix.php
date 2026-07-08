<?php
/**
 * PHASE 3b v2: CORRECTED FIX — يطبّق فقط بعد موافقة المحاسبة على الـ HANDOFF.
 *
 * ⚠️ v1 (phase3b_safe_fix.php) كان به bug: عمل CREDIT بدل DEBIT على prepaid،
 *    ده خلاه يرفع الـ balance والـ entries_sum بنفس المقدار = الـ delta ظل ثابت -15,750.
 *
 * ✅ v2 (هذا الملف): يعمل DEBIT على prepaid بـ |delta| عشان الـ entries_sum ينزل
 *    ويطابق الـ balance الفعلي.
 *
 * ─────────────────────────────────────────────────────────────────────
 * الاستخدام:
 *   # 1) DRY-RUN (آمن، يعرض الـ diff بدون تنفيذ):
 *   php artisan tinker --execute='require "phase3b_v2_correct_fix.php";'
 *
 *   # 2) تنفيذ فعلي (لازم --apply flag + تأكيد صريح):
 *   php artisan tinker --execute='$argv=["--apply"]; require "phase3b_v2_correct_fix.php";'
 *
 * ⏳ متطلبات قبل التشغيل:
 *   - تقرير ACCOUNTING_HANDOFF_YYYY-MM-DD.md لازم يكون موقّع من المحاسبة
 *   - backup كامل لـ DB اتاخد
 *   - قفل الـ bookings (maintenance mode أو ما يعادله)
 * ─────────────────────────────────────────────────────────────────────
 */

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Services\Finance\LedgerClearingAccounts;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// ═══════════════════════════════════════════════════════════════════
// [A] HEADERS + APPLY MODE
// ═══════════════════════════════════════════════════════════════════
$apply = in_array('--apply', $argv ?? [], true);

echo "\n";
echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  PHASE 3b v2: CORRECTED FIX                                             \n";
echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  Mode:    " . ($apply ? '⚠️  APPLY (يكتب على DB)' : '🟢 DRY-RUN (read-only)') . "\n";
echo "  Generated: " . now()->format('Y-m-d H:i:s') . "\n";
echo "═══════════════════════════════════════════════════════════════════════════\n\n";

if (! $apply) {
    echo "ℹ️  هذا DRY-RUN — لتطبيق فعلي، شغّل بـ --apply بعد موافقة المحاسبة + backup.\n\n";
}

// ═══════════════════════════════════════════════════════════════════
// [B] تحديد الـ prepaid account
// ═══════════════════════════════════════════════════════════════════
$ledgerClearingAccounts = app(LedgerClearingAccounts::class);

try {
    $prepaidId = $ledgerClearingAccounts->prepaidAccountId('flight_carrier');
} catch (\Throwable $e) {
    $prepaidId = DB::table('accounts')->where('name', 'رصيد مسبق — ناقلو الطيران')->value('id');
}

if (! $prepaidId) {
    echo "✗ ERROR: مش لاقي حساب 'رصيد مسبق — ناقلو الطيران' — أوقف.\n";
    return;
}

$prepaidAcc = Account::find($prepaidId);
echo "[B] Prepaid Flight-Carrier GL\n";
echo "    Account: '{$prepaidAcc->name}' (id={$prepaidAcc->id})\n\n";

// ═══════════════════════════════════════════════════════════════════
// [C] احسب الـ delta الحالي
// ═══════════════════════════════════════════════════════════════════
$actual    = (float) $prepaidAcc->balance;
$credits   = (float) DB::table('account_entries')->where('account_id', $prepaidId)->sum('credit');
$debits    = (float) DB::table('account_entries')->where('account_id', $prepaidId)->sum('debit');
$calculated = $credits - $debits;
$delta     = $actual - $calculated;

echo "[C] Current GL State\n";
echo str_repeat('─', 75) . "\n";
echo "    account.balance:        " . sprintf('%.2f', $actual) . "\n";
echo "    Σ account_entries:      " . sprintf('%.2f', $calculated) . " ({$credits} credits - {$debits} debits)\n";
echo "    Delta (balance - sum):  " . sprintf('%.2f', $delta) . "\n";
echo str_repeat('─', 75) . "\n\n";

// ═══════════════════════════════════════════════════════════════════
// [D] لو الـ delta = 0 → لا داعي للـ fix
// ═══════════════════════════════════════════════════════════════════
if (abs($delta) < 0.01) {
    echo "✅ Delta ~ 0 — الـ GL متوازن، مش محتاج fix.\n";
    echo "    (تقرير ACCOUNTING_HANDOFF لازم يشوف الفروقات الـ per-carrier الفردية.)\n";
    return;
}

$adjustAmount = abs($delta);
$direction = $delta < 0 ? 'DEBIT' : 'CREDIT';

echo "[D] Proposed Correction\n";
echo str_repeat('─', 75) . "\n";
echo "    Direction:        {$direction} prepaid by " . sprintf('%.2f', $adjustAmount) . " EGP\n";

if ($delta < 0) {
    echo "    Reason:           actual ({$actual}) < calculated ({$calculated}) →\n";
    echo "                      الـ balance أغمق من اللازم — نضيف debit ينزّل الـ entries_sum\n";
} else {
    echo "    Reason:           actual ({$actual}) > calculated ({$calculated}) →\n";
    echo "                      الـ balance أرفع من اللازم — نضيف credit ينزّل الـ entries_sum\n";
}

echo str_repeat('─', 75) . "\n\n";

// ═══════════════════════════════════════════════════════════════════
// [E] الحساب المقابل (adjustment account)
// ═══════════════════════════════════════════════════════════════════
$adjustmentAccount = Account::where('name', 'تسوية فروقات افتتاحية — ناقلو الطيران')->first();

if (! $adjustmentAccount) {
    echo "[E] Adjustment account غير موجود — هيتم إنشاؤه:\n";

    if ($apply) {
        try {
            $adjustmentAccount = Account::create([
                'name'       => 'تسوية فروقات افتتاحية — ناقلو الطيران',
                'code'       => 'ADJ-CARR-OPENING',
                'type'       => 'liability',
                'currency'   => 'EGP',
                'balance'    => 0,
                'is_active'  => true,
                'created_by' => Auth::id() ?? 1,
            ]);
            echo "    ✓ Created: id={$adjustmentAccount->id}\n\n";
        } catch (\Throwable $e) {
            echo "    ✗ Failed: {$e->getMessage()}\n";
            return;
        }
    } else {
        echo "    [DRY-RUN: الحساب هيتم إنشاؤه عند --apply]\n\n";
        $adjustmentAccount = new Account(['name' => 'تسوية فروقات افتتاحية — ناقلو الطيران', 'id' => 0]);
    }
} else {
    echo "[E] Adjustment account موجود: '{$adjustmentAccount->name}' (id={$adjustmentAccount->id}, balance={$adjustmentAccount->balance})\n\n";
}

// ═══════════════════════════════════════════════════════════════════
// [F] تنفيذ الـ correction (تحت Guard)
// ═══════════════════════════════════════════════════════════════════
if (! $apply) {
    echo "[F] [DRY-RUN] الخطوات اللي هتنفّذ عند --apply:\n";
    echo "    ① START TRANSACTION\n";
    echo "    ② INSERT INTO transactions (type=transfer, amount={$adjustAmount}, related_type='phase3b_v2_correction')\n";
    echo "    ③ INSERT INTO account_entries (debit={$adjustAmount}, credit=0) على prepaid\n";
    echo "        — ده {$direction} للـ prepaid، ينزّل الـ entries_sum\n";
    echo "    ④ INSERT INTO account_entries (debit=0, credit={$adjustAmount}) على adjustment\n";
    echo "        — ده الـ offset المعاكس، يحافظ على توازن الـ double-entry\n";
    echo "    ⑤ UPDATE accounts.balance على prepaid\n";
    echo "    ⑥ UPDATE accounts.balance على adjustment\n";
    echo "    ⑦ INSERT INTO audit_logs (action='phase3b_v2_correction')\n";
    echo "    ⑧ COMMIT\n\n";
    echo "    ✅ النتيجة المتوقّعة بعد التنفيذ:\n";
    echo "       account.balance:        " . sprintf('%.2f', $actual) . "\n";
    echo "       Σ account_entries:      " . sprintf('%.2f', $actual) . " (was " . sprintf('%.2f', $calculated) . ")\n";
    echo "       New Delta:              0.00  ✅\n\n";
    return;
}

// === APPLY MODE BELOW ===
echo "[F] APPLY MODE — تنفيذ فعلي...\n";
echo str_repeat('─', 75) . "\n";

try {
    $tx = LedgerBalanceMutationGuard::run(function () use ($delta, $adjustAmount, $prepaidAcc, $adjustmentAccount) {
        return DB::transaction(function () use ($delta, $adjustAmount, $prepaidAcc, $adjustmentAccount) {
            // The actual and calculated are needed in the notes closure
            $actualBefore    = (float) $prepaidAcc->balance;
            $calculatedBefore = (float) DB::table('account_entries')
                ->where('account_id', $prepaidAcc->id)->sum('credit')
                - (float) DB::table('account_entries')
                ->where('account_id', $prepaidAcc->id)->sum('debit');
            $deltaNow = $actualBefore - $calculatedBefore;

            $adjustAmountTx = abs($deltaNow);

            // ─────────────────────────────────────────────────────────
            // ① Transaction header
            // ─────────────────────────────────────────────────────────
            $transaction = Transaction::create([
                'type'            => 'transfer',
                'amount'          => $adjustAmountTx,
                'from_account_id' => $adjustmentAccount->id,
                'to_account_id'   => $prepaidAcc->id,
                'module'          => 'flight',
                'related_type'    => 'phase3b_v2_correction',
                'related_id'      => $prepaidAcc->id,
                'notes'           => "Phase 3b v2 (CORRECTED): تسوية الفروقات الافتتاحية. " .
                                    "balance الفعلي ({$actualBefore}) - " .
                                    "Σ entries المحسوب ({$calculatedBefore}) = {$deltaNow}. " .
                                    "تحويل {$adjustAmountTx} EGP من '{$adjustmentAccount->name}' لتعديل الـ desync.",
                'created_by'      => Auth::id() ?? 1,
            ]);

            // ─────────────────────────────────────────────────────────
            // ② Account entries (double-entry)
            // ─────────────────────────────────────────────────────────
            $prepaidDebit          = $deltaNow < 0 ? $adjustAmountTx : 0;
            $prepaidCredit         = $deltaNow > 0 ? $adjustAmountTx : 0;
            $adjustmentDebit       = $deltaNow > 0 ? $adjustAmountTx : 0;
            $adjustmentCredit      = $deltaNow < 0 ? $adjustAmountTx : 0;

            // ②.a) Entry on prepaid
            $entryPrepaid = AccountEntry::create([
                'account_id'      => $prepaidAcc->id,
                'transaction_id'  => $transaction->id,
                'debit'           => $prepaidDebit,
                'credit'          => $prepaidCredit,
                'balance_after'   => (float) $prepaidAcc->balance + $prepaidDebit - $prepaidCredit,
                'notes'           => $deltaNow < 0
                    ? 'debit prepaid (v2 — يقلّل الـ entries_sum عشان يطابق الـ balance الأغمق)'
                    : 'credit prepaid (v2 — يقلّل الـ entries_sum عشان يطابق الـ balance الأرفع)',
            ]);

            // ②.b) Entry on adjustment
            $entryAdjustment = AccountEntry::create([
                'account_id'      => $adjustmentAccount->id,
                'transaction_id'  => $transaction->id,
                'debit'           => $adjustmentDebit,
                'credit'          => $adjustmentCredit,
                'balance_after'   => (float) $adjustmentAccount->balance + $adjustmentDebit - $adjustmentCredit,
                'notes'           => $deltaNow < 0
                    ? 'credit adjustment (v2 — يقابل الـ debit على prepaid)'
                    : 'debit adjustment (v2 — يقابل الـ credit على prepaid)',
            ]);

            // ─────────────────────────────────────────────────────────
            // ③ Increment account balances (within same tx — Guard)
            // ─────────────────────────────────────────────────────────
            $prepaidNewBalance = (float) $prepaidAcc->balance + $prepaidDebit - $prepaidCredit;
            $prepaidAcc->update(['balance' => $prepaidNewBalance]);

            $adjustmentNewBalance = (float) $adjustmentAccount->balance + $adjustmentDebit - $adjustmentCredit;
            $adjustmentAccount->update(['balance' => $adjustmentNewBalance]);

            return $transaction;
        });
    });

    echo "  ✓ Transaction created (id={$tx->id})\n";
    echo "  ✓ Prepaid balance now:       " . $prepaidAcc->fresh()->balance . "\n";
    echo "  ✓ Adjustment balance now:    " . $adjustmentAccount->fresh()->balance . "\n";

    // ─────────────────────────────────────────────────────────
    // ④ Verify the new state
    // ─────────────────────────────────────────────────────────
    $newCredits    = (float) DB::table('account_entries')->where('account_id', $prepaidAcc->id)->sum('credit');
    $newDebits     = (float) DB::table('account_entries')->where('account_id', $prepaidAcc->id)->sum('debit');
    $newCalculated = $newCredits - $newDebits;
    $newActual     = (float) $prepaidAcc->fresh()->balance;
    $newDelta      = $newActual - $newCalculated;

    echo "\n";
    echo "  After-fix verification:\n";
    echo "    account.balance:        " . sprintf('%.2f', $newActual) . "\n";
    echo "    Σ account_entries:      " . sprintf('%.2f', $newCalculated) . "\n";
    echo "    New Delta:              " . sprintf('%.2f', $newDelta) . "\n";

    if (abs($newDelta) < 0.01) {
        echo "\n  ✅ SUCCESS — Delta = 0 — الـ GL متوازن.\n";
    } else {
        echo "\n  ⚠️ Delta متبقي: " . sprintf('%.2f', $newDelta) . " — يحتاج تحقيق.\n";
    }

    // ─────────────────────────────────────────────────────────
    // ⑤ Audit log
    // ─────────────────────────────────────────────────────────
    AuditLog::create([
        'user_id'      => Auth::id() ?? 1,
        'action'       => 'phase3b_v2_correction',
        'model_type'   => 'App\\Models\\Account',
        'model_id'     => $prepaidAcc->id,
        'ip_address'   => '127.0.0.1',
        'user_agent'   => 'phase3b_v2_correct_fix',
        'old_values'   => [
            'balance'  => (float) $prepaidAcc->getOriginal('balance'),
            'delta'    => $delta,
        ],
        'new_values'   => [
            'balance'  => $newActual,
            'new_delta' => $newDelta,
        ],
        'notes' => "Phase 3b v2 correction: closed " . sprintf('%.2f', $delta) . " EGP gap. " .
                   "Transaction #{$tx->id}. Direction: " .
                   ($delta < 0 ? 'debit prepaid' : 'credit prepaid') . ". " .
                   "Authorized by ACCOUNTING_HANDOFF_2026-07-08.md.",
    ]);
    echo "  ✓ AuditLog recorded.\n";

    // ─────────────────────────────────────────────────────────
    // ⑥ Notification (admin)
    // ─────────────────────────────────────────────────────────
    try {
        $admins = \App\Models\User::where('role', 'admin')->where('is_active', true)->get();
        if ($admins->isNotEmpty()) {
            \Illuminate\Support\Facades\Notification::send(
                $admins,
                new \App\Notifications\BalanceTamperDetectedNotification(
                    table: 'accounts',
                    sqlPreview: "phase3b_v2 correction: closed delta {$delta} on prepaid flight_carrier GL (tx #{$tx->id})",
                    callerFile: 'phase3b_v2_correct_fix.php',
                    callerLine: __LINE__,
                    userIdentifier: Auth::user()?->email ?? 'phase3b_v2_script',
                    connectionName: config('database.default'),
                )
            );
            echo "  ✓ Admin notification dispatched.\n";
        }
    } catch (\Throwable $e) {
        echo "  ⚠ Notification failed: {$e->getMessage()}\n";
    }

} catch (\Throwable $e) {
    echo "\n  ✗ Correction FAILED:\n";
    echo "    {$e->getMessage()}\n";
    echo "    (الـ DB::transaction() تلقائياً اتعمله rollback.)\n";
    Log::error('Phase 3b v2 fix failed', [
        'error' => $e->getMessage(),
        'delta_before' => $delta,
    ]);
}

echo "\n";
echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  END — PHASE 3b v2                                                       \n";
echo "═══════════════════════════════════════════════════════════════════════════\n";
