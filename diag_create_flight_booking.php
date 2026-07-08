<?php
/**
 * DIAGNOSTIC: تشخيص فشل إنشاء حجز طيران
 *
 * ⚠️ هذا السكربت للقراءة فقط افتراضياً — لا يُنشئ أي حجز فعلي إلا لو
 *    مرّرت --apply على سطر الأوامر (الحجز الفعلي داخل DB::transaction).
 *
 * الاستخدام (وضع dry-run، آمن):
 *   php artisan tinker --execute='require "diag_create_flight_booking.php";'
 *
 * الاستخدام (تنفيذ فعلي بعد مراجعة المخرجات):
 *   php artisan tinker --execute='$argv=["diag","--apply"]; require "diag_create_flight_booking.php";'
 *
 * ما يفعله:
 *   1) يبني payload شبيه بما يرد من الواجهة (frontend)
 *   2) يستدعي FlightBookingService::createBooking()
 *   3) يمسك كل أنواع الاستثناءات ويُظهر:
 *        - نوع الـ Exception
 *        - الرسالة كاملة
 *        - Stack trace
 *        - رقم سطر الفشل داخل FlightBookingService
 *   4) لو نجح، يُظهر ملخص الحجز المُنشأ ويُلغيه فوراً (rollback)
 *
 * المفيد: تشغيله على السيرفر يعطي نفس رسالة الخطأ الظاهرة للمستخدم
 *   لكن مع Stack trace كامل = تحديد السبب الجذري بدل التخمين.
 */

use App\Models\Customer;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\FlightBooking;
use App\Services\Flight\FlightBookingService;
use Illuminate\Support\Facades\DB;

$apply = in_array('--apply', $argv ?? [], true);

echo "\n=========================================================\n";
echo "  DIAGNOSTIC: فشل إنشاء حجز طيران\n";
echo "  الوضع: " . ($apply ? '⚠️ APPLY (سيُنشأ حجز فعلي)' : '✓ DRY-RUN (قراءة فقط)') . "\n";
echo "=========================================================\n";

// ─────────────────────────────────────────────────────────────
// [1] فحص المتطلبات الأساسية
// ─────────────────────────────────────────────────────────────
echo "\n[1] فحص البيئة\n";
echo "─────────────────────────────────────────────────────────\n";

try {
    $dbOk = DB::connection()->getPdo() ? '✓' : '✗';
    echo "  اتصال قاعدة البيانات: {$dbOk}\n";
} catch (\Throwable $e) {
    echo "  ✗ تعذّر الاتصال بقاعدة البيانات: " . $e->getMessage() . "\n";
    exit(1);
}

$customerCount = Customer::count();
$carrierCount  = FlightCarrier::count();
$systemCount   = FlightSystem::count();
printf("  عملاء متاحون:          %d\n", $customerCount);
printf("  ناقلات متاحة:          %d\n", $carrierCount);
printf("  أنظمة طيران متاحة:     %d\n", $systemCount);

if ($customerCount === 0 || ($carrierCount === 0 && $systemCount === 0)) {
    echo "\n  ✗ لا توجد بيانات أساسية كافية (عملاء + ناقل/نظام). أضفها أولاً.\n";
    exit(2);
}

// ─────────────────────────────────────────────────────────────
// [2] بناء Payload تجريبي
// ─────────────────────────────────────────────────────────────
echo "\n[2] Payload المُستخدم في التجربة\n";
echo "─────────────────────────────────────────────────────────\n";

$customer = Customer::orderBy('id')->first();
$carrier  = FlightCarrier::orderBy('id')->first();
$system   = FlightSystem::orderBy('id')->first();

$payload = [
    'customer_id'             => $customer->id,
    'employee_id'             => null,
    'booking_channel_type'    => 'SIGN',
    'booking_channel_provider' => 'SIGN',
    'system_type'             => $system ? 'gds' : 'manual',
    'pnr'                     => null,
    'airline_name'            => 'TestAirline',
    'airline'                 => 'TestAirline',
    'origin'                  => 'CAI',
    'destination'             => 'JED',
    'from_airport'            => 'CAI',
    'to_airport'              => 'JED',
    'departure_date'          => now()->addDays(7)->toDateString(),
    'return_date'             => null,
    'departure_time'          => '10:00',
    'arrival_time'            => '12:30',
    'trip_type'               => 'one_way',
    'passenger_count'         => 1,
    'passengers_count'        => 1,
    'baggage_allowance_kg'    => 0,
    'currency'                => 'EGP',
    'purchase_price'          => 6291.70,
    'selling_price'           => 6435.00,
    'exchange_rate'           => 1.0,
    'flight_system_id'        => $system?->id,
    'flight_carrier_id'       => $carrier?->id,
    'flight_group_id'         => null,
    'account_id'              => null,
    'airline_account_id'      => null,
    'agent_name'              => 'Diagnostic',
    'notes'                   => 'diag_create_flight_booking.php',
    'passengers'              => [
        [
            'first_name' => 'Test',
            'last_name'  => 'User',
            'type'       => 'adult',
            'date_of_birth' => '1990-01-01',
        ],
    ],
    'segments'                => [
        [
            'from_airport' => 'CAI',
            'to_airport'   => 'JED',
            'departure_at' => now()->addDays(7)->setTime(10, 0)->toDateTimeString(),
            'arrival_at'   => now()->addDays(7)->setTime(12, 30)->toDateTimeString(),
            'flight_number' => 'TEST123',
        ],
    ],
    'payment'                 => null,
];

echo "  customer_id      = {$payload['customer_id']} ({$customer->full_name})\n";
echo "  flight_carrier_id= " . ($payload['flight_carrier_id'] ?? 'null') . "\n";
echo "  flight_system_id = " . ($payload['flight_system_id'] ?? 'null') . "\n";
echo "  purchase_price   = {$payload['purchase_price']} EGP\n";
echo "  selling_price    = {$payload['selling_price']} EGP\n";
echo "  profit           = " . ($payload['selling_price'] - $payload['purchase_price']) . " EGP\n";

// ─────────────────────────────────────────────────────────────
// [3] تنفيذ createBooking مع التقاط كل أنواع الاستثناءات
// ─────────────────────────────────────────────────────────────
echo "\n[3] تنفيذ FlightBookingService::createBooking()\n";
echo "─────────────────────────────────────────────────────────\n";

if (! $apply) {
    echo "  وضع DRY-RUN: سأستدعي الخدمة لكن سأعمل rollback فوري.\n";
    echo "  للنفيذ الفعلي: php artisan tinker --execute='\$argv=[\"--apply\"]; require \"diag_create_flight_booking.php\";'\n";
}

$svc   = app(FlightBookingService::class);
$start = microtime(true);

try {
    if ($apply) {
        $booking = $svc->createBooking($payload);
    } else {
        // Dry-run: لو فيه transaction خارجية ستفشل. نشغّلها في transaction خاصة بنا ونعمل rollback.
        $booking = DB::transaction(function () use ($svc, $payload) {
            return $svc->createBooking($payload);
        });
        // rollback يدوي — DB::transaction يلتزم لو لم يحدث استثناء
        DB::rollBack();
    }

    $elapsed = round((microtime(true) - $start) * 1000, 2);
    echo "  ✓ نجح الإنشاء في {$elapsed}ms\n";
    echo "  Booking ID: {$booking->id}\n";
    echo "  Booking Ref: {$booking->booking_reference}\n";

    if ($apply) {
        echo "\n  ⚠️  --apply: حجز فعلي أُنشئ. احذفه من /admin/flight-bookings يدوياً أو عبر del_corp_apply.php.\n";
    }
} catch (\Throwable $e) {
    $elapsed = round((microtime(true) - $start) * 1000, 2);
    echo "\n  ✗ فشل بعد {$elapsed}ms\n";
    echo "  ─────────────────────────────────────────\n";
    echo "  نوع الاستثناء:    " . get_class($e) . "\n";
    echo "  الرسالة (raw):    " . $e->getMessage() . "\n";

    // لو الرسالة ملفوفة بـ "فشل إنشاء الحجز:" نُظهر ما بداخلها
    if (str_starts_with($e->getMessage(), 'فشل إنشاء الحجز: ')) {
        $inner = mb_substr($e->getMessage(), mb_strlen('فشل إنشاء الحجز: '));
        echo "  الرسالة (داخلية): {$inner}\n";
    }

    echo "  الملف:            {$e->getFile()}:{$e->getLine()}\n";

    echo "\n  ── Stack trace (أول 25 إطار) ──\n";
    $trace = $e->getTrace();
    $shown = array_slice($trace, 0, 25);
    foreach ($shown as $i => $f) {
        $file = $f['file'] ?? '?';
        $line = $f['line'] ?? '?';
        $cls  = $f['class'] ?? '';
        $typ  = $f['type'] ?? '';
        $fn   = $f['function'] ?? '?';
        printf("    #%02d %s:%s %s%s%s()\n", $i, $file, $line, $cls, $typ, $fn);
    }

    // لو الخطأ داخل FlightBookingService نحدد الخطوة بدقة
    echo "\n  ── تشخيص داخل FlightBookingService ──\n";
    $flightFrames = array_filter($trace, function ($f) {
        return str_contains($f['file'] ?? '', 'FlightBookingService.php');
    });
    if (count($flightFrames) > 0) {
        $first = array_values($flightFrames)[0];
        echo "  أول إطار داخل FlightBookingService: {$first['file']}:{$first['line']} → {$first['function']}()\n";
        echo "  ➜ راجع الكود حول هذا السطر لتحديد السبب الجذري.\n";
    } else {
        echo "  الاستثناء لم ينشأ داخل FlightBookingService — السبب أعمق.\n";
        echo "  راجع أول إطار في الـ trace.\n";
    }
}

// ─────────────────────────────────────────────────────────────
// [4] ملخص
// ─────────────────────────────────────────────────────────────
echo "\n[4] ملخص\n";
echo "─────────────────────────────────────────────────────────\n";
echo "  ✓ لو ظهرت رسالة 'فشل إنشاء الحجز:' أعلاه، فالسبب الجذري موجود في:\n";
echo "      - FlightBookingService.php → استبدل 'فشل إنشاء الحجز: ' برسالة ودّية للمستخدم\n";
echo "      - يجب أن يحتوي $e->getMessage() على ما يكفي لتشخيص المطور\n";
echo "  ✓ لو ظهر 'SQLSTATE' أو 'Integrity constraint' فالسبب في البيانات المُرسلة\n";
echo "  ✓ لو ظهر 'Insufficient balance' أو 'رصيد غير كافٍ' فالسبب في pool الرصيد\n";
echo "  ✓ لو ظهر نص صيني/إنجليزي طويل فالسبب في طبقة خارجية (TBO/GDS) تستدعيها خطوة غير ظاهرة هنا\n";
echo "\n=========================================================\n";
echo "  ✓ انتهى التشخيص.\n";
echo "=========================================================\n";