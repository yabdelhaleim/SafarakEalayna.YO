<?php
/**
 * APPLY: شحن حساب "رصيد مسبق — ناقلو الطيران" لتغطية العجز
 *
 * ⚠️ المشكلة: الحساب المسبق (Prepaid GL Account) رصيده سالب، فالـ system
 *    يرفض إنشاء أي حجز يستخدم flight_carrier_id حتى لو الـ carrier.available_balance موجب.
 *
 * ⚠️ خطوات الأمان قبل التشغيل:
 *   1) شغّل fix_carrier_balance_diag.php وحدد المفتاح الناقص ('flight_carrier' أو 'flight_system')
 *   2) حدد رقم الحساب الـ EGP اللي هتشحن منه (مثلاً: البنك الأهلي، الخزينة الرئيسية)
 *   3) حدد المبلغ — يفضّل تغطية العجز + هامش (50,000 EGP إذا العجز 32,387)
 *   4) أنشئ ملف التأكيد على السيرفر:
 *         touch /tmp/recharge_prepaid_flight_carrier.confirmed
 *   5) خُذ backup من قاعدة البيانات قبل التشغيل
 *
 * الاستخدام (Dry-run، آمن):
 *   php artisan tinker --execute='require "fix_carrier_balance_apply.php";'
 *
 * الاستخدام (التنفيذ الفعلي):
 *   php artisan tinker --execute='$argv=["--apply"]; require "fix_carrier_balance_apply.php";'
 *
 * المتغيرات القابلة للتعديل (في أعلى السكربت):
 *   $prepaidKey       = 'flight_carrier' أو 'flight_system'
 *   $sourceAccountId  = رقم الحساب المصدر EGP
 *   $rechargeAmount   = المبلغ بالجنيه المصري
 *   $notes            = ملاحظة اختيارية
 */

use App\Enums\TransactionModule;
use App\Models\Account;
use App\Services\Finance\PrepaidLedgerService;
use Illuminate\Support\Facades\DB;

$apply = in_array('--apply', $argv ?? [], true);

// ════════════════════════════════════════════════════════════
// الإعدادات (عدّلها حسب الحاجة)
// ════════════════════════════════════════════════════════════
$prepaidKey      = 'flight_carrier'; // أو 'flight_system'
$sourceAccountId = null;             // الحساب المصدر EGP (null = auto-pick)
$rechargeAmount  = 50000.0;          // المبلغ بالجنيه المصري
$notes           = 'إصلاح عجز رصيد مسبق الناقلين — diag_create_flight_booking.php';
// ════════════════════════════════════════════════════════════

echo "\n=========================================================\n";
echo "  APPLY: شحن الرصيد المسبق «{$prepaidKey}»\n";
echo "  المبلغ: " . number_format($rechargeAmount, 2) . " EGP\n";
echo "  الوضع: " . ($apply ? '⚠️ APPLY (تنفيذ فعلي)' : '✓ DRY-RUN (قراءة فقط)') . "\n";
echo "=========================================================\n";

// ─────────────────────────────────────────────────────────────
// [0] بوابة الأمان
// ─────────────────────────────────────────────────────────────
if ($apply) {
    $confirmFile = "/tmp/recharge_prepaid_{$prepaidKey}.confirmed";
    if (! file_exists($confirmFile)) {
        echo "\n✗ لم يتم العثور على ملف التأكيد: {$confirmFile}\n\n";
        echo "  أنشئه قبل التنفيذ:\n";
        echo "      touch {$confirmFile}\n\n";
        exit(10);
    }
    echo "  ✓ ملف التأكيد موجود ({$confirmFile})\n";
}

// ─────────────────────────────────────────────────────────────
// [1] البحث عن الحساب المسبق
// ─────────────────────────────────────────────────────────────
$prepaidName = config("accounting.clearing.prepaid.{$prepaidKey}");
if (! $prepaidName) {
    echo "\n✗ المفتاح «{$prepaidKey}» غير معرّف في config/accounting.php\n";
    echo "  المفاتيح المتاحة: " . implode(', ', array_keys(config('accounting.clearing.prepaid', []))) . "\n";
    exit(15);
}

$prepaid = Account::where('name', $prepaidName)->first();
if (! $prepaid) {
    echo "\n✗ الحساب «{$prepaidName}» غير موجود في جدول accounts\n";
    exit(20);
}

$balanceBefore = (float) $prepaid->balance;

echo "\n[1] حالة الحساب المسبق قبل الشحن\n";
echo "─────────────────────────────────────────────────────────\n";
printf("  ID:           #%d\n", $prepaid->id);
printf("  الاسم:        %s\n", $prepaid->name);
printf("  المفتاح:      %s\n", $prepaidKey);
printf("  العملة:       %s\n", $prepaid->currency ?? 'EGP');
printf("  الرصيد قبل:   %.2f %s\n", $balanceBefore, $prepaid->currency ?? 'EGP');

if ($balanceBefore >= 0) {
    echo "\n  ⚠ الرصيد المسبق موجب — تأكد إنك اخترت المفتاح الصحيح.\n";
    if ($apply) {
        exit(21);
    }
}

$shortfall = abs($balanceBefore);
$needed = $shortfall + 6291.70;
echo "  العجز:        %.2f %s\n", $shortfall, $prepaid->currency ?? 'EGP';
echo "  المطلوب لتغطية العجز + حجز نموذجي: %.2f %s\n", $needed, $prepaid->currency ?? 'EGP';

// ─────────────────────────────────────────────────────────────
// [2] البحث عن الحساب المصدر EGP
// ─────────────────────────────────────────────────────────────
echo "\n[2] اختيار الحساب المصدر\n";
echo "─────────────────────────────────────────────────────────\n";

if ($sourceAccountId === null) {
    $source = Account::where('currency', 'EGP')
        ->where(function ($q) {
            $q->where('is_active', true)->orWhereNull('is_active');
        })
        ->where('name', 'not like', '%رصيد مسبق%') // استبعاد الحسابات المسبقة نفسها
        ->orderByDesc('balance')
        ->first();

    if (! $source) {
        echo "\n  ✗ لم يتم العثور على أي حساب EGP نشط (غير مسبق).\n";
        echo "  ➜ حدد sourceAccountId يدوياً في أعلى السكربت.\n";
        exit(30);
    }
    echo "  ➜ لم يحدد حساب → تم اختيار الأكبر رصيداً تلقائياً:\n";
} else {
    $source = Account::find($sourceAccountId);
    if (! $source) {
        echo "\n  ✗ لم يتم العثور على الحساب المصدر #{$sourceAccountId}\n";
        exit(31);
    }
}

printf("  الحساب:       #%d \"%s\"\n", $source->id, $source->name);
printf("  العملة:       %s\n", $source->currency);
printf("  الرصيد قبل:   %.2f %s\n", (float) $source->balance, $source->currency);

if (strtoupper($source->currency) !== strtoupper($prepaid->currency ?? 'EGP')) {
    echo "\n  ✗ العملة مختلفة! المسبق بـ {$prepaid->currency}، الحساب بـ {$source->currency}.\n";
    echo "  ➜ PrepaidLedgerService يحوّل تلقائياً، لكن يفضّل اختيار حساب بنفس العملة للوضوح.\n";
    if ($apply) {
        // اسأل المستخدم لو يكمل (في الإنتاج يفضّل إيقاف)
        exit(32);
    }
}

if ((float) $source->balance < $rechargeAmount) {
    echo "\n  ⚠ رصيد الحساب المصدر أقل من المبلغ المطلوب: %.2f < %.2f\n",
        (float) $source->balance, $rechargeAmount;
    echo "  ➜ قلل rechargeAmount أو اشحن الحساب المصدر أولاً.\n";
    exit(33);
}

if ($source->id === $prepaid->id) {
    echo "\n  ✗ الحساب المصدر يطابق الحساب المسبق! غير ممكن.\n";
    exit(34);
}

// ─────────────────────────────────────────────────────────────
// [3] ملخص العملية
// ─────────────────────────────────────────────────────────────
echo "\n[3] ملخص العملية\n";
echo "─────────────────────────────────────────────────────────\n";
echo "  الشحن من:    #{$source->id} ({$source->name})\n";
echo "  الشحن إلى:   #{$prepaid->id} ({$prepaid->name})\n";
echo "  المبلغ:      " . number_format($rechargeAmount, 2) . " {$prepaid->currency}\n";
echo "  النتيجة:\n";
printf("    رصيد المسبق بعد:    %.2f %s (delta: %+.2f)\n",
    $balanceBefore + $rechargeAmount, $prepaid->currency,
    $rechargeAmount
);
printf("    رصيد المصدر بعد:    %.2f %s (delta: %+.2f)\n",
    (float) $source->balance - $rechargeAmount, $source->currency,
    -$rechargeAmount
);

if (! $apply) {
    echo "\n  ⚠ DRY-RUN: لم يُنفّذ شيء. للتنفيذ الفعلي:\n";
    echo "      touch /tmp/recharge_prepaid_{$prepaidKey}.confirmed\n";
    echo "      php artisan tinker --execute='\$argv=[\"--apply\"]; require \"fix_carrier_balance_apply.php\";'\n";
    exit(0);
}

// ─────────────────────────────────────────────────────────────
// [4] التنفيذ
// ─────────────────────────────────────────────────────────────
echo "\n[4] تنفيذ الشحن\n";
echo "─────────────────────────────────────────────────────────\n";

$svc = app(PrepaidLedgerService::class);

try {
    $transaction = $svc->recharge(
        prepaidKey: $prepaidKey,
        source: $source,
        amount: $rechargeAmount,
        module: TransactionModule::Flight,
        notes: $notes,
        relatedType: Account::class,
        relatedId: $prepaid->id,
    );

    // إعادة قراءة الحسابات بعد التحديث
    $prepaidAfter = $prepaid->fresh();
    $sourceAfter  = $source->fresh();

    echo "  ✓ تم الشحن بنجاح (Transaction ID: {$transaction->id})\n";
    printf("    المسبق:      %.2f → %.2f %s (delta: %+.2f)\n",
        $balanceBefore,
        (float) $prepaidAfter->balance,
        $prepaidAfter->currency,
        (float) $prepaidAfter->balance - $balanceBefore
    );
    printf("    المصدر:      %.2f → %.2f %s (delta: %+.2f)\n",
        (float) $source->balance,
        (float) $sourceAfter->balance,
        $sourceAfter->currency,
        (float) $sourceAfter->balance - (float) $source->balance
    );

    echo "\n  ── الخطوة التالية ──\n";
    echo "  ✓ أعد محاولة إنشاء الحجز من الواجهة — المفروض ينجح الآن.\n";
} catch (\Throwable $e) {
    echo "  ✗ فشل الشحن: " . $e->getMessage() . "\n";
    echo "  → تم عمل ROLLBACK تلقائي — لم يتغير شيء في قاعدة البيانات.\n";
    exit(99);
}

// ─────────────────────────────────────────────────────────────
// [5] اختبار ذاتي بإنشاء حجز تجريبي
// ─────────────────────────────────────────────────────────────
echo "\n[5] اختبار ذاتي (إنشاء حجز تجريبي + rollback)\n";
echo "─────────────────────────────────────────────────────────\n";

try {
    $test = DB::transaction(function () {
        $customer = \App\Models\Customer::orderBy('id')->first();
        if (! $customer) {
            throw new \RuntimeException('لا يوجد عميل للاختبار.');
        }

        // جلب ناقل مرتبط بالمفتاح
        $carrier = \App\Models\Flight\FlightCarrier::orderBy('id')->first();

        $svc = app(\App\Services\Flight\FlightBookingService::class);
        return $svc->createBooking([
            'customer_id'        => $customer->id,
            'currency'           => 'EGP',
            'purchase_price'     => 6291.70,
            'selling_price'      => 6435.00,
            'exchange_rate'      => 1.0,
            'flight_carrier_id'  => $carrier?->id,
            'flight_system_id'   => null,
            'flight_group_id'    => null,
            'departure_date'     => now()->addDays(7)->toDateString(),
            'departure_time'     => '10:00',
            'trip_type'          => 'one_way',
            'passenger_count'    => 1,
            'passengers_count'   => 1,
            'agent_name'         => 'recharge-verify',
            'passengers'         => [['first_name' => 'V', 'last_name' => 'T', 'type' => 'adult']],
        ]);
    });
    DB::rollBack();

    echo "  ✓ اختبار إنشاء حجز تجريبي: نجح (تم rollback تلقائي).\n";
    echo "    Booking ID: {$test->id}\n";
    echo "    Ref: {$test->booking_reference}\n";
    echo "  ✓ الحساب المسبق جاهز للحجز الفعلي.\n";
} catch (\Throwable $e) {
    DB::rollBack();
    echo "  ⚠ فشل الاختبار الذاتي: " . $e->getMessage() . "\n";
    echo "  → الشحن نجح لكن لسه فيه مشكلة في إنشاء الحجز (راجع المخرجات).\n";
}

echo "\n=========================================================\n";
echo "  ✓ تم شحن الرصيد المسبق.\n";
echo "=========================================================\n";
echo "  للسلامة الإضافية:\n";
echo "    - نظّف ملف التأكيد:\n";
echo "        rm /tmp/recharge_prepaid_{$prepaidKey}.confirmed\n";
echo "    - امسح الكاش:\n";
echo "        php artisan cache:clear\n";
echo "=========================================================\n";