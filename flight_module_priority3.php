<?php
/**
 * اختبار أولوية 3: Ticket Modifications
 * M,N,O,P — create/confirm/reconcile/update-status
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Account;
use App\Models\User;
use App\Models\Flight\FlightBooking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$BASE_URL = 'http://127.0.0.1:8000/api/v1';
$IDS = json_decode(file_get_contents(__DIR__ . '/storage/logs/flight_test/ids.json'), true);
$admin = User::find($IDS['admin_id']);
auth()->setUser($admin);
$token = $admin->createToken('priority3-test')->plainTextToken;

function httpGet(string $url) { global $token; $r = Http::withToken($token)->acceptJson()->get($url); return ['status' => $r->status(), 'json' => $r->json()]; }
function httpPost(string $url, array $data) { global $token; $r = Http::withToken($token)->acceptJson()->post($url, $data); return ['status' => $r->status(), 'json' => $r->json()]; }
function httpPut(string $url, array $data) { global $token; $r = Http::withToken($token)->acceptJson()->put($url, $data); return ['status' => $r->status(), 'json' => $r->json()]; }

$logHandle = fopen(__DIR__ . '/storage/logs/flight_test/' . date('Y-m-d_His') . '_priority3.log', 'w');
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
t("║  أولوية 3: Ticket Modifications                              ║");
t("╚══════════════════════════════════════════════════════════════════╝");

// أنشئ حجز أولاً للاختبار
$r = httpPost($BASE_URL . '/flight/bookings', [
    'customer_id'    => $IDS['customer_ids'][0],
    'employee_id'    => $IDS['employee_id'],
    'pnr'            => 'PNR-MOD-' . substr(uniqid(), -4),
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
    'payment' => ['amount' => 4000, 'payment_method' => 'cash', 'account_id' => $IDS['cash_egp_id']],
    'passengers' => [['first_name' => 'Ahmed', 'last_name' => 'Mod', 'passport_number' => 'TESTPM001', 'type' => 'adult']],
]);
$bookingId = $r['json']['data']['id'] ?? null;
t('  ℹ  تم إنشاء حجز #' . $bookingId . ' لتجارب التعديل');

// ───────────────────────────────────────────────────────────────
// M) إنشاء طلب تعديل تذكرة (POST /flight/modifications)
// ───────────────────────────────────────────────────────────────
section('M) Ticket Modification - إنشاء طلب تعديل');

$r = httpPost($BASE_URL . '/flight/modifications', [
    'booking_id'        => $bookingId,
    'modification_type'  => 'date_change',
    'new_date'          => now()->addDays(8)->format('Y-m-d'),
    'penalty_amount'    => 200,
    'reason'            => 'EXTENDED: طلب تعديل تاريخ الرحلة',
]);
t('  ▸ POST /flight/modifications');
$modId = $r['json']['data']['id'] ?? null;
if ($r['status'] === 201 && $modId) {
    ok("تم إنشاء طلب تعديل #$modId");
    info('status: ' . ($r['json']['data']['status'] ?? '?'));
} else {
    fail('فشل إنشاء التعديل: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
}

// ───────────────────────────────────────────────────────────────
// P) تحديث حالة التعديل (POST /flight/modifications/{id}/update-status)
// ───────────────────────────────────────────────────────────────
section('P) تعديل حالة التعديل');
if ($modId) {
    $r = httpPut($BASE_URL . '/flight/modifications/' . $modId . '/status', [
        'status' => 'processing',
    ]);
    t('  ▸ PATCH /flight/modifications/{id}/status');
    if ($r['status'] === 200 && ($r['json']['success'] ?? false)) {
        ok("تم تحديث حالة التعديل #$modId إلى processing");
    } else {
        warn('تحديث الحالة: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    }
}

// ───────────────────────────────────────────────────────────────
// N) تأكيد التعديل (POST /flight/modifications/{id}/confirm)
// ───────────────────────────────────────────────────────────────
section('N) Ticket Modification - تأكيد');
if ($modId) {
    $r = httpPost($BASE_URL . '/flight/modifications/' . $modId . '/confirm', []);
    t('  ▸ POST /flight/modifications/{id}/confirm');
    if ($r['status'] === 200 && ($r['json']['success'] ?? false)) {
        ok("تم تأكيد التعديل #$modId");
    } else {
        warn('تأكيد التعديل: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    }
}

// ───────────────────────────────────────────────────────────────
// O) تسوية التعديل (POST /flight/modifications/{id}/reconcile)
// ───────────────────────────────────────────────────────────────
section('O) Ticket Modification - تسوية (reconcile)');
if ($modId) {
    $r = httpPost($BASE_URL . '/flight/modifications/' . $modId . '/reconcile', [
    'invoice_number' => 'INV-MOD-' . uniqid(),
]);
    t('  ▸ POST /flight/modifications/{id}/reconcile');
    if ($r['status'] === 200 && ($r['json']['success'] ?? false)) {
        ok("تم تسوية التعديل #$modId");
    } else {
        warn('تسوية التعديل: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
    }
}

// ───────────────────────────────────────────────────────────────
// Bonus: استعراض تعديلات الحجز
// ───────────────────────────────────────────────────────────────
section('Bonus) GET /flight/modifications/booking/{id}');
$r = httpGet($BASE_URL . '/flight/modifications/bookings/' . $bookingId . '/modifications');
t('  ▸ GET /flight/modifications/booking/{id}');
if ($r['status'] === 200) {
    $count = is_array($r['json']['data'] ?? null) ? count($r['json']['data']) : 0;
    ok("عدد التعديلات على الحجز #$bookingId: {$count}");
} else {
    warn('عرض تعديلات الحجز: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
}

fclose($logHandle);
echo "\n✅ أولوية 3 (Modifications) اكتملت.\n";