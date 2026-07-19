<?php
/**
 * fix_opening_balance_entry.php
 * يُوازن الـ opening balance entries بإضافة حساب رأس مال افتتاحي
 */
define('LARAVEL_START', microtime(true));
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

echo "\n" . str_repeat('=', 60) . "\n";
echo "  إصلاح توازن الأرصدة الافتتاحية\n";
echo str_repeat('=', 60) . "\n\n";

// الفرق الحالي
$totals = DB::table('account_entries')
    ->selectRaw('COALESCE(SUM(debit),0) as td, COALESCE(SUM(credit),0) as tc')
    ->first();
$td = round((float)$totals->td, 2);
$tc = round((float)$totals->tc, 2);
$diff = round($tc - $td, 2); // موجب = دائن زائد

echo "  قبل الإصلاح:\n";
echo "  مجموع المدين:  " . number_format($td, 2) . "\n";
echo "  مجموع الدائن: " . number_format($tc, 2) . "\n";
echo "  الفرق: " . number_format($diff, 2) . " (" . ($diff > 0 ? 'دائن زائد' : 'مدين زائد') . ")\n\n";

if (abs($diff) <= 0.01) {
    echo "  ✅ الميزان متوازن بالفعل!\n";
    exit(0);
}

// إنشاء حساب رأس المال الافتتاحي إذا لم يكن موجوداً
$capitalAccName = 'رأس المال الافتتاحي';
$capitalAcc = DB::table('accounts')->where('name', $capitalAccName)->whereNull('deleted_at')->first();

if (!$capitalAcc) {
    $capitalAccId = DB::table('accounts')->insertGetId([
        'name'        => $capitalAccName,
        'type'        => 'owner',
        'currency'    => 'EGP',
        'balance'     => $diff, // سيكون مساوياً للفرق
        'is_active'   => 1,
        'owner_type'  => 'owner',
        'module_type' => 'tourism',
        'notes'       => 'حساب رأس المال الافتتاحي — للتوازن المحاسبي',
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);
    echo "  ✅ تم إنشاء حساب رأس المال الافتتاحي #" . $capitalAccId . "\n";
} else {
    $capitalAccId = $capitalAcc->id;
    echo "  ℹ️  حساب رأس المال موجود #" . $capitalAccId . "\n";
}

// إنشاء معاملة توازنية
$txId = DB::table('transactions')->insertGetId([
    'type'       => 'transfer',
    'amount'     => abs($diff),
    'currency'   => 'EGP',
    'module'     => 'general',
    'notes'      => 'قيد توازن الأرصدة الافتتاحية — تلقائي',
    'created_by' => 1,
    'created_at' => now(),
    'updated_at' => now(),
]);

// إضافة القيد المعاكس لتحقيق التوازن
if ($diff > 0) {
    // دائن زائد → نحتاج مدين في حساب رأس المال
    DB::table('account_entries')->insert([
        'account_id'     => $capitalAccId,
        'transaction_id' => $txId,
        'debit'          => abs($diff),
        'credit'         => 0,
        'balance_after'  => $diff,
        'notes'          => 'قيد توازن افتتاحي — مدين رأس المال',
        'created_at'     => now(), 'updated_at' => now(),
    ]);
} else {
    // مدين زائد → نحتاج دائن في حساب رأس المال
    DB::table('account_entries')->insert([
        'account_id'     => $capitalAccId,
        'transaction_id' => $txId,
        'debit'          => 0,
        'credit'         => abs($diff),
        'balance_after'  => $diff,
        'notes'          => 'قيد توازن افتتاحي — دائن رأس المال',
        'created_at'     => now(), 'updated_at' => now(),
    ]);
}

echo "  ✅ قيد التوازن TX#{$txId} تم: " . abs($diff) . " EGP\n\n";

// تحقق نهائي
$after = DB::table('account_entries')
    ->selectRaw('COALESCE(SUM(debit),0) as td, COALESCE(SUM(credit),0) as tc')
    ->first();
$tdAfter = round((float)$after->td, 2);
$tcAfter = round((float)$after->tc, 2);
$diffAfter = round(abs($tdAfter - $tcAfter), 2);

echo str_repeat('=', 60) . "\n";
echo "  بعد الإصلاح:\n";
echo "  مجموع المدين:  " . number_format($tdAfter, 2) . " EGP\n";
echo "  مجموع الدائن: " . number_format($tcAfter, 2) . " EGP\n";
echo "  الفرق: {$diffAfter}\n\n";

$diffAfter <= 0.01
    ? print("  ✅ الميزان متوازن تماماً!\n\n")
    : print("  ⚠️  لا يزال هناك فرق {$diffAfter}\n\n");
