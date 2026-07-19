<?php
/**
 * fix_tx17_balance.php — يُضيف الـ counter-entry لـ TX#17 لتحقيق التوازن
 */
define('LARAVEL_START', microtime(true));
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

echo "\n=== إصلاح TX#17 (قيد التوازن الافتتاحي) ===\n\n";

// ابحث عن المعاملات غير المتوازنة
$unbalanced = DB::select("
    SELECT t.id, t.type, t.module, t.amount,
           COALESCE(SUM(ae.debit), 0) as total_debit,
           COALESCE(SUM(ae.credit), 0) as total_credit,
           ABS(COALESCE(SUM(ae.debit), 0) - COALESCE(SUM(ae.credit), 0)) as diff
    FROM transactions t
    LEFT JOIN account_entries ae ON ae.transaction_id = t.id
    GROUP BY t.id, t.type, t.module, t.amount
    HAVING ABS(COALESCE(SUM(ae.debit), 0) - COALESCE(SUM(ae.credit), 0)) > 0.01
");

if (empty($unbalanced)) {
    echo "  ✅ لا توجد معاملات غير متوازنة!\n";
    exit(0);
}

foreach ($unbalanced as $tx) {
    echo "  TX#{$tx->id} [{$tx->module}] debit={$tx->total_debit} credit={$tx->total_credit} diff={$tx->diff}\n";

    $diff    = (float)$tx->total_credit - (float)$tx->total_debit;
    $txId    = $tx->id;

    // جلب حساب رأس المال الافتتاحي
    $capitalAcc = DB::table('accounts')->where('name', 'رأس المال الافتتاحي')->whereNull('deleted_at')->first();
    if (!$capitalAcc) {
        echo "  ⚠️  حساب رأس المال غير موجود\n";
        continue;
    }

    // احسب الرصيد الحالي للحساب
    $curBal = (float) DB::table('account_entries')
        ->where('account_id', $capitalAcc->id)
        ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) as net')
        ->value('net');

    if ($diff > 0) {
        // دائن > مدين → نحتاج مدين إضافي
        $newBal = $curBal - $diff;
        DB::table('account_entries')->insert([
            'account_id'     => $capitalAcc->id,
            'transaction_id' => $txId,
            'debit'          => $diff,
            'credit'         => 0,
            'balance_after'  => round($newBal, 2),
            'notes'          => 'قيد توازن TX#' . $txId . ' — مدين رأس المال',
            'created_at'     => now(), 'updated_at' => now(),
        ]);
        echo "  ✅ أُضيف counter-entry: debit={$diff} إلى رأس المال #{$capitalAcc->id}\n";
    } else {
        // مدين > دائن → نحتاج دائن إضافي
        $credit = abs($diff);
        $newBal = $curBal + $credit;
        DB::table('account_entries')->insert([
            'account_id'     => $capitalAcc->id,
            'transaction_id' => $txId,
            'debit'          => 0,
            'credit'         => $credit,
            'balance_after'  => round($newBal, 2),
            'notes'          => 'قيد توازن TX#' . $txId . ' — دائن رأس المال',
            'created_at'     => now(), 'updated_at' => now(),
        ]);
        echo "  ✅ أُضيف counter-entry: credit={$credit} إلى رأس المال #{$capitalAcc->id}\n";
    }
}

// تحقق نهائي
echo "\n=== التحقق النهائي ===\n";
$remaining = DB::select("
    SELECT COUNT(*) as cnt
    FROM transactions t
    LEFT JOIN account_entries ae ON ae.transaction_id = t.id
    GROUP BY t.id
    HAVING ABS(COALESCE(SUM(ae.debit),0) - COALESCE(SUM(ae.credit),0)) > 0.01
");
$cnt = count($remaining);
$cnt === 0
    ? print("  ✅ جميع المعاملات متوازنة الآن!\n\n")
    : print("  ⚠️  لا يزال هناك {$cnt} معاملة غير متوازنة\n\n");
