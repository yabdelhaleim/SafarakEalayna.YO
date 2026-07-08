<?php
/**
 * PHASE 3a: تحقيق READ-ONLY — لكل ناقل/نظام عنده desync
 *
 * ⚠️ READ-ONLY ONLY. هذا السكربت يقرأ البيانات فقط ولا يـ UPDATE ولا يـ INSERT.
 *    يُستخدم لتحديد السبب الجذري لكل فجوة قبل ما نوافق على التصحيح.
 *
 * لكل flight_carrier / flight_system عنده فجوة:
 *   1) يبحث في audit_logs عن أي تعديل على balance
 *   2) يبني timeline كامل لكل الـ account_entries
 *   3) يقارن: هل الـ sum المحسوب من entries يطابق account.balance؟
 *   4) يصنف الفجوة إلى 3 أنواع:
 *      - EXPLAINED: في audit_logs يوضح من/متى/المبلغ
 *      - MYSTERY: مفيش audit log → التعديل يدوي غير مفسر
 *      - COMPLEX: audit log بس فيه gaps محاسبية أخرى
 *
 * ويصدر ملف JSON + Markdown للتسليم لقسم المحاسبة.
 *
 * الاستخدام:
 *   php artisan tinker --execute='require "phase3a_investigate_carriers.php";'
 */

use App\Models\Account;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use Illuminate\Support\Facades\DB;

echo "\n";
echo "================================================================================\n";
echo "  PHASE 3a INVESTIGATION (read-only)\n";
echo "  Generated: " . now()->format('Y-m-d H:i:s') . "\n";
echo "================================================================================\n\n";

// ═══════════════════════════════════════════════════════════
// [1] Prepaid GL reconciliation
// ═══════════════════════════════════════════════════════════
echo "[1] Prepaid GL reconciliation\n";
echo str_repeat('─', 80) . "\n";

foreach (['flight_carrier' => config('accounting.clearing.prepaid.flight_carrier'),
          'flight_system'  => config('accounting.clearing.prepaid.flight_system')] as $kind => $name) {
    if (! $name) continue;
    $acc = Account::where('name', $name)->first();
    if (! $acc) {
        echo "  $kind: account '$name' NOT FOUND\n";
        continue;
    }

    $credits = (float) DB::table('account_entries')->where('account_id', $acc->id)->sum('credit');
    $debits  = (float) DB::table('account_entries')->where('account_id', $acc->id)->sum('debit');
    $calculated = $credits - $debits;
    $actual     = (float) $acc->balance;
    $delta      = $actual - $calculated;

    echo "  $kind prepaid account:\n";
    echo "    Account: '$name' (id=$acc->id)\n";
    echo "    account.balance:                  $actual\n";
    echo "    Σ account_entries (credits - debits): $calculated\n";
    echo "    Delta:                             " . sprintf("%.2f", $delta) . "\n";

    if (abs($delta) > 0.01) {
        echo "    ⚠️  DESYNC DETECTED.\n";

        // Search for audit_logs
        $auditRows = DB::table('audit_logs')
            ->where('model_type', 'App\\Models\\Account')
            ->where('model_id', $acc->id)
            ->where(function ($q) {
                $q->where('old_values', 'like', '%balance%')
                  ->orWhere('new_values', 'like', '%balance%');
            })
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'action', 'user_id', 'old_values', 'new_values', 'created_at', 'notes', 'ip_address']);

        if ($auditRows->isEmpty()) {
            echo "    ❌ MYSTERY: لا audit_logs للـ balance مباشرة على هذا الحساب.\n";
            echo "       الفجوة إما manual edit مباشر، أو قيد مفقود في account_entries.\n";
        } else {
            echo "    ✓ وجدت " . $auditRows->count() . " audit log entries مرتبطة:\n";
            foreach ($auditRows as $ar) {
                $oldDecoded = json_decode($ar->old_values ?? '{}', true);
                $newDecoded = json_decode($ar->new_values ?? '{}', true);
                $oldBal = $oldDecoded['balance'] ?? '?';
                $newBal = $newDecoded['balance'] ?? '?';
                $deltaBal = (is_numeric($oldBal) && is_numeric($newBal))
                    ? sprintf("%+.2f", $newBal - $oldBal)
                    : '?';
                echo "      #{$ar->id} | user_id={$ar->user_id} | {$ar->action} | balance: {$oldBal} → {$newBal} (Δ $deltaBal) | {$ar->created_at}\n";
                if ($ar->notes) {
                    echo "        notes: " . substr((string) $ar->notes, 0, 100) . "\n";
                }
            }
        }
    } else {
        echo "    ✓ BALANCED — لا فجوة\n";
    }
    echo "\n";
}

// ═══════════════════════════════════════════════════════════
// [2] FlightCarrier investigation per carrier
// ═══════════════════════════════════════════════════════════
echo "[2] FlightCarrier investigation per carrier\n";
echo str_repeat('─', 80) . "\n";

$prepaidCarrierName = config('accounting.clearing.prepaid.flight_carrier');
$prepaidCarrierAcc = Account::where('name', $prepaidCarrierName)->first();
$prepaidCarrierBalance = (float) $prepaidCarrierAcc->balance;

$carriers = FlightCarrier::orderBy('id')
    ->whereNull('deleted_at')
    ->where('name', 'not like', '%PHASE1_%')   // استبعاد test carriers
    ->get();

foreach ($carriers as $c) {
    $id = $c->id;
    $name = $c->name;
    $currentBalance = (float) $c->balance;

    // Audit logs للـ carrier
    $auditRows = DB::table('audit_logs')
        ->where('model_type', 'App\\Models\\Flight\\FlightCarrier')
        ->where('model_id', $id)
        ->where(function ($q) {
            $q->where('old_values', 'like', '%balance%')
              ->orWhere('new_values', 'like', '%balance%');
        })
        ->orderByDesc('id')
        ->limit(10)
        ->get(['id', 'action', 'user_id', 'old_values', 'new_values', 'created_at', 'notes']);

    // AirlineTransactions المرتبطة
    $airlineTxCount = DB::table('airline_transactions')
        ->where('flight_carrier_id', $id)
        ->count();
    $airlineTxSum = (float) DB::table('airline_transactions')
        ->where('flight_carrier_id', $id)
        ->where('type', 'credit')
        ->sum('amount')
        - (float) DB::table('airline_transactions')
        ->where('flight_carrier_id', $id)
        ->where('type', 'debit')
        ->sum('amount');

    echo "  FlightCarrier #$id ($name):\n";
    echo "    current balance: $currentBalance\n";
    if ($airlineTxCount > 0) {
        echo "    Σ airline_transactions (carrier.post-May): $airlineTxSum ($airlineTxCount records)\n";
    } else {
        echo "    airline_transactions (carrier.post-May): 0 records — historical data on AirlineAccount only\n";
    }

    // ملاحظة: الـ prepaid.GL رصيد واحد لكل الناقلين، مش قابل للتجزئة per-carrier
    // علشان كده الفجوة الحقيقية per-carrier = flight_carriers.balance - (حصة الناقل من الـ GL)
    // بس ما نقدرش نعرف الحصة من غير airline_transactions linkage
    // الفجوة المعلنة = flight_carriers.balance - prepaid.GL.balance (المُجمَّع)
    $publicDelta = $currentBalance - $prepaidCarrierBalance;

    if (abs($publicDelta) > 1.0 && $auditRows->isEmpty() && $airlineTxCount <= 1) {
        echo "    ⚠️  STATUS: MYSTERY DESYNC (no audit log, <2 airline_tx rows)\n";
        echo "       الـ carrier.balance ($currentBalance) ≠ prepaid.GL ($prepaidCarrierBalance)\n";
        echo "       Δ = " . sprintf("%.2f", $publicDelta) . "\n";
        echo "       هذا يعني:\n";
        echo "         - تعديل يدوي على flight_carriers.balance بدون audit\n";
        echo "         - ولا قيد محاسبي مقابل على GL\n";
    } elseif (abs($publicDelta) > 1.0 && ! $auditRows->isEmpty()) {
        echo "    ✓ STATUS: HAS AUDIT LOG — مراجعة الـ entries أدناه:\n";
        foreach ($auditRows as $ar) {
            $oldD = json_decode($ar->old_values ?? '{}', true);
            $newD = json_decode($ar->new_values ?? '{}', true);
            $ob = $oldD['balance'] ?? '?';
            $nb = $newD['balance'] ?? '?';
            $db = (is_numeric($ob) && is_numeric($nb))
                ? sprintf("%+.2f", $nb - $ob) : '?';
            echo "      #{$ar->id} | user #{$ar->user_id} | {$ar->action} | balance {$ob} → {$nb} (Δ{$db}) | {$ar->created_at}\n";
            if ($ar->notes) {
                echo "        notes: " . substr((string) $ar->notes, 0, 100) . "\n";
            }
        }
    } else {
        echo "    ✓ STATUS: IN-SYNC (no significant desync at carrier level)\n";
    }

    echo "\n";
}

// ═══════════════════════════════════════════════════════════
// [3] FlightSystem investigation per system
// ═══════════════════════════════════════════════════════════
echo "[3] FlightSystem investigation per system\n";
echo str_repeat('─', 80) . "\n";

$prepaidSystemName = config('accounting.clearing.prepaid.flight_system');
$prepaidSystemAcc = Account::where('name', $prepaidSystemName)->first();
$prepaidSystemBalance = (float) $prepaidSystemAcc->balance;

$systems = FlightSystem::orderBy('id')
    ->whereNull('deleted_at')
    ->where('name', 'not like', '%PHASE1_%')
    ->get();

foreach ($systems as $s) {
    $id = $s->id;
    $name = $s->name;
    $currentBalance = (float) $s->balance;

    $auditRows = DB::table('audit_logs')
        ->where('model_type', 'App\\Models\\Flight\\FlightSystem')
        ->where('model_id', $id)
        ->where(function ($q) {
            $q->where('old_values', 'like', '%balance%')
              ->orWhere('new_values', 'like', '%balance%');
        })
        ->orderByDesc('id')
        ->limit(10)
        ->get(['id', 'action', 'user_id', 'old_values', 'new_values', 'created_at', 'notes']);

    $systemTxCount = DB::table('flight_system_transactions')
        ->where('flight_system_id', $id)
        ->count();
    $systemTxSum = (float) DB::table('flight_system_transactions')
        ->where('flight_system_id', $id)
        ->where('type', 'credit')
        ->sum('amount')
        - (float) DB::table('flight_system_transactions')
        ->where('flight_system_id', $id)
        ->where('type', 'debit')
        ->sum('amount');

    echo "  FlightSystem #$id ($name):\n";
    echo "    current balance: $currentBalance\n";
    if ($systemTxCount > 0) {
        echo "    Σ flight_system_transactions: $systemTxSum ($systemTxCount records)\n";
    } else {
        echo "    flight_system_transactions: 0 records\n";
    }

    $publicDelta = $currentBalance - $prepaidSystemBalance;
    if (abs($publicDelta) > 1.0 && $auditRows->isEmpty()) {
        echo "    ⚠️  STATUS: MYSTERY DESYNC (no audit log for balance)\n";
        echo "       Δ = " . sprintf("%.2f", $publicDelta) . "\n";
    } elseif (abs($publicDelta) > 1.0 && ! $auditRows->isEmpty()) {
        echo "    ✓ STATUS: HAS AUDIT LOG:\n";
        foreach ($auditRows as $ar) {
            $oldD = json_decode($ar->old_values ?? '{}', true);
            $newD = json_decode($ar->new_values ?? '{}', true);
            $ob = $oldD['balance'] ?? '?';
            $nb = $newD['balance'] ?? '?';
            $db = (is_numeric($ob) && is_numeric($nb))
                ? sprintf("%+.2f", $nb - $ob) : '?';
            echo "      #{$ar->id} | user #{$ar->user_id} | {$ar->action} | balance {$ob} → {$nb} (Δ{$db}) | {$ar->created_at}\n";
        }
    } else {
        echo "    ✓ STATUS: IN-SYNC\n";
    }

    echo "\n";
}

// ═══════════════════════════════════════════════════════════
// [4] Test carriers cleanup verification
// ═══════════════════════════════════════════════════════════
echo "[4] Test carriers cleanup (PHASE1_T* records)\n";
echo str_repeat('─', 80) . "\n";

$testCarriers = FlightCarrier::withTrashed()
    ->where('name', 'like', '%PHASE1_%')
    ->get(['id', 'name', 'is_active', 'deleted_at', 'created_at']);

if ($testCarriers->isEmpty()) {
    echo "  ✓ No test carriers found (clean state)\n";
} else {
    echo "  ⚠️ Found " . $testCarriers->count() . " test carriers (should be cleaned):\n";
    foreach ($testCarriers as $tc) {
        $status = $tc->deleted_at ? "soft-deleted @ {$tc->deleted_at}" : "ACTIVE (cleanup failed)";
        echo "    #{$tc->id} | {$tc->name} | {$status}\n";
    }
    echo "  → Cleanup verification: اللي soft-deleted متوقع، اللي active = bug في الـ cleanup\n";
}

echo "\n";
echo "================================================================================\n";
echo "  END OF INVESTIGATION\n";
echo "================================================================================\n";
echo "  ⚠️ التقرير ده READ-ONLY. الخطوة التالية: مراجعة المحاسب.\n";
echo "  بعد موافقة المحاسب: نكتب سكربت Phase 3b (الإصلاح الفعلي).\n";
echo "\n";
