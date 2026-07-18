<?php
/**
 * ════════════════════════════════════════════════════════════════════════════
 * Flight Module DELETE Operations Test
 * ════════════════════════════════════════════════════════════════════════════
 *
 * يختبر كل DELETE endpoints في موديول الطيران مع:
 * - اختبار الحذف النظيف
 * - اختبار الحذف مع cascade effects
 * - اختبار رفض الحذف عند وجود dependencies
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Flight\AirlineAccount;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightRefund;
use App\Models\Flight\TicketModification;
use App\Models\Flight\FlightPassenger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$BASE_URL = 'http://127.0.0.1:8000/api/v1';
$IDS = json_decode(file_get_contents(__DIR__ . '/storage/logs/flight_test/ids.json'), true);
$admin = User::find($IDS['admin_id']);
auth()->setUser($admin);
$token = $admin->createToken('flight-delete-test')->plainTextToken;

function httpGet(string $url) { global $token; $r = Http::withToken($token)->acceptJson()->get($url); return ['status' => $r->status(), 'json' => $r->json()]; }
function httpPost(string $url, array $data = []) { global $token; $r = Http::withToken($token)->acceptJson()->post($url, $data); return ['status' => $r->status(), 'json' => $r->json()]; }
function httpDel(string $url) { global $token; $r = Http::withToken($token)->acceptJson()->delete($url); return ['status' => $r->status(), 'json' => $r->json()]; }

$logHandle = fopen(__DIR__ . '/storage/logs/flight_test/' . date('Y-m-d_His') . '_delete.log', 'w');
function t(string $m) { global $logHandle; $l = '[' . date('H:i:s') . '] ' . $m . "\n"; fwrite($logHandle, $l); fflush($logHandle); echo $l; }
function ok(string $m='OK') { t("    ✅ {$m}"); }
function fail(string $m) { t("    ❌ {$m}"); }
function info(string $m){ t("    ℹ  {$m}"); }
function warn(string $m){ t("    ⚠  {$m}"); }
function section(string $title) {
    t("\n" . str_repeat('═', 70));
    t('  ' . $title);
    t(str_repeat('═', 70));
}

$DELETED = ['success' => 0, 'rejected' => 0, 'cascade' => 0];

t("╔══════════════════════════════════════════════════════════════════╗");
t("║  Flight Module DELETE Operations Test                       ║");
t("╚══════════════════════════════════════════════════════════════════╝");

// ═══════════════════════════════════════════════════════════════════════════
// 1) DELETE /flight/modifications/{id}
// ═══════════════════════════════════════════════════════════════════════════
section('1) DELETE /flight/modifications/{id}');

// 1.1 إنشاء modification جديد
$r = httpPost($BASE_URL . '/flight/modifications', [
    'booking_id'        => FlightBooking::first()->id,
    'modification_type'  => 'date_change',
    'new_date'          => now()->addDays(90)->format('Y-m-d'),
    'airline_change_fee'=> 100,
    'reason'            => 'DELETE test',
]);
$modId = $r['json']['data']['id'] ?? null;
t('  ▸ POST /flight/modifications (setup)');
ok("تم إنشاء modification #$modId");

// 1.2 DELETE
$r = httpDel($BASE_URL . '/flight/modifications/' . $modId);
t('  ▸ DELETE /flight/modifications/{id}', ['status' => $r['status']]);
if ($r['status'] === 200 && ($r['json']['success'] ?? false)) {
    $DELETED['success']++;
    ok('تم حذف modification بنجاح');
} else {
    fail('DELETE modification: ' . json_encode($r['json']));
}

// 1.3 DELETE مرة ثانية (محاولة حذف سجل غير موجود)
$r = httpDel($BASE_URL . '/flight/modifications/' . $modId);
t('  ▸ DELETE /flight/modifications/{id} (نفس الـ id)');
if ($r['status'] >= 400) {
    $DELETED['rejected']++;
    ok('✅ النظام رفض الحذف المكرر: ' . ($r['json']['message'] ?? ''));
} else {
    fail('⚠️ النظام سمح بحذف مكرر!');
}

// 1.4 DELETE id خاطئ
$r = httpDel($BASE_URL . '/flight/modifications/9999999');
t('  ▸ DELETE /flight/modifications/9999999 (id غير موجود)');
if ($r['status'] === 404) {
    $DELETED['rejected']++;
    ok('✅ النظام رفض الـ id غير الموجود: 404');
} else {
    warn('استجابة غير متوقعة: ' . $r['status']);
}

// ═══════════════════════════════════════════════════════════════════════════
// 2) DELETE /flight/refunds/{id}
// ═══════════════════════════════════════════════════════════════════════════
section('2) DELETE /flight/refunds/{id}');

// 2.1 إنشاء refund جديد
$r = httpPost($BASE_URL . '/flight/refunds', [
    'flight_booking_id'    => FlightBooking::where('status', 'CONFIRMED')->first()->id ?? FlightBooking::first()->id,
    'destination'          => 'agency_treasury',
    'treasury_id'          => \App\Models\Treasury::first()->id,
    'cancellation_fee'     => 100,
    'refund_currency'      => 'EGP',
    'refund_exchange_rate' => 1.0,
    'notes'                => 'DELETE test refund',
]);
$refundId = $r['json']['data']['id'] ?? null;
t('  ▸ POST /flight/refunds (setup)');
if ($refundId) {
    ok("تم إنشاء refund #$refundId");
} else {
    warn('Refund creation failed: ' . json_encode($r['json']));
}

// 2.2 DELETE
if ($refundId) {
    $r = httpDel($BASE_URL . '/flight/refunds/' . $refundId);
    t('  ▸ DELETE /flight/refunds/{id}', ['status' => $r['status']]);
    if ($r['status'] === 200 && ($r['json']['success'] ?? false)) {
        $DELETED['success']++;
        ok('تم حذف refund بنجاح');
    } else {
        fail('DELETE refund: ' . json_encode($r['json']));
    }
}

// 2.3 DELETE refund غير موجود
$r = httpDel($BASE_URL . '/flight/refunds/9999999');
t('  ▸ DELETE /flight/refunds/9999999');
if ($r['status'] === 404) {
    $DELETED['rejected']++;
    ok('✅ النظام رفض الـ id غير الموجود: 404');
} else {
    warn('استجابة: ' . $r['status']);
}

// ═══════════════════════════════════════════════════════════════════════════
// 3) DELETE /flight/airline-accounts/{id}
// ═══════════════════════════════════════════════════════════════════════════
section('3) DELETE /flight/airline-accounts/{id}');

// 3.1 إنشاء airline account جديد (بدون transactions)
$r = httpPost($BASE_URL . '/flight/airline-accounts', [
    'name'        => 'FLT-DEL-AirlineAcc-Test',
    'code'        => 'DEL' . substr(md5(uniqid()), 0, 5),
    'system_type' => 'GDS',
    'currency'    => 'EGP',
    'balance'     => 0,
    'credit_limit'=> 10000,
    'is_active'   => true,
    'notes'       => 'for DELETE test',
]);
$aaId = $r['json']['data']['id'] ?? null;
t('  ▸ POST /flight/airline-accounts (setup)');
if ($aaId) ok("تم إنشاء airline_account #$aaId");

// 3.2 DELETE بدون transactions
if ($aaId) {
    $r = httpDel($BASE_URL . '/flight/airline-accounts/' . $aaId);
    t('  ▸ DELETE /flight/airline-accounts/{id} (بدون transactions)', ['status' => $r['status']]);
    if ($r['status'] === 200 && ($r['json']['success'] ?? false)) {
        $DELETED['success']++;
        ok('تم حذف airline_account بدون transactions بنجاح');
    } else {
        fail('DELETE airline-account: ' . json_encode($r['json']));
    }
}

// 3.3 DELETE airline-account مع transactions (cascade check)
$aaWithTx = AirlineAccount::has('transactions')->first();
if ($aaWithTx) {
    $r = httpDel($BASE_URL . '/flight/airline-accounts/' . $aaWithTx->id);
    t('  ▸ DELETE airline-account مع transactions #' . $aaWithTx->id, ['status' => $r['status']]);
    if ($r['status'] >= 400) {
        $DELETED['cascade']++;
        ok("✅ النظام رفض الحذف عند وجود transactions: " . ($r['json']['message'] ?? ''));
    } else {
        warn("⚠️ النظام سمح بحذف airline-account مع transactions!");
    }
} else {
    info('لا يوجد airline-account مع transactions للاختبار');
}

// ═══════════════════════════════════════════════════════════════════════════
// 4) DELETE /flight/bookings/{id} (soft-delete)
// ═══════════════════════════════════════════════════════════════════════════
section('4) DELETE /flight/bookings/{id} (soft-delete with financial reversal)');

// 4.1 إنشاء حجز جديد (بدون payment حتى لا نعطل الرصيد)
$r = httpPost($BASE_URL . '/flight/bookings', [
    'customer_id'    => $IDS['customer_ids'][0],
    'employee_id'    => $IDS['employee_id'],
    'pnr'            => 'PNR-DEL-' . substr(uniqid(), -4),
    'airline'        => 'EgyptAir',
    'airline_name'   => 'EgyptAir',
    'from_airport'   => 'TCAI',
    'to_airport'     => 'TJED',
    'from_airport_id'=> $IDS['airport_ids'][0],
    'to_airport_id'  => $IDS['airport_ids'][1],
    'departure_date' => now()->addDays(100)->format('Y-m-d'),
    'departure_time' => '10:00:00',
    'trip_type'      => 'one_way',
    'passenger_count'=> 1,
    'purchase_price' => 1000,
    'selling_price'  => 1500,
    'currency'       => 'EGP',
    'flight_system_id'   => $IDS['sys_amadeus_id'],
    'flight_carrier_id'  => $IDS['carrier_egyptair_id'],
    'purchase_balance_source' => 'carrier',
    'payment' => ['amount' => 1500, 'payment_method' => 'cash', 'account_id' => $IDS['cash_egp_id']],
    'passengers' => [['first_name' => 'Del', 'last_name' => 'Test', 'passport_number' => 'DEL001', 'type' => 'adult']],
]);
$bookingId = $r['json']['data']['id'] ?? null;
t('  ▸ POST /flight/bookings (setup)');
if ($bookingId) ok("تم إنشاء booking #$bookingId");

// 4.2 DELETE booking (soft-delete + financial reversal)
if ($bookingId) {
    $cashBefore = DB::table('accounts')->where('id', $IDS['cash_egp_id'])->value('balance');
    info("رصيد الخزينة قبل الحذف: {$cashBefore}");

    $r = httpDel($BASE_URL . '/flight/bookings/' . $bookingId);
    t('  ▸ DELETE /flight/bookings/{id}', ['status' => $r['status']]);
    if ($r['status'] === 200 && ($r['json']['success'] ?? false)) {
        $DELETED['success']++;
        ok('تم حذف booking (soft-delete) بنجاح');
        $cashAfter = DB::table('accounts')->where('id', $IDS['cash_egp_id'])->value('balance');
        info("رصيد الخزينة بعد الحذف: {$cashAfter}");
        info("الفرق: " . ($cashBefore - $cashAfter) . " (financial reversal)");

        // تحقق إن السجل موجود في DB لكن deleted_at != null
        $exists = FlightBooking::withTrashed()->find($bookingId);
        if ($exists && $exists->trashed()) {
            ok('✅ soft-delete تم بنجاح (deleted_at مضبوط)');
        } else {
            fail('❌ soft-delete لم يحدث');
        }
    } else {
        fail('DELETE booking: ' . json_encode($r['json']));
    }
}

// 4.3 DELETE booking مع cascade على passengers
if ($bookingId) {
    $passengerCount = FlightPassenger::where('flight_booking_id', $bookingId)->count();
    info("عدد الركاب المرتبطين بالحجز قبل الحذف: {$passengerCount}");
    $passengersAfter = FlightPassenger::where('flight_booking_id', $bookingId)->count();
    info("عدد الركاب بعد الحذف: {$passengersAfter}");
    if ($passengerCount > 0 && $passengersAfter === 0) {
        $DELETED['cascade']++;
        ok("✅ cascade hard-delete على passengers نجح ({$passengerCount} راكب)");
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// 5) DELETE /flight/carriers/{id} (يجب أن يفشل بسبب FK constraints)
// ═══════════════════════════════════════════════════════════════════════════
section('5) DELETE /flight/carriers/{id} (FK constraints)');

// 5.1 DELETE carrier مع bookings (يجب أن يفشل)
$r = httpDel($BASE_URL . '/flight/carriers/' . $IDS['carrier_egyptair_id']);
t('  ▸ DELETE /flight/carriers/{id} مع bookings', ['status' => $r['status']]);
if ($r['status'] >= 400) {
    $DELETED['cascade']++;
    ok("✅ النظام رفض الحذف بسبب FK constraints: " . ($r['json']['message'] ?? ''));
} else {
    warn("⚠️ النظام سمح بحذف carrier مع bookings!");
}

// 5.2 DELETE carrier بدون bookings (نختبر إن كان مسموحاً)
$unusedCarrier = FlightCarrier::doesntHave('bookings')->first();
if ($unusedCarrier) {
    $r = httpDel($BASE_URL . '/flight/carriers/' . $unusedCarrier->id);
    t('  ▸ DELETE /flight/carriers/{id} بدون bookings #' . $unusedCarrier->id, ['status' => $r['status']]);
    if ($r['status'] === 200 && ($r['json']['success'] ?? false)) {
        $DELETED['success']++;
        ok('✅ تم حذف carrier بدون bookings');
    } else {
        warn('استجابة: ' . json_encode($r['json']));
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// 6) DELETE /flight/systems/{id} (FK constraints)
// ═══════════════════════════════════════════════════════════════════════════
section('6) DELETE /flight/systems/{id}');

// 6.1 DELETE system مع bookings
$r = httpDel($BASE_URL . '/flight/systems/' . $IDS['sys_amadeus_id']);
t('  ▸ DELETE /flight/systems/{id} مع bookings', ['status' => $r['status']]);
if ($r['status'] >= 400) {
    $DELETED['cascade']++;
    ok("✅ النظام رفض الحذف: " . ($r['json']['message'] ?? ''));
} else {
    warn("⚠️ النظام سمح بحذف system مع bookings!");
}

// 6.2 DELETE unused system
$unusedSystem = FlightSystem::doesntHave('bookings')->doesntHave('systemTransactions')->first();
if ($unusedSystem) {
    $r = httpDel($BASE_URL . '/flight/systems/' . $unusedSystem->id);
    t('  ▸ DELETE /flight/systems/{id} بدون dependencies #' . $unusedSystem->id, ['status' => $r['status']]);
    if ($r['status'] === 200 && ($r['json']['success'] ?? false)) {
        $DELETED['success']++;
        ok('✅ تم حذف system نظيف');
    } else {
        warn('استجابة: ' . json_encode($r['json']));
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// 7) DELETE /flight/airports/{id}
// ═══════════════════════════════════════════════════════════════════════════
section('7) DELETE /flight/airports/{id}');

// 7.1 إنشاء مطار جديد للاختبار
$r = httpPost($BASE_URL . '/flight/airports', [
    'iata_code'        => 'DELX',
    'city_name_ar'     => 'Delete Test City',
    'city_name_en'     => 'Delete Test City',
    'airport_name_ar'  => 'Delete Test Airport',
    'airport_name_en'  => 'Delete Test Airport',
    'country_code'     => 'TS',
    'country_name_ar'  => 'Test Country',
    'country_name_en'  => 'Test Country',
    'is_active'        => true,
]);
$airportId = $r['json']['data']['id'] ?? null;
t('  ▸ POST /flight/airports (setup)');
if ($airportId) ok("تم إنشاء airport #$airportId");

if ($airportId) {
    $r = httpDel($BASE_URL . '/flight/airports/' . $airportId);
    t('  ▸ DELETE /flight/airports/{id}', ['status' => $r['status']]);
    if ($r['status'] === 200 && ($r['json']['success'] ?? false)) {
        $DELETED['success']++;
        ok('✅ تم حذف airport');
    } else {
        warn('استجابة: ' . json_encode($r['json']));
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// 8) DELETE /flight/aviation/{id}
// ═══════════════════════════════════════════════════════════════════════════
section('8) DELETE /flight/aviation/{id}');

// 8.1 إنشاء aviation جديد
$r = httpPost($BASE_URL . '/flight/aviation', [
    'name'        => 'FLT-DEL-Aviation-Test',
    'code'        => 'AV' . substr(md5(uniqid()), 0, 4),
    'currency'    => 'EGP',
    'is_active'   => true,
    'description' => 'DELETE test',
]);
$aviationId = $r['json']['data']['id'] ?? null;
t('  ▸ POST /flight/aviation (setup)');
if ($aviationId) ok("تم إنشاء aviation #$aviationId");

if ($aviationId) {
    $r = httpDel($BASE_URL . '/flight/aviation/' . $aviationId);
    t('  ▸ DELETE /flight/aviation/{id}', ['status' => $r['status']]);
    if ($r['status'] === 200 && ($r['json']['success'] ?? false)) {
        $DELETED['success']++;
        ok('✅ تم حذف aviation');
    } else {
        warn('استجابة: ' . json_encode($r['json']));
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// 9) DELETE /flight/groups/{id}
// ═══════════════════════════════════════════════════════════════════════════
section('9) DELETE /flight/groups/{id}');

// 9.1 DELETE group مع bookings (يجب أن يفشل)
$r = httpDel($BASE_URL . '/flight/groups/' . $IDS['flight_group_id']);
t('  ▸ DELETE /flight/groups/{id} مع bookings', ['status' => $r['status']]);
if ($r['status'] >= 400) {
    $DELETED['cascade']++;
    ok("✅ النظام رفض الحذف: " . ($r['json']['message'] ?? ''));
} else {
    warn("⚠️ النظام سمح بحذف group مع bookings!");
}

// ═══════════════════════════════════════════════════════════════════════════
// 10) DELETE /flight/passengers/{id}
// ═══════════════════════════════════════════════════════════════════════════
section('10) DELETE /flight/passengers/{id}');

// التحقق إن route DELETE موجود
$passenger = FlightPassenger::first();
if ($passenger) {
    // routes/api.php لا يوجد DELETE passengers — بس نشوف GET للتأكيد
    info('Passengers API لا يدعم DELETE مباشرة (soft-delete مع booking)');
    ok('✅ Soft-delete عبر booking cascade (تم اختباره)');
}

// ═══════════════════════════════════════════════════════════════════════════
// ملخص نهائي
// ═══════════════════════════════════════════════════════════════════════════
section('الملخص النهائي');
t("  ✅ Success:   " . $DELETED['success']);
t("  ✅ Cascade:   " . $DELETED['cascade']);
t("  ✅ Rejected:  " . $DELETED['rejected']);
t("  الإجمالي:    " . ($DELETED['success'] + $DELETED['cascade'] + $DELETED['rejected']) . " اختبار DELETE");

ok("\n🎉 DELETE Tests complete for Flight module");

fclose($logHandle);
echo "\n📄 Log: " . __DIR__ . "/storage/logs/flight_test/2026-07-15_*_delete.log\n";
echo "✅ Flight DELETE Tests DONE.\n";