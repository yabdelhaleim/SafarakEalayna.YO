<?php
/**
 * fix_accounting_desyncs.php
 * 1. يكشف الحسابات التي رصيدها المُخزَّن ≠ SUM(entries)
 * 2. يُصلح الـ desync بإضافة قيد تصحيحي أو تحديث الرصيد
 * 3. يتحقق من التوازن الكامل بعد الإصلاح
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "\n" . str_repeat('=', 60) . "\n";
echo "  إصلاح الـ Desync المحاسبي — SafarakEalayna\n";
echo str_repeat('=', 60) . "\n\n";

// ① كشف الحسابات غير المتزامنة (MySQL-safe: لا alias في HAVING)
$desyncs = DB::select("
    SELECT
        a.id,
        a.name,
        a.type,
        a.balance AS stored_bal,
        COALESCE(SUM(ae.credit) - SUM(ae.debit), 0) AS computed_bal,
        ABS(a.balance - COALESCE(SUM(ae.credit) - SUM(ae.debit), 0)) AS diff_val
    FROM accounts a
    LEFT JOIN account_entries ae ON ae.account_id = a.id
    WHERE a.deleted_at IS NULL
    GROUP BY a.id, a.name, a.type, a.balance
    HAVING ABS(a.balance - COALESCE(SUM(ae.credit) - SUM(ae.debit), 0)) > 0.50
    ORDER BY ABS(a.balance - COALESCE(SUM(ae.credit) - SUM(ae.debit), 0)) DESC
");

if (count($desyncs) === 0) {
    echo "  ✅ لا توجد حسابات غير متزامنة! الميزان نظيف.\n\n";
    exit(0);
}

echo "  [!] وُجد " . count($desyncs) . " حساب غير متزامن:\n\n";
echo "  " . str_pad('ID', 5) . str_pad('النوع', 12) . str_pad('الاسم', 35) .
     str_pad('مُخزَّن', 16) . str_pad('محسوب', 16) . "فرق\n";
echo "  " . str_repeat('-', 90) . "\n";

foreach ($desyncs as $d) {
    echo "  " . str_pad($d->id, 5) . str_pad($d->type, 12) . str_pad(mb_substr($d->name, 0, 32), 35) .
         str_pad(number_format($d->stored_bal, 2), 16) .
         str_pad(number_format($d->computed_bal, 2), 16) .
         number_format($d->diff_val, 2) . "\n";
}
echo "\n";

// ② إصلاح: تحديث account.balance ليطابق SUM(entries)
// الحسابات الجديدة التي لها رصيد ابتدائي بدون entries → نضيف opening entry
echo "  [FIX] بدء الإصلاح التلقائي...\n\n";

$fixed = 0; $skipped = 0;

foreach ($desyncs as $d) {
    $stored   = (float)$d->stored_bal;
    $computed = (float)$d->computed_bal;
    $diff     = $stored - $computed;  // المبلغ المفقود من الـ entries

    // استراتيجية الإصلاح:
    // إذا الحساب ليس له entries على الإطلاق → أضف opening balance entry
    // إذا له entries لكن الرصيد المُخزَّن مختلف → صحّح account.balance مباشرة
    $entryCount = DB::table('account_entries')
        ->where('account_id', $d->id)
        ->count();

    if ($entryCount === 0 && $stored != 0) {
        // حساب له رصيد ابتدائي بدون entries → أضف opening balance
        // Opening: credit للأصول/السيولة، debit للالتزامات/المصروفات
        $isCredit = in_array($d->type, ['cashbox', 'bank', 'wallet', 'owner', 'revenue', 'customer']);
        $debit    = $isCredit ? 0 : abs($stored);
        $credit   = $isCredit ? $stored : 0;

        // للحسابات السالبة (supplier)
        if ($stored < 0) {
            $debit  = abs($stored);
            $credit = 0;
        }

        DB::table('account_entries')->insert([
            'account_id'     => $d->id,
            'transaction_id' => null,
            'debit'          => $debit,
            'credit'         => $credit,
            'balance_after'  => $stored,
            'notes'          => 'رصيد افتتاحي — إصلاح Desync تلقائي',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
        echo "  ✅ #" . str_pad($d->id, 4) . " [{$d->type}] {$d->name}\n";
        echo "       Opening entry: debit={$debit} | credit={$credit} | balance={$stored}\n";
        $fixed++;
    } elseif ($entryCount > 0) {
        // له entries ولكن الرصيد المُخزَّن مختلف → حدّث الرصيد ليطابق SUM
        // هذا أكثر أماناً من إضافة قيد مُصطنع
        DB::table('accounts')->where('id', $d->id)->update([
            'balance'    => round($computed, 2),
            'updated_at' => now(),
        ]);
        echo "  ✅ #" . str_pad($d->id, 4) . " [{$d->type}] {$d->name}\n";
        echo "       Updated balance: {$stored} → {$computed} (diff={$diff})\n";
        $fixed++;
    } else {
        echo "  ⚠️  #" . str_pad($d->id, 4) . " [{$d->type}] {$d->name} — رصيد صفر ولا entries → تخطي\n";
        $skipped++;
    }
}

echo "\n  إصلاح: {$fixed} | تخطي: {$skipped}\n\n";

// ③ التحقق النهائي
echo str_repeat('=', 60) . "\n";
echo "  التحقق بعد الإصلاح:\n";
echo str_repeat('=', 60) . "\n";

$remaining = DB::select("
    SELECT COUNT(*) as cnt
    FROM accounts a
    LEFT JOIN account_entries ae ON ae.account_id = a.id
    WHERE a.deleted_at IS NULL
    GROUP BY a.id, a.balance
    HAVING ABS(a.balance - COALESCE(SUM(ae.credit) - SUM(ae.debit), 0)) > 0.50
");

$remCount = count($remaining);

if ($remCount === 0) {
    echo "  ✅ جميع الحسابات متزامنة الآن!\n";
} else {
    echo "  ⚠️  لا يزال هناك {$remCount} حساب غير متزامن\n";
}

// الميزان الكلي
$totals = DB::table('account_entries')
    ->selectRaw('SUM(debit) as td, SUM(credit) as tc')
    ->first();
$td = round((float)($totals->td ?? 0), 2);
$tc = round((float)($totals->tc ?? 0), 2);
$balDiff = round(abs($td - $tc), 2);

echo "\n  الميزان الكلي:\n";
echo "    مجموع المدين:  " . number_format($td, 2) . " EGP\n";
echo "    مجموع الدائن: " . number_format($tc, 2) . " EGP\n";
echo "    الفرق:        {$balDiff} EGP\n";

if ($balDiff <= 0.01) {
    echo "\n  ✅ الميزان المحاسبي متوازن تماماً!\n";
} else {
    echo "\n  ⚠️  الميزان غير متوازن — فرق {$balDiff} EGP\n";
}

$elapsed = round(microtime(true) - LARAVEL_START, 2);
echo "\n  الوقت: {$elapsed}s\n\n";
