<?php
/**
 * COMPENSATION: إصلاح Acct#27 و Acct#28 بعد V2 (اللي اتعكسوا غلط)
 *
 * ⚠️ المشكلة: V2 عامل الحسابين "cashbox" (debit-normal) لكن فعلياً
 *    "إقفال تكاليف" (#27) و "إقفال مبيعات" (#28) بيتصرفوا credit-normal.
 *
 * الحسابات المتأثرة:
 *   - Acct#27 (إقفال تكاليف): V2 زود 7,425 بدل ما ينقص → لازم ننقص 14,850
 *     عشان نرجع لـ "ما قبل الحجز + المعاملات التانية"
 *   - Acct#28 (إقفال مبيعات): V2 نقص 1,265,550 بدل ما يزيد → لازم نزيد 2,531,100
 *
 * الهدف: كل حساب يرجع للقيمة اللي كانت لو الحجز #14 ما اتعملش أصلاً
 * (مع الحفاظ على المعاملات التانية اللي حصلت بعده)
 *
 * الاستخدام:
 *   1) touch /tmp/del_corp_97F3BD.confirmed
 *   2) php artisan tinker --execute='require "/tmp/del_corp_compensate.php";'
 */

use App\Models\Account;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\DB;

$confirmFile = '/tmp/del_corp_97F3BD.confirmed';

// القيم اللي اتحسبت من V2 output + bal_after للـ entries الأصلية
$compensations = [
    27 => [
        'delta'        => -14850.00,    // نقص 14,850 من الرصيد الحالي
        'pre_v2'       => 99917.69,     // الرصيد قبل V2 (للمرجع)
        'expected_now' => 92492.69,     // القيمة المتوقعة بعد التعويض
        'description'  => 'إقفال تكاليف الطيران — credit-normal فعلياً (V2 زاد بدل ما ينقص)',
    ],
    28 => [
        'delta'        => +2531100.00,  // زود 2,531,100 على الرصيد الحالي
        'pre_v2'       => -1361399.00,  // الرصيد قبل V2 (للمرجع)
        'expected_now' => -95849.00,    // القيمة المتوقعة بعد التعويض
        'description'  => 'إقفال مبيعات الطيران (نظام) — credit-normal فعلياً (V2 نقص بدل ما يزيد)',
    ],
];

echo "\n=========================================================\n";
echo "  COMPENSATION: إصلاح أرصدة Acct#27 و Acct#28\n";
echo "=========================================================\n";

// [0] بوابة الأمان
if (! file_exists($confirmFile)) {
    echo "\n✗ ملف التأكيد غير موجود: {$confirmFile}\n";
    echo "  شغّل: touch {$confirmFile}\n";
    exit(10);
}
echo "  ✓ ملف التأكيد موجود\n";

// [1] عرض الحالة قبل
echo "\n[1] الحالة قبل التعويض\n";
echo "─────────────────────────────────────────────────────────\n";
foreach ($compensations as $acctId => $info) {
    $acc = Account::find($acctId);
    printf("  Acct#%-3d %-40s | balance=%.4f %s | سيتغير بـ %+.4f → %.4f\n",
        $acctId, mb_substr($acc?->name ?? '?', 0, 40),
        (float) $acc?->balance, $acc?->currency ?? '',
        $info['delta'], $info['expected_now']
    );
    printf("        %s\n", $info['description']);
}

// [2] تنفيذ التعويض
echo "\n[2] تنفيذ التعويض (DB::transaction + LedgerBalanceMutationGuard)\n";
echo "─────────────────────────────────────────────────────────\n";

try {
    DB::transaction(function () use ($compensations) {
        LedgerBalanceMutationGuard::run(function () use ($compensations) {
            foreach ($compensations as $acctId => $info) {
                $account = Account::lockForUpdate()->find($acctId);
                if (! $account) {
                    throw new \RuntimeException("Account #$acctId not found");
                }

                $oldBalance = (float) $account->balance;
                $newBalance = $oldBalance + $info['delta'];
                $account->balance = $newBalance;
                $account->save();

                printf("    ✓ Acct#%-3d %-40s | %.4f → %.4f %s (delta: %+.4f)\n",
                    $acctId, mb_substr($account->name, 0, 40),
                    $oldBalance, $newBalance, $account->currency, $info['delta']
                );
            }
        });
    });

    echo "\n  ✓ تم التعويض بنجاح.\n";
} catch (\Throwable $e) {
    echo "\n  ✗ FATAL ERROR: ".$e->getMessage()."\n";
    echo "  → تم عمل ROLLBACK.\n";
    exit(99);
}

// [3] التحقق بعد
echo "\n[3] التحقق بعد التعويض\n";
echo "─────────────────────────────────────────────────────────\n";
foreach ($compensations as $acctId => $info) {
    $acc = Account::find($acctId);
    $actual = (float) $acc->balance;
    $expected = $info['expected_now'];
    $match = abs($actual - $expected) < 0.01;
    printf("  Acct#%-3d %-40s | balance=%.4f %s | expected=%.4f | %s\n",
        $acctId, mb_substr($acc->name, 0, 40),
        $actual, $acc->currency, $expected,
        $match ? '✓ match' : '✗ MISMATCH'
    );
}

// [4] تحقق شامل - باقي الأرصدة
echo "\n[4] تحقق شامل — باقي الحسابات ما اتأثرتش\n";
echo "─────────────────────────────────────────────────────────\n";
$otherChecks = [
    24 => ['name' => 'رصيد مسبق — ناقلو الطيران', 'expected' => -32387.15],
    58 => ['name' => 'ذممة عميل — الشيخ مجدي', 'expected' => 0.00],
];
foreach ($otherChecks as $acctId => $info) {
    $acc = Account::find($acctId);
    $actual = (float) $acc->balance;
    $match = abs($actual - $info['expected']) < 0.01;
    printf("  Acct#%-3d %-40s | balance=%.4f %s | expected=%.4f | %s\n",
        $acctId, mb_substr($acc->name, 0, 40),
        $actual, $acc->currency, $info['expected'],
        $match ? '✓ match' : '✗ MISMATCH'
    );
}

// FlightCarrier check
$carrier = \App\Models\Flight\FlightCarrier::find(3);
if ($carrier) {
    printf("  FlightCarrier#%-3d %-30s | balance=%.4f %s | expected=500.88 | %s\n",
        3, $carrier->name,
        (float) $carrier->balance, $carrier->currency,
        abs((float) $carrier->balance - 500.88) < 0.01 ? '✓ match' : '✗ MISMATCH'
    );
}

echo "\n=========================================================\n";
echo "  ✓ تم إصلاح الأرصدة. الحجز #14 محذوف + الحسابات متظبطة.\n";
echo "=========================================================\n";
echo "  نظّف ملف التأكيد: rm {$confirmFile}\n";
echo "=========================================================\n";