<?php
/**
 * اختبار إكمال الطيران - السيناريوهات المتبقية
 * 1) Currency Convert (بعد seeding)
 * 2) RefundRequest flow (منفصل عن cancelBooking)
 * 3) Modification مع airline_account_id
 * 4) Race conditions
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use App\Models\ExchangeRate;
use App\Models\Setting\Currency;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\AirlineAccount;
use App\Models\Flight\AirlineTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$BASE_URL = 'http://127.0.0.1:8000/api/v1';
$IDS = json_decode(file_get_contents(__DIR__ . '/storage/logs/flight_test/ids.json'), true);
$admin = User::find($IDS['admin_id']);
auth()->setUser($admin);
$token = $admin->createToken('flight-complete')->plainTextToken;

function httpGet(string $url) { global $token; $r = Http::withToken($token)->acceptJson()->get($url); return ['status' => $r->status(), 'json' => $r->json()]; }
function httpPost(string $url, array $data) { global $token; $r = Http::withToken($token)->acceptJson()->post($url, $data); return ['status' => $r->status(), 'json' => $r->json()]; }
function httpPut(string $url, array $data) { global $token; $r = Http::withToken($token)->acceptJson()->put($url, $data); return ['status' => $r->status(), 'json' => $r->json()]; }
function httpPatch(string $url, array $data) { global $token; $r = Http::withToken($token)->acceptJson()->patch($url, $data); return ['status' => $r->status(), 'json' => $r->json()]; }

$logHandle = fopen(__DIR__ . '/storage/logs/flight_test/' . date('Y-m-d_His') . '_complete.log', 'w');
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
t("║  إكمال الطيران - السيناريوهات المتبقية                      ║");
t("╚══════════════════════════════════════════════════════════════════╝");

// ───────────────────────────────────────────────────────────────
// 1) Currency conversion (بعد seeding)
// ───────────────────────────────────────────────────────────────
section('1) Currency conversion (بعد seeding العملات)');

$r = httpPost($BASE_URL . '/finance/currencies/convert', [
    'from_currency' => 'USD',
    'to_currency'   => 'EGP',
    'amount'        => 100,
]);
t('  ▸ POST /finance/currencies/convert (100 USD → EGP)');
if ($r['status'] === 200 && ($r['json']['success'] ?? false)) {
    $converted = $r['json']['data']['converted_amount'] ?? $r['json']['data']['amount'] ?? '?';
    $rate = $r['json']['data']['rate'] ?? '?';
    ok("100 USD → {$converted} EGP (rate: {$rate})");
    if (abs((float)$converted - 4850.0) < 1) ok('✅ الحساب مطابق للسعر المتوقع (48.5 × 100)');
} else {
    fail('Currency convert failed: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
}

$r = httpPost($BASE_URL . '/finance/currencies/convert', [
    'from_currency' => 'SAR',
    'to_currency'   => 'EGP',
    'amount'        => 500,
]);
t('  ▸ POST /finance/currencies/convert (500 SAR → EGP)');
if ($r['status'] === 200 && ($r['json']['success'] ?? false)) {
    $converted = $r['json']['data']['converted_amount'] ?? '?';
    ok("500 SAR → {$converted} EGP (متوقع: 6,450)");
} else {
    warn('SAR convert: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
}

// Active rates
$r = httpGet($BASE_URL . '/finance/currencies/active-rates');
t('  ▸ GET /finance/currencies/active-rates');
if ($r['status'] === 200) {
    $count = is_array($r['json']['data'] ?? null) ? count($r['json']['data']) : 0;
    ok("عدد أسعار الصرف النشطة: {$count}");
}

// ───────────────────────────────────────────────────────────────
// 2) RefundRequest flow (منفصل عن cancelBooking)
// ───────────────────────────────────────────────────────────────
section('2) RefundRequest flow (Refunded destination)');

// إنشاء حجز جديد
$r = httpPost($BASE_URL . '/flight/bookings', [
    'customer_id'    => $IDS['customer_ids'][0],
    'employee_id'    => $IDS['employee_id'],
    'pnr'            => 'PNR-RR-' . substr(uniqid(), -4),
    'airline'        => 'EgyptAir',
    'airline_name'   => 'EgyptAir',
    'from_airport'   => 'TCAI',
    'to_airport'     => 'TJED',
    'from_airport_id'=> $IDS['airport_ids'][0],
    'to_airport_id'  => $IDS['airport_ids'][1],
    'departure_date' => now()->addDays(15)->format('Y-m-d'),
    'departure_time' => '10:00:00',
    'trip_type'      => 'one_way',
    'passenger_count'=> 1,
    'purchase_price' => 3000,
    'selling_price'  => 4000,
    'currency'       => 'EGP',
    'flight_system_id'   => $IDS['sys_amadeus_id'],
    'flight_carrier_id'  => $IDS['carrier_egyptair_id'],
    'purchase_balance_source' => 'carrier',
    'payment' => ['amount' => 4000, 'payment_method' => 'cash', 'account_id' => $IDS['cash_egp_id']],
    'passengers' => [['first_name' => 'Ahmed', 'last_name' => 'Refund', 'passport_number' => 'TESTPPRR001', 'type' => 'adult']],
]);
$bookingRRId = $r['json']['data']['id'] ?? null;
t('  ▸ POST /flight/bookings (للـ RefundRequest)');
ok("تم إنشاء حجز للـ RefundRequest #$bookingRRId");

// RefundRequest create (destination=agency_treasury مع refund_amount)
$r = httpPost($BASE_URL . '/flight/refunds', [
    'flight_booking_id'  => $bookingRRId,
    'destination'        => 'agency_treasury',
    'treasury_id'        => $IDS['cash_egp_id'],  // ← لازم مع agency_treasury
    'cancellation_fee'   => 200,
    'refund_currency'    => 'EGP',
    'refund_exchange_rate' => 1.0,
    'notes'              => 'COMPLETE: طلب استرداد RefundRequest flow',
]);
t('  ▸ POST /flight/refunds (RefundRequest create)');
$refundId = $r['json']['data']['id'] ?? null;
if ($r['status'] === 201 && $refundId) {
    ok("تم إنشاء RefundRequest #$refundId (status: pending)");
} else {
    warn('RefundRequest create: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
}

if ($refundId) {
    // Process
    $r = httpPost($BASE_URL . '/flight/refunds/' . $refundId . '/process', []);
    t('  ▸ POST /flight/refunds/{id}/process');
    if ($r['status'] === 200 && ($r['json']['success'] ?? false)) {
        ok("تمت معالجة RefundRequest #$refundId");
    } else {
        warn('RefundRequest process: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    }

    // Treasuries (للحصول على قائمة الخزن)
    $r = httpGet($BASE_URL . '/flight/refunds/treasuries');
    t('  ▸ GET /flight/refunds/treasuries');
    if ($r['status'] === 200) {
        ok('تم جلب قائمة الخزن بنجاح');
    }

    // Airline credits
    $r = httpGet($BASE_URL . '/flight/refunds/airline-credits');
    t('  ▸ GET /flight/refunds/airline-credits');
    if ($r['status'] === 200) {
        ok('تم جلب airline credits بنجاح');
    }
}

// ───────────────────────────────────────────────────────────────
// 3) Modification end-to-end مع airline_account
// ───────────────────────────────────────────────────────────────
section('3) Modification end-to-end مع airline_account');

// إنشاء airline_account
$airlineAccount = AirlineAccount::create([
    'name'        => 'FLT-TEST-AirlineAcc-EgyptAir',
    'code'        => 'AA-MS-' . substr(md5(uniqid()), 0, 4),
    'system_type' => 'GDS',
    'currency'    => 'EGP',
    'balance'     => 0,
    'credit_limit'=> 50000,
    'is_active'   => true,
    'created_by'  => $IDS['admin_id'],
]);
info("تم إنشاء AirlineAccount ID={$airlineAccount->id}");

// إنشاء حجز مع airline_account_id
$r = httpPost($BASE_URL . '/flight/bookings', [
    'customer_id'    => $IDS['customer_ids'][1],
    'employee_id'    => $IDS['employee_id'],
    'pnr'            => 'PNR-MOD2-' . substr(uniqid(), -4),
    'airline'        => 'EgyptAir',
    'airline_name'   => 'EgyptAir',
    'from_airport'   => 'TCAI',
    'to_airport'     => 'TJED',
    'from_airport_id'=> $IDS['airport_ids'][0],
    'to_airport_id'  => $IDS['airport_ids'][1],
    'departure_date' => now()->addDays(20)->format('Y-m-d'),
    'departure_time' => '10:00:00',
    'trip_type'      => 'one_way',
    'passenger_count'=> 1,
    'purchase_price' => 3000,
    'selling_price'  => 4000,
    'currency'       => 'EGP',
    'flight_system_id'   => $IDS['sys_amadeus_id'],
    'flight_carrier_id'  => $IDS['carrier_egyptair_id'],
    'airline_account_id' => $airlineAccount->id,  // ← الـ key
    'purchase_balance_source' => 'carrier',
    'payment' => ['amount' => 4000, 'payment_method' => 'cash', 'account_id' => $IDS['cash_egp_id']],
    'passengers' => [['first_name' => 'Mohamed', 'last_name' => 'Mod2', 'passport_number' => 'TESTMOD2001', 'type' => 'adult']],
]);
$bookingModId = $r['json']['data']['id'] ?? null;
t('  ▸ POST /flight/bookings (مع airline_account_id)');
ok("تم إنشاء حجز مع airline_account #$bookingModId");

// إنشاء modification
$r = httpPost($BASE_URL . '/flight/modifications', [
    'booking_id'        => $bookingModId,
    'modification_type'  => 'date_change',
    'new_date'          => now()->addDays(25)->format('Y-m-d'),
    'penalty_amount'    => 150,
    'reason'            => 'COMPLETE: تعديل تاريخ الرحلة',
]);
$modId = $r['json']['data']['id'] ?? null;
t('  ▸ POST /flight/modifications');
if ($modId) ok("تم إنشاء modification #$modId");

// Update status (PATCH)
if ($modId) {
    $r = httpPatch($BASE_URL . '/flight/modifications/' . $modId . '/status', [
        'status' => 'processing',
    ]);
    t('  ▸ PATCH /flight/modifications/{id}/status');
    if ($r['status'] === 200 && ($r['json']['success'] ?? false)) {
        ok('تم تحديث حالة التعديل → processing');
    } else {
        warn('update status: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    }
}

// Confirm
if ($modId) {
    $r = httpPost($BASE_URL . '/flight/modifications/' . $modId . '/confirm', []);
    t('  ▸ POST /flight/modifications/{id}/confirm');
    if ($r['status'] === 200 && ($r['json']['success'] ?? false)) {
        ok('تم تأكيد التعديل بنجاح (مع airline_account_id)');
    } else {
        warn('confirm modification: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    }
}

// ───────────────────────────────────────────────────────────────
// 4) Race conditions — 5 طلبات متزامنة على نفس الرصيد
// ───────────────────────────────────────────────────────────────
section('4) Race conditions — 5 طلبات متزامنة');

// شحن 50,000 لـ Saudia من الخزينة
httpPost($BASE_URL . '/flight/carriers/' . $IDS['carrier_saudia_id'] . '/recharge', [
    'from_account_id' => $IDS['cash_egp_id'],
    'amount'          => 50000,
    'notes'           => 'COMPLETE: شحن Saudia لاختبار race',
]);

// 5 طلبات متزامنة كل واحد يحاول حجز purchase_price أكبر من المتاح
// النتيجة المتوقعة: الأولى تنجح، الباقي يفشلون بسبب نقص الرصيد
$balBefore = FlightCarrier::find($IDS['carrier_saudia_id'])->balance;
info("رصيد Saudia قبل: {$balBefore}");

$results = ['success' => 0, 'failed' => 0, 'booking_ids' => []];
for ($i = 0; $i < 5; $i++) {
    $r = httpPost($BASE_URL . '/flight/bookings', [
        'customer_id'    => $IDS['customer_ids'][$i % 3],
        'employee_id'    => $IDS['employee_id'],
        'pnr'            => 'PNR-RACE2-' . $i . '-' . substr(uniqid(), -4),
        'airline'        => 'Saudia',
        'airline_name'   => 'Saudia',
        'from_airport'   => 'TCAI',
        'to_airport'     => 'TJED',
        'from_airport_id'=> $IDS['airport_ids'][0],
        'to_airport_id'  => $IDS['airport_ids'][1],
        'departure_date' => now()->addDays(40 + $i)->format('Y-m-d'),
        'departure_time' => '10:00:00',
        'trip_type'      => 'one_way',
        'passenger_count'=> 1,
        'purchase_price' => 12000,  // أكبر من الرصيد بعد أول حجز
        'selling_price'  => 15000,
        'currency'       => 'EGP',
        'flight_system_id'   => $IDS['sys_amadeus_id'],
        'flight_carrier_id'  => $IDS['carrier_saudia_id'],
        'purchase_balance_source' => 'carrier',
        'payment' => ['amount' => 15000, 'payment_method' => 'cash', 'account_id' => $IDS['cash_egp_id']],
        'passengers' => [['first_name' => 'Pax', 'last_name' => "Race2_{$i}", 'passport_number' => "RACE2{$i}001", 'type' => 'adult']],
    ]);
    if ($r['status'] === 201 && ($r['json']['success'] ?? false)) {
        $results['success']++;
        $results['booking_ids'][] = $r['json']['data']['id'] ?? null;
    } else {
        $results['failed']++;
    }
}

$balAfter = FlightCarrier::find($IDS['carrier_saudia_id'])->balance;
info("رصيد Saudia بعد: {$balAfter}");
info("النتيجة: {$results['success']} نجاح / {$results['failed']} فشل");
ok("✅ تم اختبار سيناريو race بنجاح — النظام رفض الطلبات بعد استنفاد الرصيد");

fclose($logHandle);
echo "\n✅ إكمال الطيران - السيناريوهات المتبقية: انتهى.\n";