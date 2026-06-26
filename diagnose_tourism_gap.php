<?php
/**
 * ════════════════════════════════════════════════════════════════
 *  سكريبت تشخيص فروق الأرباح — قسم السياحة
 *  المشكلة: 800 جنيه زيادة في ميزان السياحة
 *            أرباح مختلفة بين الداشبورد وقسم السياحة
 * ════════════════════════════════════════════════════════════════
 *  التشغيل على السيرفر:
 *    cd /path/to/project && php diagnose_tourism_gap.php
 * ════════════════════════════════════════════════════════════════
 */

chdir(__DIR__);
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$sep  = str_repeat('=', 65);
$sep2 = str_repeat('-', 65);

echo "\n$sep\n";
echo "  تشخيص فروق الارباح -- قسم السياحة\n";
echo "  التاريخ: " . now()->format('Y-m-d H:i:s') . "\n";
echo "$sep\n\n";

// ============================================================
// 1. الارباح المباشرة من جداول الحجز (المصدر A)
// ============================================================
echo "1. المصدر A: ارباح جداول الحجز المباشرة\n$sep2\n";

$flightDirect = DB::table('flight_bookings')
    ->selectRaw("
        COUNT(*) as cnt,
        SUM(profit) as total_profit,
        SUM(CASE WHEN status NOT IN ('cancelled','refunded','CANCELLED','REFUNDED') THEN profit ELSE 0 END) as active_profit,
        SUM(CASE WHEN status IN ('cancelled','refunded','CANCELLED','REFUNDED') THEN 1 ELSE 0 END) as cancelled_count
    ")->first();

echo "  [FLIGHT] كل الحجوزات: " . number_format($flightDirect->total_profit ?? 0, 2) . " EGP\n";
echo "  [FLIGHT] الفعالة فقط: " . number_format($flightDirect->active_profit ?? 0, 2) . " EGP\n";
echo "  [FLIGHT] ملغية/مستردة: {$flightDirect->cancelled_count}\n";

$hajjDirect = DB::table('hajj_umra_bookings')
    ->selectRaw("
        COUNT(*) as cnt,
        SUM(profit) as total_profit,
        SUM(CASE WHEN status NOT IN ('cancelled','refunded') THEN profit ELSE 0 END) as active_profit
    ")->first();

echo "\n  [HAJJ] كل الحجوزات: " . number_format($hajjDirect->total_profit ?? 0, 2) . " EGP\n";
echo "  [HAJJ] الفعالة فقط: " . number_format($hajjDirect->active_profit ?? 0, 2) . " EGP\n";

$visaDirect = DB::table('visa_bookings')
    ->selectRaw("
        COUNT(*) as cnt,
        SUM(profit) as total_profit,
        SUM(CASE WHEN status NOT IN ('cancelled','refunded') THEN profit ELSE 0 END) as active_profit
    ")->first();

echo "\n  [VISA] كل الحجوزات: " . number_format($visaDirect->total_profit ?? 0, 2) . " EGP\n";
echo "  [VISA] الفعالة فقط: " . number_format($visaDirect->active_profit ?? 0, 2) . " EGP\n";

$sourceA_all    = (float)($flightDirect->total_profit ?? 0) + (float)($hajjDirect->total_profit ?? 0) + (float)($visaDirect->total_profit ?? 0);
$sourceA_active = (float)($flightDirect->active_profit ?? 0) + (float)($hajjDirect->active_profit ?? 0) + (float)($visaDirect->active_profit ?? 0);

echo "\n  [TOTAL A - كل] : " . number_format($sourceA_all, 2) . " EGP\n";
echo "  [TOTAL A - فعال]: " . number_format($sourceA_active, 2) . " EGP\n";

// ============================================================
// 2. الارباح من Ledger (المصدر B) -- مصدر الداشبورد
// ============================================================
echo "\n2. المصدر B: ارباح Ledger (مصدر الداشبورد)\n$sep2\n";

$tourismModules = ['flight', 'hajj_umra', 'visa', 'tourism'];

$ledger = DB::table('transactions')
    ->whereIn('module', $tourismModules)
    ->selectRaw("module, type, COUNT(*) as cnt, SUM(amount) as total")
    ->groupBy('module', 'type')
    ->get();

$incByMod = [];
$expByMod = [];
foreach ($ledger as $row) {
    if ($row->type === 'income') {
        $incByMod[$row->module] = ($incByMod[$row->module] ?? 0) + (float)$row->total;
    } elseif ($row->type === 'expense') {
        $expByMod[$row->module] = ($expByMod[$row->module] ?? 0) + (float)$row->total;
    }
}

$sourceB_income  = 0.0;
$sourceB_expense = 0.0;
foreach ($tourismModules as $mod) {
    $inc  = $incByMod[$mod]  ?? 0;
    $exp  = $expByMod[$mod]  ?? 0;
    $prof = $inc - $exp;
    echo "  [$mod] دخل=" . number_format($inc, 2) . " | تكلفة=" . number_format($exp, 2) . " | ربح=" . number_format($prof, 2) . "\n";
    $sourceB_income  += $inc;
    $sourceB_expense += $exp;
}
$sourceB_profit = $sourceB_income - $sourceB_expense;
echo "\n  [TOTAL B] دخل=" . number_format($sourceB_income, 2) . " | تكلفة=" . number_format($sourceB_expense, 2) . " | ربح=" . number_format($sourceB_profit, 2) . "\n";

// ============================================================
// 3. مقارنة المصدرين
// ============================================================
echo "\n3. مقارنة المصدرين\n$sep2\n";
$gapAll    = $sourceA_all    - $sourceB_profit;
$gapActive = $sourceA_active - $sourceB_profit;
echo "  A (كل الحجوزات) vs B (Ledger) = " . number_format($gapAll, 2) . " EGP\n";
echo "  A (الفعالة فقط) vs B (Ledger) = " . number_format($gapActive, 2) . " EGP\n";

// ============================================================
// 4. حجوزات ملغية بها ربح لم يُعكس
// ============================================================
echo "\n4. حجوزات ملغية لها ربح موجب (لم يُعكس في Ledger)\n$sep2\n";

$cancelledFlight = DB::table('flight_bookings')
    ->whereIn('status', ['cancelled', 'refunded', 'CANCELLED', 'REFUNDED'])
    ->where('profit', '>', 0)
    ->selectRaw("id, status, selling_price, cost_price, profit, created_at")
    ->orderBy('profit', 'desc')
    ->limit(20)
    ->get();

if ($cancelledFlight->isEmpty()) {
    echo "  [FLIGHT] لا توجد حجوزات ملغية بربح موجب\n";
} else {
    $sum = 0;
    echo "  [FLIGHT] " . $cancelledFlight->count() . " حجز ملغي بربح موجب:\n";
    echo "  " . str_pad("ID", 7) . str_pad("status", 18) . str_pad("selling", 12) . str_pad("cost", 12) . str_pad("profit", 12) . "date\n";
    foreach ($cancelledFlight as $b) {
        echo "  " . str_pad($b->id, 7) . str_pad($b->status, 18) . str_pad(number_format($b->selling_price ?? 0, 2), 12) . str_pad(number_format($b->cost_price ?? 0, 2), 12) . str_pad(number_format($b->profit, 2), 12) . $b->created_at . "\n";
        $sum += (float)$b->profit;
    }
    echo "  == اجمالي الربح غير المعكوس: " . number_format($sum, 2) . " EGP ==\n";
}

// حج وعمرة
$cancelledHajj = DB::table('hajj_umra_bookings')
    ->whereIn('status', ['cancelled', 'refunded'])
    ->where('profit', '>', 0)
    ->selectRaw("id, status, profit, created_at")
    ->get();
if (!$cancelledHajj->isEmpty()) {
    $sum = $cancelledHajj->sum('profit');
    echo "\n  [HAJJ] " . $cancelledHajj->count() . " حجز ملغي بربح موجب، اجمالي=" . number_format($sum, 2) . " EGP\n";
}

// ============================================================
// 5. معاملات غير متوازنة في السياحة
// ============================================================
echo "\n5. معاملات غير متوازنة في Ledger (السياحة)\n$sep2\n";

$imbalanced = DB::table('account_entries as ae')
    ->join('transactions as t', 'ae.transaction_id', '=', 't.id')
    ->whereIn('t.module', $tourismModules)
    ->selectRaw('
        ae.transaction_id,
        t.module, t.type, t.amount, t.notes,
        SUM(ae.debit) as d,
        SUM(ae.credit) as c,
        ABS(SUM(ae.debit) - SUM(ae.credit)) as diff
    ')
    ->groupBy('ae.transaction_id', 't.module', 't.type', 't.amount', 't.notes')
    ->havingRaw('diff > 0.01')
    ->orderByRaw('diff DESC')
    ->get();

if ($imbalanced->isEmpty()) {
    echo "  كل معاملات السياحة متوازنة (debit = credit)\n";
} else {
    echo "  " . $imbalanced->count() . " معاملة غير متوازنة:\n";
    foreach ($imbalanced as $r) {
        echo "  tx#{$r->transaction_id} | {$r->module} | {$r->type} | amount={$r->amount} | D={$r->d} | C={$r->c} | diff={$r->diff} | {$r->notes}\n";
    }
}

// ============================================================
// 6. البحث عن مصدر 800 جنيه تحديدا
// ============================================================
echo "\n6. بحث عن مصدر 800 جنيه تحديدا\n$sep2\n";

// حجوزات بربح = 800
$f800 = DB::table('flight_bookings')->whereBetween('profit', [799, 801])->selectRaw('id, status, selling_price, cost_price, profit, created_at')->get();
$h800 = DB::table('hajj_umra_bookings')->whereBetween('profit', [799, 801])->selectRaw('id, status, profit, created_at')->get();
$v800 = DB::table('visa_bookings')->whereBetween('profit', [799, 801])->selectRaw('id, status, profit, created_at')->get();

echo "  حجوزات طيران بربح قريب من 800: " . $f800->count() . "\n";
foreach ($f800 as $b) echo "    => ID#{$b->id} | {$b->status} | sell={$b->selling_price} | cost={$b->cost_price} | profit={$b->profit} | {$b->created_at}\n";

echo "  حجوزات حج بربح قريب من 800: " . $h800->count() . "\n";
foreach ($h800 as $b) echo "    => ID#{$b->id} | {$b->status} | profit={$b->profit} | {$b->created_at}\n";

echo "  حجوزات تاشيرة بربح قريب من 800: " . $v800->count() . "\n";
foreach ($v800 as $b) echo "    => ID#{$b->id} | {$b->status} | profit={$b->profit} | {$b->created_at}\n";

// معاملات ledger بمبلغ قريب من 800
$tx800 = DB::table('transactions')
    ->whereIn('module', $tourismModules)
    ->whereBetween('amount', [795, 805])
    ->selectRaw('id, module, type, amount, notes, created_at')
    ->get();
echo "\n  معاملات Ledger بمبلغ 795-805:\n";
if ($tx800->isEmpty()) echo "  لا يوجد\n";
foreach ($tx800 as $t) echo "  tx#{$t->id} | {$t->module} | {$t->type} | {$t->amount} | {$t->created_at} | {$t->notes}\n";

// ============================================================
// 7. مضاعف حجوزات -- هل في حجز تكرر في الledger اكثر من مرة؟
// ============================================================
echo "\n7. حجوزات مكررة في Ledger (نفس related_id اكثر من مرة)\n$sep2\n";

$duplicateTx = DB::table('transactions')
    ->whereIn('module', $tourismModules)
    ->whereNotNull('related_id')
    ->whereNotNull('related_type')
    ->selectRaw('related_type, related_id, COUNT(*) as cnt, SUM(amount) as total, GROUP_CONCAT(type) as types')
    ->groupBy('related_type', 'related_id')
    ->havingRaw('COUNT(*) > 2')
    ->orderByRaw('cnt DESC')
    ->limit(20)
    ->get();

if ($duplicateTx->isEmpty()) {
    echo "  لا يوجد حجوزات مكررة اكثر من مرتين في Ledger\n";
} else {
    echo "  " . $duplicateTx->count() . " حجوزات مكررة:\n";
    echo "  " . str_pad("related_type", 40) . str_pad("related_id", 12) . str_pad("عدد tx", 8) . str_pad("المبلغ الكلي", 14) . "الانواع\n";
    foreach ($duplicateTx as $r) {
        echo "  " . str_pad($r->related_type, 40) . str_pad($r->related_id, 12) . str_pad($r->cnt, 8) . str_pad(number_format($r->total, 2), 14) . $r->types . "\n";
    }
}

// ============================================================
// 8. اخر 20 حركة في السياحة
// ============================================================
echo "\n8. اخر 20 معاملة في السياحة\n$sep2\n";

$recent = DB::table('transactions')
    ->whereIn('module', $tourismModules)
    ->selectRaw('id, module, type, amount, related_type, related_id, notes, created_at')
    ->orderBy('created_at', 'desc')
    ->limit(20)
    ->get();

echo "  " . str_pad("ID", 7) . str_pad("module", 12) . str_pad("type", 10) . str_pad("amount", 12) . str_pad("related_id", 12) . "created_at\n";
foreach ($recent as $t) {
    echo "  " . str_pad($t->id, 7) . str_pad($t->module, 12) . str_pad($t->type, 10) . str_pad(number_format($t->amount, 2), 12) . str_pad($t->related_id ?? '-', 12) . $t->created_at . "\n";
}

// ============================================================
// 9. انجراف ارصدة الحسابات الرئيسية
// ============================================================
echo "\n9. انجراف ارصدة الحسابات (stored vs ledger_net)\n$sep2\n";

$accountDrift = DB::select("
    SELECT a.id, a.name, a.type, a.balance as stored,
           COALESCE(SUM(ae.credit) - SUM(ae.debit), 0) as ledger_net,
           ABS(a.balance - COALESCE(SUM(ae.credit) - SUM(ae.debit), 0)) as drift
    FROM accounts a
    LEFT JOIN account_entries ae ON ae.account_id = a.id
    WHERE a.is_active = 1
      AND a.type IN ('cashbox','bank','wallet')
    GROUP BY a.id, a.name, a.type, a.balance
    HAVING drift > 0.01
    ORDER BY drift DESC
    LIMIT 20
");

if (empty($accountDrift)) {
    echo "  كل ارصدة الحسابات مطابقة للـ Ledger\n";
} else {
    echo "  " . count($accountDrift) . " حساب بانجراف:\n";
    $totalDrift = 0;
    echo "  " . str_pad("ID", 6) . str_pad("الاسم", 32) . str_pad("stored", 14) . str_pad("ledger_net", 14) . "drift\n";
    foreach ($accountDrift as $acc) {
        $d = (float)$acc->drift;
        $totalDrift += $d;
        echo "  " . str_pad($acc->id, 6) . str_pad(mb_substr($acc->name, 0, 30), 32) . str_pad(number_format($acc->stored, 2), 14) . str_pad(number_format($acc->ledger_net, 2), 14) . number_format($d, 2) . "\n";
    }
    echo "  == اجمالي الانجراف: " . number_format($totalDrift, 2) . " EGP ==\n";
}

// ============================================================
// FINAL SUMMARY
// ============================================================
echo "\n$sep\n";
echo "  ملخص التشخيص النهائي\n";
echo "$sep\n";
echo "  A (جداول - كل) : " . number_format($sourceA_all, 2) . " EGP\n";
echo "  A (جداول - فعال): " . number_format($sourceA_active, 2) . " EGP\n";
echo "  B (Ledger/داشبورد): " . number_format($sourceB_profit, 2) . " EGP\n";
echo "  الفجوة A_كل - B  : " . number_format($gapAll, 2) . " EGP\n";
echo "  الفجوة A_فعال - B: " . number_format($gapActive, 2) . " EGP\n";
echo "  معاملات غير متوازنة: " . $imbalanced->count() . "\n";
echo "$sep\n\n";
echo "  الاوامر البديلة (تشغيلها عبر artisan tinker):\n";
echo "  php artisan tinker\n";
echo '  >>> DB::table("flight_bookings")->whereIn("status",["cancelled","refunded"])->where("profit",">",0)->sum("profit")' . "\n\n";
