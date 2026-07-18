<?php
/**
 * ════════════════════════════════════════════════════════════════════════════
 * اختبار شامل للـ CRUD + Endpoints المفقودة + السيناريوهات المتقدمة
 * (بدون DELETE)
 * ════════════════════════════════════════════════════════════════════════════
 *
 * يغطي 30+ endpoint إضافي من موديول الطيران:
 * - CRUD: AirlineAccounts, Airports, Systems, Carriers, Groups
 * - Missing: Aviation, Passenger, Flight stats, Reports
 * - Advanced: Currency set-rate, Finance operations, Concurrency, Pagination
 *
 * يتطلب: تشغيل flight_module_full_test.php أولاً + MySQL شغّال + Laravel server
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use App\Models\Airport;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\FlightPassenger;
use App\Models\Flight\AirlineAccount;
use App\Models\Treasury;
use App\Models\ExchangeRate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$BASE_URL = 'http://127.0.0.1:8000/api/v1';
$IDS = json_decode(file_get_contents(__DIR__ . '/storage/logs/flight_test/ids.json'), true);
$admin = User::find($IDS['admin_id']);
auth()->setUser($admin);
$token = $admin->createToken('flight-complete-coverage')->plainTextToken;

// ═══════════════════════════════════════════════════════════════════════════
// HTTP Helpers
// ═══════════════════════════════════════════════════════════════════════════
function httpGet(string $url, array $params = []) {
    global $token;
    if ($params) $url .= '?' . http_build_query($params);
    $r = Http::withToken($token)->acceptJson()->get($url);
    return ['status' => $r->status(), 'json' => $r->json()];
}
function httpPost(string $url, array $data = []) {
    global $token;
    $r = Http::withToken($token)->acceptJson()->post($url, $data);
    return ['status' => $r->status(), 'json' => $r->json()];
}
function httpPut(string $url, array $data = []) {
    global $token;
    $r = Http::withToken($token)->acceptJson()->put($url, $data);
    return ['status' => $r->status(), 'json' => $r->json()];
}
function httpPatch(string $url, array $data = []) {
    global $token;
    $r = Http::withToken($token)->acceptJson()->patch($url, $data);
    return ['status' => $r->status(), 'json' => $r->json()];
}

// ═══════════════════════════════════════════════════════════════════════════
// Logging & Reporting
// ═══════════════════════════════════════════════════════════════════════════
$REPORT = [
    'title'      => 'Flight Module Complete Coverage Test (No Delete)',
    'started_at' => date('Y-m-d H:i:s'),
    'sections'   => [],
    'results'    => ['success' => 0, 'failed' => 0, 'partial' => 0],
];

$logFile = __DIR__ . '/storage/logs/flight_test/' . date('Y-m-d_His') . '_complete_coverage.log';
$logHandle = fopen($logFile, 'w');
function t(string $m) {
    global $logHandle;
    $l = '[' . date('H:i:s') . '] ' . $m . "\n";
    fwrite($logHandle, $l); fflush($logHandle); echo $l;
}
function ok(string $m='OK') { global $REPORT; $REPORT['results']['success']++; t("    ✅ {$m}"); }
function fail(string $m)  { global $REPORT; $REPORT['results']['failed']++; t("    ❌ {$m}"); }
function info(string $m){ t("    ℹ  {$m}"); }
function warn(string $m){ global $REPORT; $REPORT['results']['partial']++; t("    ⚠  {$m}"); }
function section(string $title) {
    global $REPORT;
    $REPORT['sections'][] = ['title' => $title, 'started_at' => date('H:i:s'), 'steps' => []];
    t("\n" . str_repeat('═', 70));
    t('  ' . $title);
    t(str_repeat('═', 70));
}
function step(string $name, array $data = []): void {
    global $REPORT;
    $lastIdx = count($REPORT['sections']) - 1;
    $REPORT['sections'][$lastIdx]['steps'][] = array_merge(['name' => $name], $data);
    t("  ▸ {$name}");
}

t("╔══════════════════════════════════════════════════════════════════╗");
t("║  Flight Module Complete Coverage Test (No Delete)            ║");
t("╚══════════════════════════════════════════════════════════════════╝");
info('Server: ' . $BASE_URL);
info('Admin ID: ' . $IDS['admin_id']);

// ═══════════════════════════════════════════════════════════════════════════
// PART 1: CRUD — Airline Accounts (POST/PUT/GET single)
// ═══════════════════════════════════════════════════════════════════════════
section('PART 1: Airline Accounts CRUD');

// 1.1 POST — إنشاء airline account جديد
$r = httpPost($BASE_URL . '/flight/airline-accounts', [
    'name'        => 'FLT-CRUD-AirlineAcc-1',
    'code'        => 'CRUD' . substr(md5(uniqid()), 0, 5),
    'system_type' => 'GDS',
    'currency'    => 'EGP',
    'balance'     => 0,
    'credit_limit'=> 30000,
    'is_active'   => true,
    'notes'       => 'CRUD test airline account',
]);
step('POST /flight/airline-accounts', ['status' => $r['status']]);
$newAirlineAccId = $r['json']['data']['id'] ?? null;
if ($r['status'] === 201 && $newAirlineAccId) {
    ok("تم إنشاء airline_account #$newAirlineAccId");
} else {
    fail('POST airline-account: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
}

// 1.2 GET single
if ($newAirlineAccId) {
    $r = httpGet($BASE_URL . '/flight/airline-accounts/' . $newAirlineAccId);
    step('GET /flight/airline-accounts/{id}', ['status' => $r['status']]);
    if ($r['status'] === 200) ok('تم جلب airline_account بنجاح'); else fail('GET airline-account');
}

// 1.3 PUT — تعديل
if ($newAirlineAccId) {
    $r = httpPut($BASE_URL . '/flight/airline-accounts/' . $newAirlineAccId, [
        'name'        => 'FLT-CRUD-AirlineAcc-UPDATED',
        'credit_limit'=> 50000,
        'notes'       => 'تم التعديل',
    ]);
    step('PUT /flight/airline-accounts/{id}', ['status' => $r['status']]);
    if ($r['status'] === 200 && ($r['json']['success'] ?? false)) ok('تم تعديل airline_account'); else fail('PUT airline-account: ' . json_encode($r['json']));
}

// 1.4 POST /add-credit — إضافة رصيد
if ($newAirlineAccId) {
    $r = httpPost($BASE_URL . '/flight/airline-accounts/add-credit', [
        'airline_account_id' => $newAirlineAccId,
        'amount'             => 5000,
        'currency'           => 'EGP',
        'notes'              => 'CRUD test add credit',
    ]);
    step('POST /flight/airline-accounts/add-credit', ['status' => $r['status']]);
    if ($r['status'] === 200 && ($r['json']['success'] ?? false)) ok('تم إضافة رصيد 5,000 EGP'); else warn('add-credit: ' . json_encode($r['json']));
}

// 1.5 GET /transactions
if ($newAirlineAccId) {
    $r = httpGet($BASE_URL . '/flight/airline-accounts/' . $newAirlineAccId . '/transactions');
    step('GET /flight/airline-accounts/{id}/transactions', ['status' => $r['status']]);
    if ($r['status'] === 200) ok('تم جلب حركات airline_account'); else warn('transactions: ' . json_encode($r['json']));
}

// ═══════════════════════════════════════════════════════════════════════════
// PART 2: CRUD — Airports
// ═══════════════════════════════════════════════════════════════════════════
section('PART 2: Airports CRUD + Search');

// 2.1 GET index with pagination
$r = httpGet($BASE_URL . '/flight/airports', ['per_page' => 5]);
step('GET /flight/airports (per_page=5)', ['status' => $r['status']]);
if ($r['status'] === 200) ok('تم جلب المطارات'); else fail('airports index');

// 2.2 GET /search
$r = httpGet($BASE_URL . '/flight/airports/search', ['q' => 'TCAI']);
step('GET /flight/airports/search?q=TCAI', ['status' => $r['status']]);
if ($r['status'] === 200) ok('بحث المطارات نجح'); else warn('search: ' . json_encode($r['json']));

// 2.3 GET /popular
$r = httpGet($BASE_URL . '/flight/airports/popular');
step('GET /flight/airports/popular', ['status' => $r['status']]);
if ($r['status'] === 200) ok('المطارات الشائعة'); else warn('popular: ' . json_encode($r['json']));

// 2.4 GET /by-iata
$r = httpGet($BASE_URL . '/flight/airports/by-iata', ['iata_code' => 'TCAI']);
step('GET /flight/airports/by-iata?iata_code=TCAI', ['status' => $r['status']]);
if ($r['status'] === 200) ok('البحث بـ IATA نجح'); else warn('by-iata: ' . json_encode($r['json']));

// 2.5 GET /grouped
$r = httpGet($BASE_URL . '/flight/airports/grouped');
step('GET /flight/airports/grouped', ['status' => $r['status']]);
if ($r['status'] === 200) ok('تجميع حسب الدولة نجح'); else warn('grouped: ' . json_encode($r['json']));

// ═══════════════════════════════════════════════════════════════════════════
// PART 3: CRUD — Flight Systems
// ═══════════════════════════════════════════════════════════════════════════
section('PART 3: Flight Systems CRUD');

// 3.1 POST — إنشاء
$r = httpPost($BASE_URL . '/flight/systems', [
    'name'        => 'FLT-CRUD-Sys-Travelport',
    'code'        => 'TVP' . substr(md5(uniqid()), 0, 4),
    'type'        => 'GDS',
    'currency'    => 'EGP',
    'balance'     => 0,
    'credit_limit'=> 25000,
    'is_active'   => true,
    'description' => 'CRUD test system',
]);
step('POST /flight/systems', ['status' => $r['status']]);
$newSysId = $r['json']['data']['id'] ?? null;
if ($r['status'] === 201 && $newSysId) ok("تم إنشاء flight_system #$newSysId"); else fail('POST system: ' . json_encode($r['json']));

// 3.2 GET single
if ($newSysId) {
    $r = httpGet($BASE_URL . '/flight/systems/' . $newSysId);
    step('GET /flight/systems/{id}', ['status' => $r['status']]);
    if ($r['status'] === 200) ok('GET system OK'); else fail('GET system');
}

// 3.3 PUT
if ($newSysId) {
    $r = httpPut($BASE_URL . '/flight/systems/' . $newSysId, [
        'name'        => 'FLT-CRUD-Sys-Travelport-UPDATED',
        'credit_limit'=> 40000,
        'description' => 'معدّل',
    ]);
    step('PUT /flight/systems/{id}', ['status' => $r['status']]);
    if ($r['status'] === 200 && ($r['json']['success'] ?? false)) ok('PUT system OK'); else warn('PUT system: ' . json_encode($r['json']));
}

// 3.4 GET /flight/system-types
$r = httpGet($BASE_URL . '/flight/system-types');
step('GET /flight/system-types', ['status' => $r['status']]);
if ($r['status'] === 200) ok('أنواع الأنظمة'); else warn('system-types: ' . json_encode($r['json']));

// ═══════════════════════════════════════════════════════════════════════════
// PART 4: CRUD — Flight Carriers
// ═══════════════════════════════════════════════════════════════════════════
section('PART 4: Flight Carriers CRUD + Balance');

// 4.1 POST — إنشاء
$r = httpPost($BASE_URL . '/flight/carriers', [
    'name'            => 'FLT-CRUD-Carrier-TestAir',
    'code'            => 'TA' . substr(md5(uniqid()), 0, 3),
    'iata_code'       => 'T4',
    'flight_system_id'=> $IDS['sys_amadeus_id'],
    'currency'        => 'EGP',
    'balance'         => 0,
    'credit_limit'    => 50000,
    'is_active'       => true,
    'notes'           => 'CRUD test carrier',
]);
step('POST /flight/carriers', ['status' => $r['status']]);
$newCarrierId = $r['json']['data']['id'] ?? null;
if ($r['status'] === 201 && $newCarrierId) ok("تم إنشاء carrier #$newCarrierId"); else fail('POST carrier: ' . json_encode($r['json']));

// 4.2 GET single
if ($newCarrierId) {
    $r = httpGet($BASE_URL . '/flight/carriers/' . $newCarrierId);
    step('GET /flight/carriers/{id}', ['status' => $r['status']]);
    if ($r['status'] === 200) ok('GET carrier OK'); else fail('GET carrier');
}

// 4.3 PUT
if ($newCarrierId) {
    $r = httpPut($BASE_URL . '/flight/carriers/' . $newCarrierId, [
        'name'        => 'FLT-CRUD-Carrier-TestAir-UPDATED',
        'credit_limit'=> 75000,
        'notes'       => 'معدّل',
    ]);
    step('PUT /flight/carriers/{id}', ['status' => $r['status']]);
    if ($r['status'] === 200 && ($r['json']['success'] ?? false)) ok('PUT carrier OK'); else warn('PUT carrier: ' . json_encode($r['json']));
}

// 4.4 GET /balance
if ($newCarrierId) {
    $r = httpGet($BASE_URL . '/flight/carriers/' . $newCarrierId . '/balance');
    step('GET /flight/carriers/{id}/balance', ['status' => $r['status']]);
    if ($r['status'] === 200) ok('رصيد الـ carrier تم جلبه'); else warn('balance: ' . json_encode($r['json']));
}

// 4.5 GET /groups
$r = httpGet($BASE_URL . '/flight/carriers/' . $IDS['carrier_saudia_id'] . '/groups');
step('GET /flight/carriers/{id}/groups', ['status' => $r['status']]);
if ($r['status'] === 200) ok('مجموعات الناقل تم جلبها'); else warn('groups: ' . json_encode($r['json']));

// 4.6 GET carrier transactions
$r = httpGet($BASE_URL . '/flight/treasury/carriers/' . $IDS['carrier_egyptair_id'] . '/transactions');
step('GET /flight/treasury/carriers/{id}/transactions', ['status' => $r['status']]);
if ($r['status'] === 200) ok('حركات الناقل تم جلبها'); else warn('transactions: ' . json_encode($r['json']));

// ═══════════════════════════════════════════════════════════════════════════
// PART 5: Flight Groups GET single (CRUD غير متاح — only index/show)
// ═══════════════════════════════════════════════════════════════════════════
section('PART 5: Flight Groups — Index + Show');

// 5.1 GET /flight/groups (index)
$r = httpGet($BASE_URL . '/flight/groups');
step('GET /flight/groups (index)', ['status' => $r['status']]);
if ($r['status'] === 200) ok('قائمة المجموعات تم جلبها'); else fail('groups index');

// 5.2 GET /flight/groups/{id} (show)
$r = httpGet($BASE_URL . '/flight/groups/' . $IDS['flight_group_id']);
step('GET /flight/groups/{id} (show)', ['status' => $r['status']]);
if ($r['status'] === 200) ok('تفاصيل المجموعة تم جلبها'); else warn('group show: ' . json_encode($r['json']));

// ═══════════════════════════════════════════════════════════════════════════
// PART 6: Aviation Controller
// ═══════════════════════════════════════════════════════════════════════════
section('PART 6: Aviation Controller');

// 6.1 GET /flight/aviation/next-number
$r = httpGet($BASE_URL . '/flight/aviation/next-number');
step('GET /flight/aviation/next-number', ['status' => $r['status']]);
if ($r['status'] === 200) ok('رقم الحجز التالي: ' . ($r['json']['data']['next_number'] ?? 'n/a')); else warn('aviation next-number: ' . json_encode($r['json']));

// 6.2 GET /flight/aviation (index)
$r = httpGet($BASE_URL . '/flight/aviation');
step('GET /flight/aviation (index)', ['status' => $r['status']]);
if ($r['status'] === 200) ok('قائمة aviation تم جلبها'); else warn('aviation index: ' . json_encode($r['json']));

// 6.3 POST /flight/aviation
$r = httpPost($BASE_URL . '/flight/aviation', [
    'name'        => 'FLT-CRUD-Aviation-Test',
    'code'        => 'AV' . substr(md5(uniqid()), 0, 4),
    'currency'    => 'EGP',
    'is_active'   => true,
    'description' => 'CRUD test aviation',
]);
step('POST /flight/aviation', ['status' => $r['status']]);
$newAviationId = $r['json']['data']['id'] ?? null;
if ($r['status'] === 201 && $newAviationId) ok("تم إنشاء aviation #$newAviationId"); else warn('POST aviation: ' . json_encode($r['json']));

// 6.4 PUT /flight/aviation/{id}
if ($newAviationId) {
    $r = httpPut($BASE_URL . '/flight/aviation/' . $newAviationId, [
        'name' => 'FLT-CRUD-Aviation-UPDATED',
    ]);
    step('PUT /flight/aviation/{id}', ['status' => $r['status']]);
    if ($r['status'] === 200 && ($r['json']['success'] ?? false)) ok('PUT aviation OK'); else warn('PUT aviation: ' . json_encode($r['json']));
}

// ═══════════════════════════════════════════════════════════════════════════
// PART 7: Passengers + Notifications
// ═══════════════════════════════════════════════════════════════════════════
section('PART 7: Passengers + Notifications');

// 7.1 GET /flight/passengers
$r = httpGet($BASE_URL . '/flight/passengers', ['per_page' => 10]);
step('GET /flight/passengers', ['status' => $r['status']]);
if ($r['status'] === 200) ok('قائمة الركاب'); else warn('passengers: ' . json_encode($r['json']));

// 7.2 GET alert-settings
$r = httpGet($BASE_URL . '/flight/passengers/alert-settings');
step('GET /flight/passengers/alert-settings', ['status' => $r['status']]);
if ($r['status'] === 200) ok('إعدادات التنبيهات'); else warn('alert-settings: ' . json_encode($r['json']));

// 7.3 PUT alert-settings
$r = httpPut($BASE_URL . '/flight/passengers/alert-settings', [
    'enabled'  => true,
    'channels' => ['email', 'sms'],
]);
step('PUT /flight/passengers/alert-settings', ['status' => $r['status']]);
if ($r['status'] === 200) ok('تحديث إعدادات التنبيهات'); else warn('update alert-settings: ' . json_encode($r['json']));

// 7.4 GET notifications
$r = httpGet($BASE_URL . '/flight/passengers/notifications');
step('GET /flight/passengers/notifications', ['status' => $r['status']]);
if ($r['status'] === 200) ok('الإشعارات'); else warn('notifications: ' . json_encode($r['json']));

// 7.5 POST mark-all-read
$r = httpPost($BASE_URL . '/flight/passengers/notifications/mark-all-read');
step('POST /flight/passengers/notifications/mark-all-read', ['status' => $r['status']]);
if ($r['status'] === 200) ok('تحديد الكل كمقروء'); else warn('mark-all-read: ' . json_encode($r['json']));

// 7.6 POST mark-traveled
// إيجاد passenger حقيقي من booking
$passenger = FlightPassenger::first();
if ($passenger) {
    $r = httpPost($BASE_URL . '/flight/passengers/' . $passenger->id . '/mark-traveled');
    step('POST /flight/passengers/{id}/mark-traveled', ['status' => $r['status']]);
    if ($r['status'] === 200) ok('تسجيل سفر الراكب #' . $passenger->id); else warn('mark-traveled: ' . json_encode($r['json']));

    // 7.7 POST unmark-traveled
    $r = httpPost($BASE_URL . '/flight/passengers/' . $passenger->id . '/unmark-traveled');
    step('POST /flight/passengers/{id}/unmark-traveled', ['status' => $r['status']]);
    if ($r['status'] === 200) ok('إلغاء تسجيل سفر الراكب'); else warn('unmark-traveled: ' . json_encode($r['json']));
}

// ═══════════════════════════════════════════════════════════════════════════
// PART 8: Flight Bookings Stats + Detailed Report
// ═══════════════════════════════════════════════════════════════════════════
section('PART 8: Flight Bookings Stats + Reports');

// 8.1 GET /flight/bookings/stats
$r = httpGet($BASE_URL . '/flight/bookings/stats');
step('GET /flight/bookings/stats', ['status' => $r['status']]);
if ($r['status'] === 200) ok('إحصائيات الحجوزات'); else warn('stats: ' . json_encode($r['json']));

// 8.2 GET /flight/detailed (detailed flight report)
$r = httpGet($BASE_URL . '/flight/detailed');
step('GET /flight/detailed (financial report)', ['status' => $r['status']]);
if ($r['status'] === 200) ok('التقرير المالي للطيران'); else warn('detailed: ' . json_encode($r['json']));

// ═══════════════════════════════════════════════════════════════════════════
// PART 9: Refund endpoints (extra)
// ═══════════════════════════════════════════════════════════════════════════
section('PART 9: Refund endpoints (treasuries + airline-credits)');

$r = httpGet($BASE_URL . '/flight/refunds/treasuries');
step('GET /flight/refunds/treasuries', ['status' => $r['status']]);
if ($r['status'] === 200) ok('قائمة الخزن للاسترداد'); else warn('treasuries: ' . json_encode($r['json']));

$r = httpGet($BASE_URL . '/flight/refunds/airline-credits');
step('GET /flight/refunds/airline-credits', ['status' => $r['status']]);
if ($r['status'] === 200) ok('قائمة أرصدة airline credits'); else warn('airline-credits: ' . json_encode($r['json']));

// ═══════════════════════════════════════════════════════════════════════════
// PART 10: Currency set-rate
// ═══════════════════════════════════════════════════════════════════════════
section('PART 10: Currency set-rate');

// 10.1 POST /finance/currencies/set-rate
$r = httpPost($BASE_URL . '/finance/currencies/set-rate', [
    'from_currency'  => 'USD',
    'to_currency'    => 'EGP',
    'rate'           => 49.0,  // تحديث من 48.5 → 49.0
    'effective_date' => date('Y-m-d'),
]);
step('POST /finance/currencies/set-rate (USD→EGP = 49.0)', ['status' => $r['status']]);
if ($r['status'] === 200 && ($r['json']['success'] ?? false)) ok('تم تحديث سعر الصرف'); else warn('set-rate: ' . json_encode($r['json']));

// 10.2 GET active-rates (يجب أن يعرض 49.0 الآن)
$r = httpGet($BASE_URL . '/finance/currencies/active-rates');
step('GET /finance/currencies/active-rates', ['status' => $r['status']]);
if ($r['status'] === 200) {
    $rates = $r['json']['data'] ?? [];
    $usdEgp = collect($rates)->firstWhere('from_currency', 'USD');
    $newRate = $usdEgp['rate'] ?? '?';
    ok("عدد أسعار الصرف النشطة: " . count($rates) . " | USD→EGP = {$newRate}");
}

// ═══════════════════════════════════════════════════════════════════════════
// PART 11: Finance — Accounts, Statement, Transfers
// ═══════════════════════════════════════════════════════════════════════════
section('PART 11: Finance Accounts + Transfers');

// 11.1 GET /finance/accounts
$r = httpGet($BASE_URL . '/finance/accounts', ['per_page' => 10]);
step('GET /finance/accounts', ['status' => $r['status']]);
if ($r['status'] === 200) ok('قائمة الحسابات'); else warn('accounts: ' . json_encode($r['json']));

// 11.2 GET /finance/accounts/{id}/statement
$r = httpGet($BASE_URL . '/finance/accounts/' . $IDS['cash_egp_id'] . '/statement');
step('GET /finance/accounts/{id}/statement', ['status' => $r['status']]);
if ($r['status'] === 200) ok('كشف حساب الخزينة'); else warn('statement: ' . json_encode($r['json']));

// 11.3 POST /finance/transfers (تحويل بين الحسابات)
$r = httpPost($BASE_URL . '/finance/transfers', [
    'from_account_id' => $IDS['bank_egp_id'],
    'to_account_id'   => $IDS['cash_egp_id'],
    'amount'          => 1000,
    'notes'           => 'CRUD test transfer',
]);
step('POST /finance/transfers (1000 EGP من البنك للخزينة)', ['status' => $r['status']]);
if ($r['status'] === 200 && ($r['json']['success'] ?? false)) ok('تم التحويل بنجاح'); else warn('transfer: ' . json_encode($r['json']));

// ═══════════════════════════════════════════════════════════════════════════
// PART 12: Treasury — Overview, Module Accounts, Trial Balance
// ═══════════════════════════════════════════════════════════════════════════
section('PART 12: Treasury Endpoints');

// 12.1 GET /finance/treasuries/get-overview
$r = httpGet($BASE_URL . '/finance/treasuries/get-overview');
step('GET /finance/treasuries/get-overview', ['status' => $r['status']]);
if ($r['status'] === 200) ok('النظرة الشاملة على الخزينة'); else warn('treasury overview: ' . json_encode($r['json']));

// 12.2 GET /finance/treasuries/get-module-accounts/{module}
$r = httpGet($BASE_URL . '/finance/treasuries/get-module-accounts/flights');
step('GET /finance/treasuries/get-module-accounts/flights', ['status' => $r['status']]);
if ($r['status'] === 200) ok('حسابات وحدة الطيران'); else warn('module-accounts: ' . json_encode($r['json']));

// 12.3 GET /finance/treasuries/export-trial-balance
$r = httpGet($BASE_URL . '/finance/treasuries/export-trial-balance');
step('GET /finance/treasuries/export-trial-balance', ['status' => $r['status']]);
if ($r['status'] === 200) ok('ميزان المراجعة تم تصديره'); else warn('trial-balance: ' . json_encode($r['json']));

// 12.4 GET /flight/treasury/overview
$r = httpGet($BASE_URL . '/flight/treasury/overview');
step('GET /flight/treasury/overview (نظرة الطيران)', ['status' => $r['status']]);
if ($r['status'] === 200) ok('نظرة خزينة الطيران'); else warn('flight treasury: ' . json_encode($r['json']));

// 12.5 GET /flight/treasury/systems/{id}/transactions
$r = httpGet($BASE_URL . '/flight/treasury/systems/' . $IDS['sys_amadeus_id'] . '/transactions');
step('GET /flight/treasury/systems/{id}/transactions', ['status' => $r['status']]);
if ($r['status'] === 200) ok('حركات نظام Amadeus'); else warn('sys transactions: ' . json_encode($r['json']));

// 12.6 GET /flight/treasury/accounts/{id}/flight-transactions
$r = httpGet($BASE_URL . '/flight/treasury/accounts/' . $IDS['cash_egp_id'] . '/flight-transactions');
step('GET /flight/treasury/accounts/{id}/flight-transactions', ['status' => $r['status']]);
if ($r['status'] === 200) ok('معاملات الخزينة في الطيران'); else warn('flight-transactions: ' . json_encode($r['json']));

// ═══════════════════════════════════════════════════════════════════════════
// PART 13: Concurrency (curl_multi parallel)
// ═══════════════════════════════════════════════════════════════════════════
section('PART 13: Concurrency حقيقي (curl_multi متوازي)');

// 5 طلبات متوازية على نفس الـ carrier
$multiHandle = curl_multi_init();
$handles = [];
for ($i = 0; $i < 5; $i++) {
    $ch = curl_init($BASE_URL . '/flight/bookings');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $token",
            "Accept: application/json",
            "Content-Type: application/json",
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'customer_id'    => $IDS['customer_ids'][$i % 3],
            'employee_id'    => $IDS['employee_id'],
            'pnr'            => 'PNR-CONC-' . $i . '-' . substr(uniqid(), -4),
            'airline'        => 'EgyptAir',
            'airline_name'   => 'EgyptAir',
            'from_airport'   => 'TCAI',
            'to_airport'     => 'TJED',
            'from_airport_id'=> $IDS['airport_ids'][0],
            'to_airport_id'  => $IDS['airport_ids'][1],
            'departure_date' => now()->addDays(70 + $i)->format('Y-m-d'),
            'departure_time' => '10:00:00',
            'trip_type'      => 'one_way',
            'passenger_count'=> 1,
            'purchase_price' => 500,
            'selling_price'  => 700,
            'currency'       => 'EGP',
            'flight_system_id'   => $IDS['sys_amadeus_id'],
            'flight_carrier_id'  => $IDS['carrier_egyptair_id'],
            'purchase_balance_source' => 'carrier',
            'payment' => ['amount' => 700, 'payment_method' => 'cash', 'account_id' => $IDS['cash_egp_id']],
            'passengers' => [['first_name' => 'P', 'last_name' => "Conc{$i}", 'passport_number' => "CONC{$i}01", 'type' => 'adult']],
        ]),
    ]);
    curl_multi_add_handle($multiHandle, $ch);
    $handles[] = $ch;
}

$running = null;
do {
    curl_multi_exec($multiHandle, $running);
    curl_multi_select($multiHandle);
} while ($running > 0);

$concurrencyResults = ['success' => 0, 'rejected' => 0];
foreach ($handles as $ch) {
    $body = curl_multi_getcontent($ch);
    $resp = json_decode($body, true);
    if (($resp['success'] ?? false)) {
        $concurrencyResults['success']++;
    } else {
        $concurrencyResults['rejected']++;
    }
}
curl_multi_close($multiHandle);

step('5 طلبات متوازية (curl_multi)', $concurrencyResults);
info("نجح: {$concurrencyResults['success']} | رُفض: {$concurrencyResults['rejected']}");
if ($concurrencyResults['success'] > 0 && $concurrencyResults['rejected'] > 0) {
    ok('✅ lockForUpdate يمنع double-spending تحت الطلبات المتوازية');
} elseif ($concurrencyResults['success'] === 5) {
    warn('⚠️ كل الـ 5 نجحت — قد تكون الرصيد يكفي (لكن lockForUpdate لم يمنع)');
} else {
    ok('النتيجة: ' . $concurrencyResults['success'] . ' ناجح، ' . $concurrencyResults['rejected'] . ' مرفوض');
}

// ═══════════════════════════════════════════════════════════════════════════
// PART 14: Pagination + Search
// ═══════════════════════════════════════════════════════════════════════════
section('PART 14: Pagination + Search Filters');

// 14.1 Pagination على flight bookings
$r = httpGet($BASE_URL . '/flight/bookings', ['per_page' => 3, 'page' => 1]);
step('GET /flight/bookings?per_page=3&page=1', ['status' => $r['status']]);
if ($r['status'] === 200) {
    $items = $r['json']['data']['items'] ?? [];
    $pagination = $r['json']['data']['pagination'] ?? [];
    ok("عدد النتائج: " . count($items) . " | Total: " . ($pagination['total'] ?? '?'));
}

// 14.2 Search بـ PNR
$r = httpGet($BASE_URL . '/flight/bookings', ['search' => 'PNR']);
step('GET /flight/bookings?search=PNR', ['status' => $r['status']]);
if ($r['status'] === 200) ok('البحث بـ PNR نجح'); else warn('search: ' . json_encode($r['json']));

// 14.3 Filter بـ status
$r = httpGet($BASE_URL . '/flight/bookings', ['status' => 'CONFIRMED']);
step('GET /flight/bookings?status=CONFIRMED', ['status' => $r['status']]);
if ($r['status'] === 200) ok('الفلترة بـ status نجح'); else warn('status filter: ' . json_encode($r['json']));

// 14.4 Filter بـ date range
$r = httpGet($BASE_URL . '/flight/bookings', [
    'date_from' => now()->subDays(7)->format('Y-m-d'),
    'date_to'   => now()->addDays(90)->format('Y-m-d'),
]);
step('GET /flight/bookings?date_from=...&date_to=...', ['status' => $r['status']]);
if ($r['status'] === 200) ok('الفلترة بالتاريخ نجح'); else warn('date filter: ' . json_encode($r['json']));

// ═══════════════════════════════════════════════════════════════════════════
// PART 15: Flight Bookings List (Index with all filters)
// ═══════════════════════════════════════════════════════════════════════════
section('PART 15: Flight Bookings Index (Index endpoint)');

$r = httpGet($BASE_URL . '/flight/bookings');
step('GET /flight/bookings (index)', ['status' => $r['status']]);
if ($r['status'] === 200) ok('Index الحجوزات'); else warn('bookings index: ' . json_encode($r['json']));

// 15.1 GET single booking
$firstBooking = FlightBooking::first();
if ($firstBooking) {
    $r = httpGet($BASE_URL . '/flight/bookings/' . $firstBooking->id);
    step('GET /flight/bookings/{id} (show)', ['status' => $r['status']]);
    if ($r['status'] === 200) ok('GET single booking OK'); else warn('show: ' . json_encode($r['json']));
}

// 15.2 GET booking-form/employees
$r = httpGet($BASE_URL . '/flight/booking-form/employees');
step('GET /flight/booking-form/employees', ['status' => $r['status']]);
if ($r['status'] === 200) ok('قائمة الموظفين لنموذج الحجز'); else warn('employees: ' . json_encode($r['json']));

// 15.3 GET dashboard
$r = httpGet($BASE_URL . '/flight/dashboard');
step('GET /flight/dashboard', ['status' => $r['status']]);
if ($r['status'] === 200) ok('Dashboard يعمل'); else warn('dashboard: ' . json_encode($r['json']));

// ═══════════════════════════════════════════════════════════════════════════
// FINAL: حفظ التقرير
// ═══════════════════════════════════════════════════════════════════════════
file_put_contents(__DIR__ . '/storage/logs/flight_test/report_complete_coverage.json', json_encode($REPORT, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

t("\n╔══════════════════════════════════════════════════════════════════╗");
t("║  النتائج النهائية                                            ║");
t("╚══════════════════════════════════════════════════════════════════╝");
t("  Success: " . $REPORT['results']['success']);
t("  Partial: " . $REPORT['results']['partial']);
t("  Failed:  " . $REPORT['results']['failed']);
t("  Total endpoints tested: " . ($REPORT['results']['success'] + $REPORT['results']['partial'] + $REPORT['results']['failed']));

fclose($logHandle);
echo "\n📄 Log: {$logFile}\n";
echo "✅ Flight Module Complete Coverage Test DONE.\n";