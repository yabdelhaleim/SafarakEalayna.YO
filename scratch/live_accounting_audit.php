<?php

/**
 * مراجعة محاسبية على بيانات MySQL الحية (قراءة + سيناريو مع rollback).
 * التشغيل: php scratch/live_accounting_audit.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Finance\TreasuryService;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$tolerance = 0.02;
$issues = [];
$checks = 0;
$passed = 0;

function check(bool $ok, string $label, string $detail, array &$issues, int &$checks, int &$passed): void
{
    $checks++;
    if ($ok) {
        $passed++;
        echo "  ✅ $label — $detail\n";
    } else {
        $issues[] = "$label: $detail";
        echo "  ❌ $label — $detail\n";
    }
}

echo "\n══════════════════════════════════════════════════════════════\n";
echo "  مراجعة محاسبية على البيانات الحية (MySQL)\n";
echo "  ".now()->toDateTimeString()."\n";
echo "══════════════════════════════════════════════════════════════\n\n";

// ── 0. حجم البيانات ─────────────────────────────────────────────────────────
echo "0. حجم البيانات\n".str_repeat('─', 60)."\n";
$counts = [
    'users' => DB::table('users')->count(),
    'accounts' => DB::table('accounts')->count(),
    'transactions' => DB::table('transactions')->count(),
    'account_entries' => DB::table('account_entries')->count(),
    'bus_bookings' => DB::table('bus_bookings')->count(),
    'fawry_transactions' => DB::table('fawry_transactions')->count(),
    'online_transactions' => DB::table('online_transactions')->count(),
    'wallet_transactions' => DB::table('wallet_transactions')->count(),
];
foreach ($counts as $k => $v) {
    echo sprintf("  %-22s: %s\n", $k, number_format($v));
}
echo "\n";

// ── 1. القيد المزدوج العالمي ────────────────────────────────────────────────
echo "1. توازن القيد المزدوج (كل القيود)\n".str_repeat('─', 60)."\n";
$row = DB::table('account_entries')
    ->selectRaw('SUM(debit) as d, SUM(credit) as c, COUNT(*) as n')
    ->first();
$totalDebit = (float) ($row->d ?? 0);
$totalCredit = (float) ($row->c ?? 0);
$globalDiff = abs($totalDebit - $totalCredit);
check(
    $globalDiff <= $tolerance,
    'إجمالي المدين = الدائن',
    sprintf('مدين=%s | دائن=%s | فرق=%s | سطور=%d', number_format($totalDebit, 2), number_format($totalCredit, 2), number_format($globalDiff, 4), (int) $row->n),
    $issues, $checks, $passed
);

$imbalanced = DB::table('account_entries')
    ->selectRaw('transaction_id, ABS(SUM(debit)-SUM(credit)) as diff')
    ->whereNotNull('transaction_id')
    ->groupBy('transaction_id')
    ->havingRaw('ABS(SUM(debit)-SUM(credit)) > ?', [$tolerance])
    ->count();
check(
    $imbalanced === 0,
    'معاملات غير متوازنة',
    $imbalanced === 0 ? 'لا يوجد' : "عدد المعاملات غير المتوازنة: $imbalanced",
    $issues, $checks, $passed
);

$missingEntries = Transaction::query()->doesntHave('entries')->count();
check(
    $missingEntries === 0,
    'معاملات بدون قيود',
    $missingEntries === 0 ? 'لا يوجد' : "عدد: $missingEntries",
    $issues, $checks, $passed
);

// ── 2. انحراف الأرصدة (stored vs ledger) ────────────────────────────────────
echo "\n2. انحراف أرصدة الحسابات (المخزن vs الدفتر)\n".str_repeat('─', 60)."\n";
$ledgerNet = AccountEntry::query()
    ->selectRaw('account_id, SUM(COALESCE(credit,0) - COALESCE(debit,0)) AS net')
    ->groupBy('account_id')
    ->pluck('net', 'account_id');

$drifts = [];
foreach (Account::query()->get(['id', 'name', 'type', 'balance', 'is_active']) as $acc) {
    $ledger = round((float) ($ledgerNet[$acc->id] ?? 0), 2);
    $stored = round((float) $acc->balance, 2);
    $diff = round($stored - $ledger, 2);
    if (abs($diff) > $tolerance) {
        $drifts[] = [
            'id' => $acc->id,
            'name' => $acc->name,
            'stored' => $stored,
            'ledger' => $ledger,
            'diff' => $diff,
        ];
    }
}

$driftRate = $counts['accounts'] > 0 ? round(count($drifts) / $counts['accounts'] * 100, 2) : 0;
check(
    count($drifts) === 0,
    'انحراف الأرصدة',
    count($drifts) === 0
        ? 'كل الحسابات متطابقة مع الدفتر'
        : sprintf('%d حساب من %d (%.2f%%) بها انحراف', count($drifts), $counts['accounts'], $driftRate),
    $issues, $checks, $passed
);

if (count($drifts) > 0) {
    echo "  أعلى 10 انحرافات:\n";
    usort($drifts, fn ($a, $b) => abs($b['diff']) <=> abs($a['diff']));
    foreach (array_slice($drifts, 0, 10) as $d) {
        echo sprintf("    #%d %s | مخزن=%s | دفتر=%s | فرق=%s\n", $d['id'], $d['name'], number_format($d['stored'], 2), number_format($d['ledger'], 2), number_format($d['diff'], 2));
    }
}

$sumStored = round((float) Account::sum('balance'), 2);
$sumLedger = round((float) $ledgerNet->sum(), 2);
$aggDiff = round($sumStored - $sumLedger, 2);
check(
    abs($aggDiff) <= $tolerance,
    'مجموع الأرصدة المخزنة vs الدفتر',
    sprintf('مخزن=%s | دفتر=%s | فرق=%s', number_format($sumStored, 2), number_format($sumLedger, 2), number_format($aggDiff, 2)),
    $issues, $checks, $passed
);

// ── 3. ميزان السياحة ────────────────────────────────────────────────────────
echo "\n3. ميزان حسابات السياحة\n".str_repeat('─', 60)."\n";
$treasury = app(TreasuryService::class);
$tourism = $treasury->getTrialBalance();

$tbCurrent = ($tourism['total_balances'] + $tourism['total_liquidity'] + $tourism['due_to_us']) - $tourism['due_from_us'];
$tbExpected = $tourism['base_capital'] + $tourism['profits'];
check(
    abs($tbCurrent - (float) $tourism['current_capital']) <= $tolerance,
    'معادلة رأس المال الحالي (سياحة)',
    sprintf('محسوب=%s | معروض=%s', number_format($tbCurrent, 2), number_format($tourism['current_capital'], 2)),
    $issues, $checks, $passed
);
check(
    abs($tbExpected - (float) $tourism['expected_capital']) <= $tolerance,
    'معادلة رأس المال المتوقع (سياحة)',
    sprintf('محسوب=%s | معروض=%s', number_format($tbExpected, 2), number_format($tourism['expected_capital'], 2)),
    $issues, $checks, $passed
);
check(
    abs(((float) $tourism['current_capital'] - (float) $tourism['expected_capital']) - (float) $tourism['variance']) <= $tolerance,
    'معادلة الفارق (سياحة)',
    sprintf('فارق=%s | حالة=%s', number_format($tourism['variance'], 2), $tourism['status']),
    $issues, $checks, $passed
);

// ── 4. ميزان المكتب (بدون إنشاء حسابات تلقائية إن أمكن) ─────────────────────
echo "\n4. ميزان حسابات المكتب\n".str_repeat('─', 60)."\n";
$officeOk = true;
$office = null;
try {
    $admin = User::query()->orderBy('id')->first();
    if ($admin) {
        auth()->login($admin);
    }
    $office = $treasury->getOfficeTrialBalance();
    $offCurrent = ($office['total_balances'] + $office['total_liquidity'] + $office['due_to_us']) - $office['due_from_us'];
    $offExpected = $office['base_capital'] + $office['profits'];
    check(
        abs($offCurrent - (float) $office['current_capital']) <= $tolerance,
        'معادلة رأس المال الحالي (مكتب)',
        sprintf('محسوب=%s | معروض=%s', number_format($offCurrent, 2), number_format($office['current_capital'], 2)),
        $issues, $checks, $passed
    );
    check(
        abs($offExpected - (float) $office['expected_capital']) <= $tolerance,
        'معادلة رأس المال المتوقع (مكتب)',
        sprintf('محسوب=%s | معروض=%s', number_format($offExpected, 2), number_format($office['expected_capital'], 2)),
        $issues, $checks, $passed
    );
    check(
        abs(((float) $office['current_capital'] - (float) $office['expected_capital']) - (float) $office['variance']) <= $tolerance,
        'معادلة الفارق (مكتب)',
        sprintf('فارق=%s | حالة=%s', number_format($office['variance'], 2), $office['status']),
        $issues, $checks, $passed
    );
} catch (\Throwable $e) {
    $officeOk = false;
    check(false, 'ميزان المكتب', 'فشل الحساب: '.$e->getMessage(), $issues, $checks, $passed);
}

// ── 5. سيناريو المكتب على البيانات الحية (rollback داخل السكريبت) ───────────
echo "\n5. سيناريو موديولات المكتب (مع rollback — لا يُحفظ)\n".str_repeat('─', 60)."\n";
if (! User::query()->exists()) {
    check(false, 'سيناريو المكتب', 'لا يوجد مستخدم — تخطي السيناريو', $issues, $checks, $passed);
} else {
    $simOutput = shell_exec(PHP_BINARY.' '.escapeshellarg(__DIR__.'/office_module_simulation.php').' 2>&1');
    $simCode = is_string($simOutput) && str_contains($simOutput, '🎉 جميع الاختبارات نجحت') ? 0 : 1;
    if ($simOutput) {
        // اطبع آخر 25 سطر فقط لتجنب الإطالة
        $lines = explode("\n", trim($simOutput));
        echo implode("\n", array_slice($lines, max(0, count($lines) - 25)))."\n";
    }
    check(
        $simCode === 0,
        'سيناريو المكتب الشامل',
        $simCode === 0 ? 'نجح بدون أخطاء محاسبية' : 'فشل — راجع المخرجات أعلاه',
        $issues, $checks, $passed
    );
}

// ── 6. الخلاصة ──────────────────────────────────────────────────────────────
echo "\n══════════════════════════════════════════════════════════════\n";
echo "  الخلاصة\n";
echo "══════════════════════════════════════════════════════════════\n";
$errorRate = $checks > 0 ? round((($checks - $passed) / $checks) * 100, 2) : 0;
$passRate = $checks > 0 ? round(($passed / $checks) * 100, 2) : 0;
echo sprintf("  فحوصات: %d | نجح: %d | فشل: %d\n", $checks, $passed, $checks - $passed);
echo sprintf("  نسبة النجاح: %.2f%% | نسبة الخطأ: %.2f%%\n", $passRate, $errorRate);

if ($tourism && abs((float) $tourism['variance']) > $tolerance) {
    echo sprintf("\n  ⚠️  فارق ميزان السياحة (بيانات حالية): %s EGP — %s\n", number_format($tourism['variance'], 2), $tourism['status']);
    echo "     (قد يكون طبيعياً إذا رأس المال التأسيسي 1,000,000 ولا توجد حركات بعد)\n";
}
if ($office && abs((float) $office['variance']) > $tolerance) {
    echo sprintf("\n  ⚠️  فارق ميزان المكتب (بيانات حالية): %s EGP — %s\n", number_format($office['variance'], 2), $office['status']);
}

if (! empty($issues)) {
    echo "\n  المشاكل المكتشفة:\n";
    foreach ($issues as $i => $issue) {
        echo '    '.($i + 1).". $issue\n";
    }
} else {
    echo "\n  🎉 لا توجد أخطاء محاسبية في البيانات الحالية.\n";
}

echo "\n══════════════════════════════════════════════════════════════\n\n";
exit(empty($issues) ? 0 : 1);
