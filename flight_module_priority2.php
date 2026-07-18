<?php
/**
 * اختبار أولوية 2: Workflow scenarios
 * F,G,H,I,J,K,L — confirm، updatePrices، round-trip، multi-pax، segments، send-email، delete
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Account;
use App\Models\Customer;
use App\Models\User;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$BASE_URL = 'http://127.0.0.1:8000/api/v1';
$IDS = json_decode(file_get_contents(__DIR__ . '/storage/logs/flight_test/ids.json'), true);
$admin = User::find($IDS['admin_id']);
auth()->setUser($admin);
$token = $admin->createToken('priority2-test')->plainTextToken;

function httpGet(string $url) { global $token; $r = Http::withToken($token)->acceptJson()->get($url); return ['status' => $r->status(), 'json' => $r->json()]; }
function httpPost(string $url, array $data) { global $token; $r = Http::withToken($token)->acceptJson()->post($url, $data); return ['status' => $r->status(), 'json' => $r->json()]; }
function httpDel(string $url) { global $token; $r = Http::withToken($token)->acceptJson()->delete($url); return ['status' => $r->status(), 'json' => $r->json()]; }

$logHandle = fopen(__DIR__ . '/storage/logs/flight_test/' . date('Y-m-d_His') . '_priority2.log', 'w');
function t(string $m) { global $logHandle; $l = '[' . date('H:i:s') . '] ' . $m . "\n"; fwrite($logHandle, $l); fflush($logHandle); echo $l; }
function ok(string $m='OK') { t("    ✅ {$m}"); }
function fail(string $m) { t("    ❌ {$m}"); }
function info(string $m) { t("    ℹ  {$m}"); }
function warn(string $m) { t("    ⚠  {$m}"); }
function section(string $title) {
    t("\n" . str_repeat('═', 70));
    t('  ' . $title);
    t(str_repeat('═', 70));
}

t("╔══════════════════════════════════════════════════════════════════╗");
t("║  أولوية 2: Workflow scenarios                                ║");
t("╚══════════════════════════════════════════════════════════════════╝");

// ───────────────────────────────────────────────────────────────
// H) Round-trip booking (ذهاب + إياب)
// ───────────────────────────────────────────────────────────────
section('H) Round-trip booking (ذهاب + إياب)');
$r = httpPost($BASE_URL . '/flight/bookings', [
    'customer_id'    => $IDS['customer_ids'][0],
    'employee_id'    => $IDS['employee_id'],
    'pnr'            => 'PNR-RT-' . substr(uniqid(), -4),
    'airline'        => 'EgyptAir',
    'airline_name'   => 'EgyptAir',
    'from_airport'   => 'TCAI',
    'to_airport'     => 'TJED',
    'from_airport_id'=> $IDS['airport_ids'][0],
    'to_airport_id'  => $IDS['airport_ids'][1],
    'departure_date' => now()->addDays(10)->format('Y-m-d'),
    'return_date'    => now()->addDays(17)->format('Y-m-d'),
    'departure_time' => '08:00:00',
    'return_time'    => '22:00:00',
    'trip_type'      => 'round_trip',
    'passenger_count'=> 1,
    'purchase_price' => 6000,
    'selling_price'  => 7500,
    'currency'       => 'EGP',
    'flight_system_id'   => $IDS['sys_amadeus_id'],
    'flight_carrier_id'  => $IDS['carrier_egyptair_id'],
    'purchase_balance_source' => 'carrier',
    'payment' => ['amount' => 7500, 'payment_method' => 'cash', 'account_id' => $IDS['cash_egp_id']],
    'passengers' => [['first_name' => 'Ahmed', 'last_name' => 'RoundTrip', 'passport_number' => 'TESTPPRT001', 'type' => 'adult']],
]);
t('  ▸ POST /flight/bookings (round-trip)');
$bookingHId = $r['json']['data']['id'] ?? null;
if ($r['status'] === 201 && $bookingHId) {
    ok("تم إنشاء حجز round-trip #$bookingHId");
    $tripType = $r['json']['data']['trip_type'] ?? '?';
    $segCount = is_array($r['json']['data']['segments'] ?? null) ? count($r['json']['data']['segments']) : 0;
    info("trip_type: {$tripType} | segments: {$segCount}");
} else {
    fail('فشل round-trip: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
}

// ───────────────────────────────────────────────────────────────
// J) Multi-segment booking (connecting flights)
// ───────────────────────────────────────────────────────────────
section('J) Multi-segment booking (رحلتين متصلتين CAI→RUH→DXB)');
$r = httpPost($BASE_URL . '/flight/bookings', [
    'customer_id'    => $IDS['customer_ids'][1], // Mohamed
    'employee_id'    => $IDS['employee_id'],
    'pnr'            => 'PNR-MULTI-' . substr(uniqid(), -4),
    'airline'        => 'Saudia',
    'airline_name'   => 'Saudia',
    'from_airport'   => 'TCAI',
    'to_airport'     => 'TDXB',
    'from_airport_id'=> $IDS['airport_ids'][0],
    'to_airport_id'  => $IDS['airport_ids'][3],
    'departure_date' => now()->addDays(12)->format('Y-m-d'),
    'departure_time' => '14:00:00',
    'trip_type'      => 'one_way',
    'passenger_count'=> 1,
    'purchase_price' => 7000,
    'selling_price'  => 9000,
    'currency'       => 'EGP',
    'flight_system_id'   => $IDS['sys_amadeus_id'],
    'flight_carrier_id'  => $IDS['carrier_saudia_id'],
    'purchase_balance_source' => 'carrier',
    'payment' => ['amount' => 9000, 'payment_method' => 'cash', 'account_id' => $IDS['cash_egp_id']],
    'segments' => [
        ['airline_name' => 'Saudia', 'flight_number' => 'SV301', 'from_airport' => 'TCAI', 'to_airport' => 'TRUH',
         'departure_date' => now()->addDays(12)->format('Y-m-d'), 'departure_time' => '14:00:00', 'arrival_time' => '16:00:00',
         'flight_class' => 'economy'],
        ['airline_name' => 'Saudia', 'flight_number' => 'SV305', 'from_airport' => 'TRUH', 'to_airport' => 'TDXB',
         'departure_date' => now()->addDays(12)->format('Y-m-d'), 'departure_time' => '18:30:00', 'arrival_time' => '21:00:00',
         'flight_class' => 'economy'],
    ],
    'passengers' => [['first_name' => 'Mohamed', 'last_name' => 'MultiSeg', 'passport_number' => 'TESTPPMS001', 'type' => 'adult']],
]);
t('  ▸ POST /flight/bookings (multi-segment)');
$bookingJId = $r['json']['data']['id'] ?? null;
if ($r['status'] === 201 && $bookingJId) {
    ok("تم إنشاء حجز multi-segment #$bookingJId");
    $segCount = is_array($r['json']['data']['segments'] ?? null) ? count($r['json']['data']['segments']) : 0;
    info("عدد الـ segments المحفوظة: {$segCount}");
} else {
    fail('فشل multi-segment: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
}

// ───────────────────────────────────────────────────────────────
// I) Multi-passenger booking (3+ passengers)
// ───────────────────────────────────────────────────────────────
section('I) Multi-passenger booking (3 ركاب)');
$r = httpPost($BASE_URL . '/flight/bookings', [
    'customer_id'    => $IDS['customer_ids'][2], // Sara
    'employee_id'    => $IDS['employee_id'],
    'pnr'            => 'PNR-MULTIPAX-' . substr(uniqid(), -4),
    'airline'        => 'EgyptAir',
    'airline_name'   => 'EgyptAir',
    'from_airport'   => 'TCAI',
    'to_airport'     => 'TJED',
    'from_airport_id'=> $IDS['airport_ids'][0],
    'to_airport_id'  => $IDS['airport_ids'][1],
    'departure_date' => now()->addDays(20)->format('Y-m-d'),
    'departure_time' => '06:00:00',
    'trip_type'      => 'one_way',
    'passenger_count'=> 3,
    'purchase_price' => 12000,
    'selling_price'  => 16500,
    'currency'       => 'EGP',
    'flight_system_id'   => $IDS['sys_amadeus_id'],
    'flight_carrier_id'  => $IDS['carrier_egyptair_id'],
    'purchase_balance_source' => 'carrier',
    'payment' => ['amount' => 16500, 'payment_method' => 'cash', 'account_id' => $IDS['cash_egp_id']],
    'passengers' => [
        ['first_name' => 'Sara',  'last_name' => 'PaxA', 'passport_number' => 'TESTPPI001', 'type' => 'adult'],
        ['first_name' => 'Ahmed', 'last_name' => 'PaxB', 'passport_number' => 'TESTPPI002', 'type' => 'adult'],
        ['first_name' => 'Omar',  'last_name' => 'PaxC', 'passport_number' => 'TESTPPI003', 'type' => 'child'],
    ],
]);
t('  ▸ POST /flight/bookings (3 passengers)');
$bookingIId = $r['json']['data']['id'] ?? null;
if ($r['status'] === 201 && $bookingIId) {
    ok("تم إنشاء حجز 3 ركاب #$bookingIId");
    $paxCount = is_array($r['json']['data']['passengers'] ?? null) ? count($r['json']['data']['passengers']) : 0;
    info("عدد الركاب المحفوظين: {$paxCount}");
} else {
    fail('فشل multi-pax: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
}

// ───────────────────────────────────────────────────────────────
// F) Confirm booking (POST /flight/bookings/{id}/confirm)
// ───────────────────────────────────────────────────────────────
section('F) Confirm booking (endpoint منفصل)');
// أنشئ حجز بدون payment ليبقى status=PENDING (قابل للتأكيد)
$r = httpPost($BASE_URL . '/flight/bookings', [
    'customer_id'    => $IDS['customer_ids'][0],
    'employee_id'    => $IDS['employee_id'],
    'pnr'            => 'PNR-CONF-' . substr(uniqid(), -4),
    'airline'        => 'EgyptAir',
    'airline_name'   => 'EgyptAir',
    'from_airport'   => 'TCAI',
    'to_airport'     => 'TJED',
    'from_airport_id'=> $IDS['airport_ids'][0],
    'to_airport_id'  => $IDS['airport_ids'][1],
    'departure_date' => now()->addDays(5)->format('Y-m-d'),
    'departure_time' => '10:00:00',
    'trip_type'      => 'one_way',
    'passenger_count'=> 1,
    'purchase_price' => 3000,
    'selling_price'  => 4000,
    'currency'       => 'EGP',
    'flight_system_id'   => $IDS['sys_amadeus_id'],
    'flight_carrier_id'  => $IDS['carrier_egyptair_id'],
    'purchase_balance_source' => 'carrier',
    // بدون payment → الحجز يبقى pending
    'passengers' => [['first_name' => 'Ahmed', 'last_name' => 'Confirm', 'passport_number' => 'TESTPPCF001', 'type' => 'adult']],
]);
$bookingFId = $r['json']['data']['id'] ?? null;
t('  ▸ POST /flight/bookings (pending - بدون payment)');
if ($bookingFId) {
    $initialStatus = $r['json']['data']['status'] ?? '?';
    info("initial status: {$initialStatus}");
    $r = httpPost($BASE_URL . '/flight/bookings/' . $bookingFId . '/confirm', []);
    t('  ▸ POST /flight/bookings/{id}/confirm');
    if ($r['status'] === 200 && ($r['json']['success'] ?? false)) {
        ok("تم تأكيد الحجز #$bookingFId");
    } else {
        warn('تأكيد الحجز: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    }
}

// ───────────────────────────────────────────────────────────────
// G) Update prices
// ───────────────────────────────────────────────────────────────
section('G) Update prices (POST /flight/bookings/{id}/prices)');
if ($bookingFId) {
    $r = httpPost($BASE_URL . '/flight/bookings/' . $bookingFId . '/prices', [
        'purchase_price' => 3200,
        'selling_price'  => 4500,
    ]);
    t('  ▸ POST /flight/bookings/{id}/prices');
    if ($r['status'] === 200 && ($r['json']['success'] ?? false)) {
        ok('تم تحديث الأسعار');
    } else {
        warn('تحديث الأسعار: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    }
}

// ───────────────────────────────────────────────────────────────
// K) Send ticket email
// ───────────────────────────────────────────────────────────────
section('K) Send ticket email (POST /flight/bookings/{id}/send-ticket-email)');
if ($bookingFId) {
    $r = httpPost($BASE_URL . '/flight/bookings/' . $bookingFId . '/send-ticket-email', [
        'to_email' => 'test-customer@example.com',
    ]);
    t('  ▸ POST /flight/bookings/{id}/send-ticket-email (مع to_email)');
    if ($r['status'] === 200 && ($r['json']['success'] ?? false)) {
        ok('تم إرسال التذكرة بنجاح (mock)');
    } else {
        warn('إرسال التذكرة: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    }
}

// ───────────────────────────────────────────────────────────────
// L) Soft delete booking (DELETE /flight/bookings/{id})
// ───────────────────────────────────────────────────────────────
section('L) Soft delete booking (DELETE /flight/bookings/{id})');
if ($bookingFId) {
    $r = httpDel($BASE_URL . '/flight/bookings/' . $bookingFId);
    t('  ▸ DELETE /flight/bookings/{id}');
    if ($r['status'] === 200 && ($r['json']['success'] ?? false)) {
        ok('تم حذف الحجز #' . $bookingFId . ' (soft delete)');
        // تحقق إن السجل في DB لكن deleted_at != null
        $exists = FlightBooking::withTrashed()->find($bookingFId);
        if ($exists && $exists->trashed()) {
            ok('✅ soft-delete تم بنجاح (deleted_at مضبوط)');
        }
    } else {
        warn('حذف الحجز: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    }
}

fclose($logHandle);
echo "\n✅ أولوية 2 (Workflow scenarios) اكتملت.\n";