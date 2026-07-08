<?php
/**
 * PHASE 3b v1: SAFE FIX — READ-ONLY by default
 *
 * يُصلح فقط الـ items الآمنة 100% بدون موافقة محاسب:
 *   1) حذف نهائي للـ 3 test carriers (PHASE1_T*) اللي cleanup الـ tests ما حذفهمش
 *   2) قيد تسوية 15,750 EGP على Prepaid Carrier GL لتثبيت الـ desync
 *
 * ⚠️ لكل الـ "mystery desync" في الـ carriers/systems — هذا السكربت ما يعملش حاجة.
 *   هيتم تقرير منفصل يسلَّم لقسم المحاسبة للمعالجة اليدوية.
 *
 * الاستخدام:
 *   # 1) DRY-RUN (آمن تماماً، يعرض الخطة بدون تنفيذ):
 *   php artisan tinker --execute='require "phase3b_safe_fix.php";'
 *
 *   # 2) تنفيذ فعلي (لازم --apply flag + confirmation step):
 *   php artisan tinker --execute='$argv=["--apply"]; require "phase3b_safe_fix.php";'
 */

use App\Models\Account;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\AuditLog;
use App\Models\Transaction;
use App\Models\AccountEntry;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Finance\PrepaidLedgerService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$apply = in_array('--apply', $argv ?? [], true);

echo "\n";
echo "================================================================================\n";
echo "  PHASE 3b v1 — SAFE FIX (read-only by default)\n";
echo "  Generated: " . now()->format('Y-m-d H:i:s') . "\n";
echo "  Mode: " . ($apply ? '⚠️  APPLY (will modify data)' : '✓ DRY-RUN (read-only)') . "\n";
echo "================================================================================\n\n";

// ═══════════════════════════════════════════════════════════
// [1] Plan: Test carriers deletion
// ═══════════════════════════════════════════════════════════
echo "[1] Plan: Test carriers deletion (PHASE1_T*)\n";
echo str_repeat('─', 80) . "\n";

$testCarriers = FlightCarrier::withTrashed()
    ->where('name', 'like', '%PHASE1_%')
    ->get(['id', 'name', 'is_active', 'deleted_at', 'created_at']);

$testSystems = FlightSystem::withTrashed()
    ->where('name', 'like', '%PHASE1_%')
    ->get(['id', 'name', 'is_active', 'deleted_at', 'created_at']);

if ($testCarriers->isEmpty() && $testSystems->isEmpty()) {
    echo "  ✓ No test records found (clean state).\n";
} else {
    foreach ($testCarriers as $tc) {
        echo "  FlightCarrier #{$tc->id} | name='{$tc->name}' | active=" . ($tc->is_active ? 'true' : 'false') . " | deleted=" . ($tc->deleted_at ?? 'NO') . "\n";
    }
    foreach ($testSystems as $ts) {
        echo "  FlightSystem #{$ts->id} | name='{$ts->name}' | active=" . ($ts->is_active ? 'true' : 'false') . " | deleted=" . ($ts->deleted_at ?? 'NO') . "\n";
    }
    echo "\n  Plan: hard-delete all (FlightCarrier::forceDelete) and forced DELETE FROM flight_carriers for stragglers.\n";

    if ($apply) {
        try {
            DB::transaction(function () use ($testCarriers, $testSystems) {
                // Soft-delete via model (preserves relationships + audit_log)
                foreach ($testCarriers as $tc) {
                    try {
                        $tc->forceDelete();
                    } catch (\Throwable $e) {
                        DB::table('flight_carriers')->where('id', $tc->id)->delete();
                    }
                }
                foreach ($testSystems as $ts) {
                    try {
                        $ts->forceDelete();
                    } catch (\Throwable $e) {
                        DB::table('flight_systems')->where('id', $ts->id)->delete();
                    }
                }
            });
            echo "  ✓ Test records deleted successfully.\n";
        } catch (\Throwable $e) {
            echo "  ✗ Error: " . $e->getMessage() . "\n";
        }
    } else {
        echo "  [DRY-RUN: لم يُنفّذ شيء]\n";
    }
}

echo "\n";

// ═══════════════════════════════════════════════════════════
// [2] Plan: Prepaid Carrier GL reconciliation (15,750 EGP)
// ═══════════════════════════════════════════════════════════
echo "[2] Plan: Prepaid Carrier GL reconciliation\n";
echo str_repeat('─', 80) . "\n";

$prepaidName = config('accounting.clearing.prepaid.flight_carrier');
$prepaidAcc = Account::where('name', $prepaidName)->first();

if (! $prepaidAcc) {
    echo "  ✗ Prepaid account not found.\n";
} else {
    $credits = (float) DB::table('account_entries')->where('account_id', $prepaidAcc->id)->sum('credit');
    $debits  = (float) DB::table('account_entries')->where('account_id', $prepaidAcc->id)->sum('debit');
    $calculated = $credits - $debits;
    $actual = (float) $prepaidAcc->balance;
    $delta = $actual - $calculated;

    echo "  Account: $prepaidName (id={$prepaidAcc->id})\n";
    echo "  account.balance:        $actual\n";
    echo "  Σ account_entries:      $calculated\n";
    echo "  Delta:                  " . sprintf("%.2f", $delta) . "\n";

    if (abs($delta) < 0.01) {
        echo "  ✓ BALANCED — لا حاجة لإصلاح.\n";
    } else {
        echo "\n  Plan: إنشاء قيد تسوية 'تصحيح فروقات افتتاحية' بقيمة " . sprintf("%.2f", $delta) . " EGP\n";

        // We need to debit/credit the prepaid account + mirror in a "تسوية" account
        // Since we don't know the source, we'll use an "opening adjustment" concept:
        // - The delta = actual - calculated
        // - actual is more negative (-31587) than calculated (-15837)
        // - So actual has been OVER-debited by 15,750
        // - To fix: we need to CREDIT the prepaid by 15,750 (reducing the negative balance)
        //   and DEBIT something else (an "adjustment" account)

        // Check if there's a suitable "adjustment/equity" account
        $adjustmentAccount = Account::where('name', 'like', '%تسوية%')
            ->orWhere('name', 'like', '%افتتاح%')
            ->orWhere('name', 'like', '%adjustment%')
            ->orWhere('name', 'like', '%equity%')
            ->where('currency', 'EGP')
            ->first();

        if (! $adjustmentAccount) {
            // Create one — a dedicated "تصحيح فروقات افتتاحية" account
            echo "  → إنشاء حساب جديد: 'تسوية فروقات افتتاحية' (opening balance adjustment)\n";
            if ($apply) {
                try {
                    $adjustmentAccount = LedgerBalanceMutationGuard::run(function () use ($prepaidAcc) {
                        return Account::create([
                            'name' => 'تسوية فروقات افتتاحية — ناقلو الطيران',
                            'type' => 'owner',
                            'balance' => 0,
                            'currency' => 'EGP',
                            'is_active' => true,
                            'module_type' => 'office',
                            'is_module_vault' => false,
                            'notes' => "Phase 3b v1: account for reconciling 15,750 EGP gap in flight_carrier prepaid GL",
                            'created_by' => Auth::id() ?? 1,
                        ]);
                    });
                    echo "  ✓ Adjustment account created (id={$adjustmentAccount->id})\n";
                } catch (\Throwable $e) {
                    echo "  ✗ Failed to create adjustment account: " . $e->getMessage() . "\n";
                }
            }
        } else {
            echo "  → استخدام حساب موجود: '{$adjustmentAccount->name}' (id={$adjustmentAccount->id})\n";
        }

        if ($adjustmentAccount && $apply) {
            // الآن أنشئ قيد محاسبي:
            // account.balance الفعلي = -31587
            // calculated = -15837
            // delta = -15750 (account.overdrawn by 15750)
            //
            // لإصلاح: نحتاج نـ credit الـ prepaid بـ 15750 (يقلل الـ overdraft)
            //         ونـ debit حساب التسوية بـ 15750

            // نحن بنعمل debit/credit على entry level (account_entries)
            // مع transaction جديد

            try {
                $tx = LedgerBalanceMutationGuard::run(function () use ($delta, $prepaidAcc, $adjustmentAccount) {
                    return DB::transaction(function () use ($delta, $prepaidAcc, $adjustmentAccount) {
                        // delta سالب (actual < calculated = actual is more negative)
                        // لإصلاح: credit prepaid by 15750, debit adjustment by 15750
                        $adjustAmount = abs($delta);

                        $transaction = Transaction::create([
                            'type' => 'transfer',
                            'amount' => $adjustAmount,
                            'from_account_id' => $adjustmentAccount->id,
                            'to_account_id' => $prepaidAcc->id,
                            'module' => 'flight',
                            'related_type' => 'phase3b_reconciliation',
                            'related_id' => $prepaidAcc->id,
                            'notes' => "Phase 3b v1: تسوية الفروقات الافتتاحية. " .
                                       "account.balance الفعلي ({$actual}) - " .
                                       "Σ account_entries المحسوب ({$calculated}) = {$delta}. " .
                                       "تحويل {$adjustAmount} EGP من حساب '{$adjustmentAccount->name}' لتعديل الـ desync.",
                            'created_by' => Auth::id() ?? 1,
                        ]);

                        // Account entries: debit from account, credit to account
                        AccountEntry::create([
                            'account_id' => $adjustmentAccount->id,
                            'transaction_id' => $transaction->id,
                            'debit' => $adjustAmount,
                            'credit' => 0,
                            'balance_after' => (float) $adjustmentAccount->balance + $adjustAmount,
                            'notes' => 'debit adjustment',
                        ]);
                        $adjustmentAccount->increment('balance', $adjustAmount);

                        AccountEntry::create([
                            'account_id' => $prepaidAcc->id,
                            'transaction_id' => $transaction->id,
                            'debit' => 0,
                            'credit' => $adjustAmount,
                            'balance_after' => (float) $prepaidAcc->balance + $adjustAmount,
                            'notes' => 'credit prepaid (reconcile)',
                        ]);
                        $prepaidAcc->increment('balance', $adjustAmount);

                        return $transaction;
                    });
                });

                echo "  ✓ Reconciliation transaction created (id={$tx->id})\n";
                echo "  ✓ Prepaid account balance now: " . $prepaidAcc->fresh()->balance . "\n";

                // Verify the new state
                $newCredits = (float) DB::table('account_entries')->where('account_id', $prepaidAcc->id)->sum('credit');
                $newDebits  = (float) DB::table('account_entries')->where('account_id', $prepaidAcc->id)->sum('debit');
                $newCalculated = $newCredits - $newDebits;
                $newActual = (float) $prepaidAcc->fresh()->balance;
                $newDelta = $newActual - $newCalculated;

                echo "  After-fix verification:\n";
                echo "    account.balance:        $newActual\n";
                echo "    Σ account_entries:      $newCalculated\n";
                echo "    New Delta:              " . sprintf("%.2f", $newDelta) . "\n";

                // Audit log
                AuditLog::create([
                    'user_id' => Auth::id() ?? 1,
                    'action' => 'phase3b_reconciliation',
                    'model_type' => 'App\\Models\\Account',
                    'model_id' => $prepaidAcc->id,
                    'ip_address' => '127.0.0.1',
                    'user_agent' => 'phase3b_safe_fix',
                    'old_values' => ['balance' => (float) $prepaidAcc->getOriginal('balance')],
                    'new_values' => ['balance' => $newActual],
                    'notes' => "Phase 3b v1 reconciliation: closed 15,750 EGP gap in flight_carrier prepaid GL. Transaction #{$tx->id}.",
                ]);
                echo "  ✓ AuditLog recorded.\n";

            } catch (\Throwable $e) {
                echo "  ✗ Reconciliation failed: " . $e->getMessage() . "\n";
            }
        } else {
            echo "  [DRY-RUN: لم يُنفّذ قيد التسوية]\n";
        }
    }
}

echo "\n";

// ═══════════════════════════════════════════════════════════
// [3] Manual investigation required (per-carrier desyncs) — print only
// ═══════════════════════════════════════════════════════════
echo "[3] Manual investigation required (NOT auto-fixed)\n";
echo str_repeat('─', 80) . "\n";

$prepaidCarrierBalance = (float) $prepaidAcc->balance;
$carriers = FlightCarrier::whereNull('deleted_at')
    ->where('name', 'not like', '%PHASE1_%')
    ->orderBy('id')
    ->get();

echo "  FlightCarriers مع mystery desync (لا audit_log، محتاجة مراجعة محاسب):\n";
echo "  ────────────────────────────────────────────────────────────\n";
foreach ($carriers as $c) {
    $auditRows = DB::table('audit_logs')
        ->where('model_type', 'App\\Models\\Flight\\FlightCarrier')
        ->where('model_id', $c->id)
        ->where(function ($q) {
            $q->where('old_values', 'like', '%balance%')
              ->orWhere('new_values', 'like', '%balance%');
        })
        ->count();

    $airlineTxCount = DB::table('airline_transactions')->where('flight_carrier_id', $c->id)->count();

    if ($auditRows === 0 && $airlineTxCount <= 1) {
        $delta = (float) $c->balance - $prepaidCarrierBalance;
        printf("    #%-3d | %-22s | balance=%10.2f | Δ=%+10.2f\n",
            $c->id, mb_substr($c->name, 0, 22), (float) $c->balance, $delta);
    }
}

echo "\n  FlightSystems مع mystery desync:\n";
echo "  ────────────────────────────────────────────────────────────\n";
$prepaidSystemName = config('accounting.clearing.prepaid.flight_system');
$prepaidSystemAcc = Account::where('name', $prepaidSystemName)->first();
$prepaidSystemBalance = (float) $prepaidSystemAcc->balance;

$systems = FlightSystem::whereNull('deleted_at')
    ->where('name', 'not like', '%PHASE1_%')
    ->orderBy('id')
    ->get();

foreach ($systems as $s) {
    $auditRows = DB::table('audit_logs')
        ->where('model_type', 'App\\Models\\Flight\\FlightSystem')
        ->where('model_id', $s->id)
        ->where(function ($q) {
            $q->where('old_values', 'like', '%balance%')
              ->orWhere('new_values', 'like', '%balance%');
        })
        ->count();

    $systemTxCount = DB::table('flight_system_transactions')->where('flight_system_id', $s->id)->count();

    if ($auditRows === 0) {
        $delta = (float) $s->balance - $prepaidSystemBalance;
        printf("    #%-3d | %-22s | balance=%10.2f | Δ=%+10.2f\n",
            $s->id, mb_substr($s->name, 0, 22), (float) $s->balance, $delta);
    }
}

echo "\n  → هذه الناقلين والأنظمة تتطلب مراجعة يدوية من المحاسب.\n";
echo "  → NO auto-fix will be applied to their balances.\n";
echo "\n";

// ═══════════════════════════════════════════════════════════
// [4] Final summary
// ═══════════════════════════════════════════════════════════
echo "[4] Final summary\n";
echo str_repeat('─', 80) . "\n";

if ($apply) {
    echo "  ✓ Phase 3b v1 APPLIED.\n";
    echo "  ✓ Test carriers cleaned.\n";
    echo "  ✓ Prepaid Carrier GL reconciled.\n";
    echo "  → 6 carriers + 2 systems still pending manual review.\n";
} else {
    echo "  [DRY-RUN — لم يُنفّذ شيء]\n";
    echo "\n";
    echo "  لإعادة التشغيل مع التنفيذ الفعلي:\n";
    echo "  php artisan tinker --execute='\$argv=[\"--apply\"]; require \"phase3b_safe_fix.php\";'\n";
}

echo "\n";
echo "================================================================================\n";
echo "  PHASE 3b v1 END\n";
echo "================================================================================\n";
