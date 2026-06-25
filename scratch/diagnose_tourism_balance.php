<?php

/**
 * تشخيص ميزان السياحة على الإنتاج — يفرّق بين خطأ محاسبي وإعداد رأس المال.
 * التشغيل على السيرفر: php scratch/diagnose_tourism_balance.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Account;
use App\Models\AccountEntry;
use App\Services\Finance\TreasuryService;
use App\Services\Reports\ProfitLossReportService;
use App\Services\Setting\PrintSettingService;
use Illuminate\Support\Facades\DB;

$tolerance = 0.05;
$treasury = app(TreasuryService::class);
$tb = $treasury->getTrialBalance();
$pl = app(ProfitLossReportService::class)->report(['category' => 'tourism']);
$ps = app(PrintSettingService::class)->get();

echo "\n══════════════════════════════════════════════════════════════\n";
echo "  تشخيص حسابات قطاع السياحة\n";
echo "  ".now()->toDateTimeString()."\n";
echo "══════════════════════════════════════════════════════════════\n\n";

// ── 1. هل القيد المزدوج سليم؟ (هذا خطأ حقيقي إن فشل) ─────────────────────
$row = DB::table('account_entries')->selectRaw('SUM(debit) as d, SUM(credit) as c')->first();
$debit = (float) ($row->d ?? 0);
$credit = (float) ($row->c ?? 0);
$deDiff = abs($debit - $credit);
$imbalanced = DB::table('account_entries')
    ->selectRaw('transaction_id')
    ->whereNotNull('transaction_id')
    ->groupBy('transaction_id')
    ->havingRaw('ABS(SUM(debit)-SUM(credit)) > ?', [$tolerance])
    ->count();

echo "1️⃣  القيد المزدوج (أخطاء تشغيلية حقيقية)\n".str_repeat('─', 60)."\n";
if ($deDiff <= $tolerance && $imbalanced === 0) {
    echo "  ✅ القيود متوازنة — مدين=".number_format($debit, 2).' | دائن='.number_format($credit, 2)."\n";
    echo "  ← لا يوجد خطأ في تسجيل المعاملات نفسها.\n\n";
} else {
    echo "  ❌ مشكلة في القيد المزدوج!\n";
    echo "     فرق إجمالي: ".number_format($deDiff, 2)." | معاملات غير متوازنة: $imbalanced\n";
    echo "  ← هذا خطأ محاسبي يحتاج إصلاح فوري.\n\n";
}

// ── 2. الأرباح والإيرادات ─────────────────────────────────────────────────
$gross = round($treasury->calculateDynamicProfits('tourism'), 2);
$net = round($treasury->calculateDivisionNetProfits('tourism'), 2);
$opex = round($treasury->calculateOperatingExpenses('tourism'), 2);

echo "2️⃣  الأرباح والإيرادات\n".str_repeat('─', 60)."\n";
echo '  أرباح العمليات (جداول الحجوزات): '.number_format($gross, 2)." EGP\n";
echo '  مصروفات تشغيلية: '.number_format($opex, 2)." EGP\n";
echo '  صافي الأرباح (الميزان): '.number_format($net, 2)." EGP\n";
echo '  مجمل ربح P&L (الدفتر): '.number_format($pl['grossProfit'], 2)." EGP\n";
echo '  إيرادات P&L: '.number_format($pl['totalRevenues'], 2).' | COGS: '.number_format($pl['totalCogs'], 2)."\n";

$profitOk = abs($gross - $tb['gross_profits']) <= $tolerance
    && abs($net - $tb['profits']) <= $tolerance;
if ($profitOk && abs($gross - $pl['grossProfit']) <= max(1.0, $pl['grossProfit'] * 0.02)) {
    echo "  ✅ حساب الأرباح متسق بين الجداول والميزان وP&L.\n\n";
} else {
    echo "  ⚠️  فرق بين مصادر حساب الربح — راجع الحجوزات الملغاة/المستردة.\n\n";
}

// ── 3. معادلة الميزان (الفرق الأحمر في الداشبورد) ─────────────────────────
echo "3️⃣  ميزان مراجعة السياحة — شرح الفارق\n".str_repeat('─', 60)."\n";
echo "\n  المعادلة:\n";
echo "  رأس المال الفعلي = أرصدة الأنظمة + السيولة + لنا − علينا\n";
echo "  رأس المال المتوقع = رأس المال التأسيسي + صافي الأرباح\n";
echo "  الفارق = الفعلي − المتوقع\n\n";

printf("  أرصدة طيران/حج/تأشيرات:  %s EGP\n", number_format($tb['total_balances'], 2));
printf("  سيولة (خزائن/بنوك):       %s EGP\n", number_format($tb['total_liquidity'], 2));
printf("  لنا (ذمم عملاء):          %s EGP\n", number_format($tb['due_to_us'], 2));
printf("  علينا (ديون):             %s EGP\n", number_format($tb['due_from_us'], 2));
printf("  ─────────────────────────────────────\n");
printf("  رأس المال الفعلي:         %s EGP\n", number_format($tb['current_capital'], 2));
printf("  رأس المال التأسيسي:       %s EGP  ← من إعدادات الطباعة\n", number_format($tb['base_capital'], 2));
printf("  + صافي الأرباح:           %s EGP\n", number_format($tb['profits'], 2));
printf("  ─────────────────────────────────────\n");
printf("  رأس المال المتوقع:        %s EGP\n", number_format($tb['expected_capital'], 2));
printf("  الفارق:                   %s EGP  [%s]\n\n", number_format($tb['variance'], 2), $tb['status']);

$calibratedBase = round($tb['current_capital'] - $tb['profits'], 2);
echo "  💡 لو الفارق مُربكك: غالباً رأس المال التأسيسي (".number_format($tb['base_capital'], 2).")\n";
echo "     لا يطابق السيولة الفعلية عند بدء النظام.\n";
echo "     قيمة معايرة مقترحة لإغلاق الفارق: ".number_format($calibratedBase, 2)." EGP\n";
echo "     (من: الخزينة → إعدادات الطباعة → رأس مال السياحة)\n\n";

// ── 4. انحراف الأرصدة الافتتاحية (سبب شائع على الإنتاج) ───────────────────
$tourismModules = ['tourism', 'flights', 'hajj_umra', 'visas'];
$ledgerNet = AccountEntry::query()
    ->selectRaw('account_id, SUM(COALESCE(credit,0) - COALESCE(debit,0)) AS net')
    ->groupBy('account_id')
    ->pluck('net', 'account_id');

$openingDrift = 0.0;
$driftAccounts = [];
foreach (Account::query()->whereIn('module_type', $tourismModules)->get(['id', 'name', 'balance', 'module_type']) as $acc) {
    $stored = round((float) $acc->balance, 2);
    $ledger = round((float) ($ledgerNet[$acc->id] ?? 0), 2);
    $diff = round($stored - $ledger, 2);
    if (abs($diff) > $tolerance) {
        $openingDrift += $diff;
        $driftAccounts[] = ['name' => $acc->name, 'diff' => $diff];
    }
}

echo "4️⃣  أرصدة افتتاحية بدون قيود (ليست خطأ في الحجوزات)\n".str_repeat('─', 60)."\n";
if (empty($driftAccounts)) {
    echo "  ✅ كل أرصدة السياحة متطابقة مع الدفتر.\n\n";
} else {
    usort($driftAccounts, fn ($a, $b) => abs($b['diff']) <=> abs($a['diff']));
    echo '  ⚠️  '.count($driftAccounts)." حساب بها رصيد افتتاحي غير مُسجّل في الدفتر\n";
    echo '  مجموع الانحراف: '.number_format($openingDrift, 2)." EGP\n";
    echo "  (الـ Seeder أو الإدخال اليدوي وضع balance مباشرة بدون قيد افتتاحي)\n";
    echo "  أعلى 5:\n";
    foreach (array_slice($driftAccounts, 0, 5) as $d) {
        echo '    • '.$d['name'].' → فرق '.number_format($d['diff'], 2)." EGP\n";
    }
    echo "\n";
}

// ── 5. الحكم النهائي ──────────────────────────────────────────────────────
echo "══════════════════════════════════════════════════════════════\n";
echo "  الحكم النهائي\n";
echo "══════════════════════════════════════════════════════════════\n\n";

$hasOperationalError = $deDiff > $tolerance || $imbalanced > 0;
$hasVariance = abs($tb['variance']) > 0.01;

if (! $hasOperationalError && ! $hasVariance) {
    echo "  🎉 كل شيء متسق — لا فارق ولا أخطاء.\n";
} elseif (! $hasOperationalError && $hasVariance) {
    echo "  ℹ️  المحاسبة التشغيلية سليمة (القيود والأرباح OK).\n";
    echo "  الفارق في الميزان (".number_format($tb['variance'], 2).") غالباً بسبب:\n";
    echo "     • رأس المال التأسيسي = ".number_format($tb['base_capital'], 2)." غير مطابق للواقع\n";
    if (! empty($driftAccounts)) {
        echo "     • أرصدة افتتاحية بقيمة ~".number_format($openingDrift, 2)." بدون قيود\n";
    }
    echo "\n  هذا ليس خطأ في حجوزات الطيران/الحج/التأشيرات.\n";
    echo "  الحل: ضبط رأس المال التأسيسي إلى ~".number_format($calibratedBase, 2)." EGP\n";
    echo "        أو تسجيل قيد افتتاحي للأرصدة الابتدائية.\n";
} else {
    echo "  ❌ يوجد خطأ محاسبي تشغيلي — راجع القيود غير المتوازنة أولاً.\n";
}

echo "\n══════════════════════════════════════════════════════════════\n";

exit($hasOperationalError ? 1 : 0);
