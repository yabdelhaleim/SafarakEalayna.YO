<?php
/**
 * اختبار أولوية 4: APIs إضافية (Read-only + Currency)
 * Q,R,S,T,U,V,W,X
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Http;

$BASE_URL = 'http://127.0.0.1:8000/api/v1';
$IDS = json_decode(file_get_contents(__DIR__ . '/storage/logs/flight_test/ids.json'), true);
$admin = User::find($IDS['admin_id']);
auth()->setUser($admin);
$token = $admin->createToken('priority4-test')->plainTextToken;

function httpGet(string $url) { global $token; $r = Http::withToken($token)->acceptJson()->get($url); return ['status' => $r->status(), 'json' => $r->json()]; }
function httpPost(string $url, array $data) { global $token; $r = Http::withToken($token)->acceptJson()->post($url, $data); return ['status' => $r->status(), 'json' => $r->json()]; }

$logHandle = fopen(__DIR__ . '/storage/logs/flight_test/' . date('Y-m-d_His') . '_priority4.log', 'w');
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
t("║  أولوية 4: APIs إضافية (Read-only)                            ║");
t("╚══════════════════════════════════════════════════════════════════╝");

// ───────────────────────────────────────────────────────────────
// Q) Carrier balance
// ───────────────────────────────────────────────────────────────
section('Q) GET /flight/carriers/{id}/balance');
$r = httpGet($BASE_URL . '/flight/carriers/' . $IDS['carrier_egyptair_id'] . '/balance');
t('  ▸ GET /flight/carriers/13/balance');
if ($r['status'] === 200 && ($r['json']['success'] ?? false)) {
    $bal = $r['json']['data']['balance'] ?? '?';
    $avail = $r['json']['data']['available_balance'] ?? '?';
    ok("رصيد EgyptAir: {$bal} EGP (متاح: {$avail})");
} else {
    fail('فشل قراءة الرصيد: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
}

// ───────────────────────────────────────────────────────────────
// R) Carrier transactions
// ───────────────────────────────────────────────────────────────
section('R) GET /flight/carriers/{id}/transactions');
$r = httpGet($BASE_URL . '/flight/carriers/' . $IDS['carrier_egyptair_id'] . '/transactions');
t('  ▸ GET /flight/carriers/13/transactions');
if ($r['status'] === 200) {
    $count = is_array($r['json']['data'] ?? null) ? count($r['json']['data']) : 0;
    ok("عدد حركات EgyptAir: {$count}");
} else {
    warn('فشل قراءة الحركات: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
}

// ───────────────────────────────────────────────────────────────
// S) Flight system transactions
// ───────────────────────────────────────────────────────────────
section('S) GET /flight/treasury/systems/{id}/transactions');
$r = httpGet($BASE_URL . '/flight/treasury/systems/' . $IDS['sys_amadeus_id'] . '/transactions');
t('  ▸ GET /flight/treasury/systems/9/transactions');
if ($r['status'] === 200) {
    $count = is_array($r['json']['data'] ?? null) ? count($r['json']['data']) : 0;
    ok("عدد حركات Amadeus: {$count}");
} else {
    warn('فشل قراءة حركات النظام: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
}

// ───────────────────────────────────────────────────────────────
// T) Group statement (اختبرناه في أولوية 1)
// ───────────────────────────────────────────────────────────────
section('T) GET /flight/groups/{id}/statement (إعادة اختبار)');
$r = httpGet($BASE_URL . '/flight/groups/' . $IDS['flight_group_id'] . '/statement');
t('  ▸ GET /flight/groups/{id}/statement');
if ($r['status'] === 200) {
    $count = is_array($r['json']['data'] ?? null) ? count($r['json']['data']) : 0;
    ok("عدد حركات كشف المجموعة: {$count}");
} else {
    warn('فشل كشف الحساب: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
}

// ───────────────────────────────────────────────────────────────
// U) Account flight transactions
// ───────────────────────────────────────────────────────────────
section('U) GET /flight/treasury/accounts/{id}/flight-transactions');
$r = httpGet($BASE_URL . '/flight/treasury/accounts/' . $IDS['cash_egp_id'] . '/flight-transactions');
t('  ▸ GET /flight/treasury/accounts/66/flight-transactions');
if ($r['status'] === 200) {
    $count = is_array($r['json']['data'] ?? null) ? count($r['json']['data']) : 0;
    ok("عدد معاملات الخزينة في الطيران: {$count}");
} else {
    warn('فشل قراءة معاملات الحساب: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
}

// ───────────────────────────────────────────────────────────────
// V) Airline accounts CRUD (GET فقط)
// ───────────────────────────────────────────────────────────────
section('V) GET /flight/airline-accounts');
$r = httpGet($BASE_URL . '/flight/airline-accounts');
t('  ▸ GET /flight/airline-accounts');
if ($r['status'] === 200) {
    $count = is_array($r['json']['data'] ?? null) ? count($r['json']['data']) : 0;
    ok("عدد airline accounts: {$count}");
} else {
    warn('فشل قراءة airline accounts: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
}

// ───────────────────────────────────────────────────────────────
// W) Airports CRUD (GET فقط)
// ───────────────────────────────────────────────────────────────
section('W) GET /flight/airports');
$r = httpGet($BASE_URL . '/flight/airports');
t('  ▸ GET /flight/airports');
if ($r['status'] === 200) {
    $count = is_array($r['json']['data'] ?? null) ? count($r['json']['data']) : 0;
    ok("عدد المطارات: {$count}");
} else {
    warn('فشل قراءة المطارات: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
}

// ───────────────────────────────────────────────────────────────
// X) Currency conversion
// ───────────────────────────────────────────────────────────────
section('X) POST /v1/finance/currencies/convert');
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
} else {
    warn('Currency convert: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
}

// ───────────────────────────────────────────────────────────────
// Bonus: Active rates
// ───────────────────────────────────────────────────────────────
section('Bonus) GET /finance/currencies/active-rates');
$r = httpGet($BASE_URL . '/finance/currencies/active-rates');
t('  ▸ GET /finance/currencies/active-rates');
if ($r['status'] === 200) {
    $count = is_array($r['json']['data'] ?? null) ? count($r['json']['data']) : 0;
    ok("عدد أسعار الصرف النشطة: {$count}");
} else {
    warn('active-rates: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
}

// ───────────────────────────────────────────────────────────────
// Bonus: Treasury overview
// ───────────────────────────────────────────────────────────────
section('Bonus) GET /flight/treasury/overview');
$r = httpGet($BASE_URL . '/flight/treasury/overview');
t('  ▸ GET /flight/treasury/overview');
if ($r['status'] === 200 && ($r['json']['success'] ?? false)) {
    $systems = is_array($r['json']['data']['systems'] ?? null) ? count($r['json']['data']['systems']) : 0;
    $carriers = is_array($r['json']['data']['carriers'] ?? null) ? count($r['json']['data']['carriers']) : 0;
    ok("نظرة عامة: {$systems} أنظمة + {$carriers} شركات طيران");
} else {
    warn('Treasury overview: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
}

fclose($logHandle);
echo "\n✅ أولوية 4 (Read-only APIs) اكتملت.\n";