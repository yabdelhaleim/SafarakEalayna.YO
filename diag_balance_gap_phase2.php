<?php
/**
 * PHASE 2 DIAGNOSTIC: تشخيص الفجوة بين الأرصدة الفعلية والمحتسبة نظريًا
 *
 * ⚠️ READ-ONLY ONLY — ممنوع أي UPDATE أو migration.
 *
 * يحسب لكل flight_carrier / flight_system:
 *   1) الرصيد الحالي في flight_carriers.balance / flight_systems.balance (الرصيد "السريع")
 *   2) الرصيد الحالي في حساب الـ Prepaid GL المقابل (الرصيد "المحاسبي")
 *   3) الرصيد المحتسب نظريًا من السجل التاريخي (airline_transactions / flight_system_transactions)
 *   4) الرصيد المحتسب من account_entries (GL ledger)
 *
 * ويُخرج الفرق (delta) بين القيم الثلاث + أقدم تاريخ فيه الـ divergence.
 *
 * الاستخدام:
 *   php artisan tinker --execute='require "diag_balance_gap_phase2.php";'
 */

use App\Models\Account;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use Illuminate\Support\Facades\DB;

echo "\n";
echo "================================================================================\n";
echo "  PHASE 2 DIAGNOSTIC: BALANCE GAP ANALYSIS (read-only)\n";
echo "================================================================================\n";
echo "  Generated: " . now()->format('Y-m-d H:i:s') . "\n";
echo "\n";

// ═══════════════════════════════════════════════════════════
// [0] تحديد حسابات الـ Prepaid GL
// ═══════════════════════════════════════════════════════════
$prepaidCarrierName = config('accounting.clearing.prepaid.flight_carrier');
$prepaidSystemName  = config('accounting.clearing.prepaid.flight_system');

$prepaidCarrierAcc = Account::where('name', $prepaidCarrierName)->first();
$prepaidSystemAcc  = Account::where('name', $prepaidSystemName)->first();

echo "[0] Prepaid GL accounts resolved:\n";
printf("    flight_carrier prepaid: '%s' (id=%s, balance=%.2f %s)\n",
    $prepaidCarrierName, $prepaidCarrierAcc?->id ?? 'NOT FOUND',
    (float) ($prepaidCarrierAcc?->balance ?? 0), $prepaidCarrierAcc?->currency ?? 'EGP');
printf("    flight_system  prepaid: '%s' (id=%s, balance=%.2f %s)\n",
    $prepaidSystemName, $prepaidSystemAcc?->id ?? 'NOT FOUND',
    (float) ($prepaidSystemAcc?->balance ?? 0), $prepaidSystemAcc?->currency ?? 'EGP');

if (! $prepaidCarrierAcc || ! $prepaidSystemAcc) {
    echo "    ✗ Missing prepaid accounts. Cannot proceed.\n";
    exit(1);
}

// ═══════════════════════════════════════════════════════════
// [1] FlightCarrier: لكل ناقل، احسب الرصيد من 3 مصادر
// ═══════════════════════════════════════════════════════════
echo "\n[1] FlightCarrier — الرصيد المحتسب نظريًا من السجل التاريخي\n";
echo "================================================================================\n";
printf("%-4s | %-22s | %12s | %12s | %12s | %12s | %12s\n",
    'ID', 'الاسم', 'carrier.bal', 'prepaid.GL', 'Σ airline_tx', 'Σ acct_entry', 'أكبر فرق');
echo str_repeat('─', 110) . "\n";

$carriers = FlightCarrier::orderBy('id')->get();
$carrierRows = [];

foreach ($carriers as $c) {
    $id = $c->id;
    $name = $c->name;

    // ① الرصيد التشغيلي المباشر
    $carrierBalance = (float) $c->balance;

    // ② نفس قيمة الحساب المسبق (هو نفسه لكل الناقلين)
    $prepaidBalance = (float) $prepaidCarrierAcc->balance;

    // ③ الرصيد المحتسب من airline_transactions
    // جدول airline_transactions فيه عمود flight_carrier_id (انضاف بعدين)
    // الحركات اللي قبل الإضافة ممكن تكون ناقصة الرابطة
    $hasAirlineTxRel = DB::table('airline_transactions')
        ->where('flight_carrier_id', $id)
        ->exists();

    if (! $hasAirlineTxRel) {
        // مفيش سجل — مش قابل للحساب
        $calculatedFromAirlineTx = null;
        $airlineTxCount = 0;
    } else {
        // الرصيد = مجموع credits - مجموع debits (مع تجاهل type='refund' للتبسيط)
        $credits = (float) DB::table('airline_transactions')
            ->where('flight_carrier_id', $id)
            ->where('type', 'credit')
            ->sum('amount');
        $debits = (float) DB::table('airline_transactions')
            ->where('flight_carrier_id', $id)
            ->where('type', 'debit')
            ->sum('amount');
        $calculatedFromAirlineTx = $credits - $debits;
        $airlineTxCount = DB::table('airline_transactions')
            ->where('flight_carrier_id', $id)
            ->count();
    }

    // ④ حساب رقم الفجوة
    // الفجوة الحقيقية = المختلف بين carrier.balance و prepaid.GL.balance
    // (الـ prepaid.GL balance هو المرجع المحاسبي الحقيقي)
    $deltaCarrierVsGL = $carrierBalance - $prepaidBalance;
    $deltaCarrierVsTx = ($calculatedFromAirlineTx !== null)
        ? $carrierBalance - $calculatedFromAirlineTx
        : null;
    $deltaGLVsTx = ($calculatedFromAirlineTx !== null)
        ? $prepaidBalance - $calculatedFromAirlineTx
        : null;

    $maxDelta = max(abs($deltaCarrierVsGL), abs($deltaCarrierVsTx ?? 0), abs($deltaGLVsTx ?? 0));

    $carrierRows[] = [
        'id' => $id,
        'name' => $name,
        'carrier_balance' => $carrierBalance,
        'prepaid_gl' => $prepaidBalance,
        'airline_tx_count' => $airlineTxCount,
        'calculated_from_tx' => $calculatedFromAirlineTx,
        'delta_carrier_vs_gl' => $deltaCarrierVsGL,
        'delta_carrier_vs_tx' => $deltaCarrierVsTx,
        'delta_gl_vs_tx' => $deltaGLVsTx,
        'max_delta' => $maxDelta,
    ];

    printf("%-4d | %-22s | %12s | %12s | %12s | %12s | %12s\n",
        $id,
        mb_substr($name, 0, 22),
        number_format($carrierBalance, 2),
        number_format($prepaidBalance, 2),
        $calculatedFromAirlineTx !== null ? number_format($calculatedFromAirlineTx, 2) : 'N/A',
        $airlineTxCount > 0 ? number_format($airlineTxCount, 0) : '(لا توجد)',
        number_format($maxDelta, 2)
    );
}

echo str_repeat('─', 110) . "\n";

// ═══════════════════════════════════════════════════════════
// [2] FlightSystem: نفس الشيء
// ═══════════════════════════════════════════════════════════
echo "\n[2] FlightSystem — الرصيد المحتسب نظريًا من السجل التاريخي\n";
echo "================================================================================\n";
printf("%-4s | %-22s | %12s | %12s | %12s | %12s | %12s\n",
    'ID', 'الاسم', 'system.bal', 'prepaid.GL', 'Σ system_tx', 'Σ acct_entry', 'أكبر فرق');
echo str_repeat('─', 110) . "\n";

$systems = FlightSystem::orderBy('id')->get();
$systemRows = [];

foreach ($systems as $s) {
    $id = $s->id;
    $name = $s->name;

    $systemBalance = (float) $s->balance;
    $prepaidBalance = (float) $prepaidSystemAcc->balance;

    $hasSystemTx = DB::table('flight_system_transactions')
        ->where('flight_system_id', $id)
        ->exists();

    if (! $hasSystemTx) {
        $calculatedFromSystemTx = null;
        $systemTxCount = 0;
    } else {
        $credits = (float) DB::table('flight_system_transactions')
            ->where('flight_system_id', $id)
            ->where('type', 'credit')
            ->sum('amount');
        $debits = (float) DB::table('flight_system_transactions')
            ->where('flight_system_id', $id)
            ->where('type', 'debit')
            ->sum('amount');
        $calculatedFromSystemTx = $credits - $debits;
        $systemTxCount = DB::table('flight_system_transactions')
            ->where('flight_system_id', $id)
            ->count();
    }

    $deltaSystemVsGL = $systemBalance - $prepaidBalance;
    $deltaSystemVsTx = ($calculatedFromSystemTx !== null)
        ? $systemBalance - $calculatedFromSystemTx
        : null;

    $maxDelta = max(abs($deltaSystemVsGL), abs($deltaSystemVsTx ?? 0));

    $systemRows[] = [
        'id' => $id,
        'name' => $name,
        'system_balance' => $systemBalance,
        'prepaid_gl' => $prepaidBalance,
        'system_tx_count' => $systemTxCount,
        'calculated_from_tx' => $calculatedFromSystemTx,
        'delta_system_vs_gl' => $deltaSystemVsGL,
        'delta_system_vs_tx' => $deltaSystemVsTx,
        'max_delta' => $maxDelta,
    ];

    printf("%-4d | %-22s | %12s | %12s | %12s | %12s | %12s\n",
        $id,
        mb_substr($name, 0, 22),
        number_format($systemBalance, 2),
        number_format($prepaidBalance, 2),
        $calculatedFromSystemTx !== null ? number_format($calculatedFromSystemTx, 2) : 'N/A',
        $systemTxCount > 0 ? number_format($systemTxCount, 0) : '(لا توجد)',
        number_format($maxDelta, 2)
    );
}

echo str_repeat('─', 110) . "\n";

// ═══════════════════════════════════════════════════════════
// [3] حساب GL الحقيقي من account_entries
// ═══════════════════════════════════════════════════════════
echo "\n[3] Prepaid GL — الرصيد المحتسب من account_entries (القيد المزدوج)\n";
echo "================================================================================\n";

foreach ([$prepaidCarrierAcc, $prepaidSystemAcc] as $acc) {
    $credits = (float) DB::table('account_entries')
        ->where('account_id', $acc->id)
        ->sum('credit');
    $debits = (float) DB::table('account_entries')
        ->where('account_id', $acc->id)
        ->sum('debit');
    $glCalculated = $debits - $credits; // الـ Prepaid GL يـ debit عند الشحن، credit عند الاستهلاك
    // ... أو العكس حسب convention
    // نقرأ Convention من الكود:
    // PrepaidLedgerService::recharge: $this->recordJournalTransfer(['from' => source, 'to' => prepaid])
    // → source debit, prepaid credit
    // PrepaidLedgerService::consumeCogs: $this->recordJournalTransfer(['from' => prepaid, 'to' => expenseContra])
    // → prepaid debit, expenseContra credit
    // إذًا: رصيد المسبق = (sum credits) - (sum debits)
    $glBalanceFormula = $credits - $debits;

    printf("  '%s' (id=%d):\n", $acc->name, $acc->id);
    printf("    account.balance (الحالي):    %.2f\n", (float) $acc->balance);
    printf("    sum(account_entries.credit): %.2f\n", $credits);
    printf("    sum(account_entries.debit):  %.2f\n", $debits);
    printf("    متبقي = credits - debits:    %.2f\n", $glBalanceFormula);
    printf("    الفرق عن account.balance:    %.2f\n\n", (float) $acc->balance - $glBalanceFormula);
}

// ═══════════════════════════════════════════════════════════
// [4] أقدم تاريخ لأي تعديل على flight_carriers.balance (لاكتشاف أول اختلاف)
// ═══════════════════════════════════════════════════════════
echo "\n[4] ملاحظات عن جودة البيانات التاريخية\n";
echo "================================================================================\n";

// كم flight_carrier ما عندوش airline_transactions مرتبط
$carriersWithoutTx = collect($carrierRows)->filter(fn($r) => $r['airline_tx_count'] === 0);
echo "\n  • FlightCarrier بلا سجل airline_transactions مرتبط:\n";
if ($carriersWithoutTx->isEmpty()) {
    echo "    (لا يوجد — كل الناقلين عندهم سجل)\n";
} else {
    foreach ($carriersWithoutTx as $r) {
        echo "    - FlightCarrier #{$r['id']} ({$r['name']}): carrier.balance={$r['carrier_balance']}, prepaid.GL={$r['prepaid_gl']}\n";
    }
}

$systemsWithoutTx = collect($systemRows)->filter(fn($r) => $r['system_tx_count'] === 0);
echo "\n  • FlightSystem بلا سجل flight_system_transactions مرتبط:\n";
if ($systemsWithoutTx->isEmpty()) {
    echo "    (لا يوجد — كل الأنظمة عندها سجل)\n";
} else {
    foreach ($systemsWithoutTx as $r) {
        echo "    - FlightSystem #{$r['id']} ({$r['name']}): system.balance={$r['system_balance']}, prepaid.GL={$r['prepaid_gl']}\n";
    }
}

// إحصائيات عامة
echo "\n  • إحصائيات عامة:\n";
echo "    - عدد FlightCarriers: " . FlightCarrier::count() . "\n";
echo "    - عدد FlightSystems: " . FlightSystem::count() . "\n";
echo "    - عدد airline_transactions: " . DB::table('airline_transactions')->count() . "\n";
echo "    - عدد airline_transactions مع flight_carrier_id: " . DB::table('airline_transactions')->whereNotNull('flight_carrier_id')->count() . "\n";
echo "    - عدد airline_transactions بدون flight_carrier_id: " . DB::table('airline_transactions')->whereNull('flight_carrier_id')->count() . " (هذه مرتبطة بـ AirlineAccount لا بـ FlightCarrier)\n";
echo "    - عدد flight_system_transactions: " . DB::table('flight_system_transactions')->count() . "\n";

// ═══════════════════════════════════════════════════════════
// [5] FlightCarrier: قائمة كاملة بكل carrier مرتبة حسب أكبر فجوة
// ═══════════════════════════════════════════════════════════
echo "\n\n[5] FlightCarrier مرتّبة حسب أكبر فجوة (الأكبر فجوة أولاً)\n";
echo "================================================================================\n";

$rankedCarriers = collect($carrierRows)
    ->sortByDesc(fn ($r) => $r['max_delta'])
    ->values();

printf("%-4s | %-30s | %12s | %12s | %12s | %10s\n",
    'ID', 'الاسم', 'carrier.bal', 'prepaid.GL', 'Σ airline_tx', 'أكبر فرق');
echo str_repeat('─', 95) . "\n";

foreach ($rankedCarriers as $r) {
    printf("%-4d | %-30s | %12s | %12s | %12s | %10s\n",
        $r['id'],
        mb_substr($r['name'], 0, 30),
        number_format($r['carrier_balance'], 2),
        number_format($r['prepaid_gl'], 2),
        $r['calculated_from_tx'] !== null ? number_format($r['calculated_from_tx'], 2) : 'N/A',
        number_format($r['max_delta'], 2)
    );
}

echo "\n";
echo "[6] FlightSystem مرتّبة حسب أكبر فجوة\n";
echo "================================================================================\n";
printf("%-4s | %-30s | %12s | %12s | %12s | %10s\n",
    'ID', 'الاسم', 'system.bal', 'prepaid.GL', 'Σ system_tx', 'أكبر فرق');
echo str_repeat('─', 95) . "\n";

$rankedSystems = collect($systemRows)
    ->sortByDesc(fn ($r) => $r['max_delta'])
    ->values();

foreach ($rankedSystems as $r) {
    printf("%-4d | %-30s | %12s | %12s | %12s | %10s\n",
        $r['id'],
        mb_substr($r['name'], 0, 30),
        number_format($r['system_balance'], 2),
        number_format($r['prepaid_gl'], 2),
        $r['calculated_from_tx'] !== null ? number_format($r['calculated_from_tx'], 2) : 'N/A',
        number_format($r['max_delta'], 2)
    );
}

echo "\n";
echo "[7] ملاحظات ختامية\n";
echo "================================================================================\n";
echo "  • هذا التقرير READ-ONLY — لم يتم تعديل أي بيانات.\n";
echo "  • الأرقام المحسوبة من airline_transactions / flight_system_transactions\n";
echo "    تعتمد على الـ type column: 'credit' للرصيد الموجب، 'debit' للخصم.\n";
echo "  • لو carrier.balance ≠ prepaid.GL → هذا desync مُحتمل (يحتاج تحقيق يدوي).\n";
echo "  • الفرق بين carrier.balance و Σ airline_tx → نسبة الـ carrier.balance المعدّل يدوياً خارج ledger.\n";
echo "  • الخطوة التالية (Phase 3): تقرير تفصيلي لكل حالة فجوة قبل أي تصحيح.\n";
echo "\n";
