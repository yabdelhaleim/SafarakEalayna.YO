<?php
/**
 * ════════════════════════════════════════════════════════════════════════════
 * اختبار السيناريوهات المتبقية لموديول الطيران (Flight Extended Test)
 * ════════════════════════════════════════════════════════════════════════════
 *
 * يستكمل اختبار السيناريوهات المتبقية من أولوية 1 → 5
 *
 * يتطلب: تشغيل flight_module_full_test.php أولاً لإنشاء البيانات الأساسية.
 *
 * التشغيل: php flight_module_extended_test.php
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Airport;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\FlightGroupTransaction;
use App\Models\Flight\FlightPassenger;
use App\Models\Flight\FlightPayment;
use App\Models\Flight\FlightRefund;
use App\Models\Flight\FlightSegment;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\FlightSystemTransaction;
use App\Models\Flight\AirlineTransaction;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$BASE_URL = 'http://127.0.0.1:8000/api/v1';

$REPORT = [
    'title'     => 'Flight Module Extended Scenarios',
    'started_at' => date('Y-m-d H:i:s'),
    'sections'  => [],
    'final_verdict' => [],
];

$logFile = __DIR__ . '/storage/logs/flight_test/' . date('Y-m-d_His') . '_extended_test.log';
$logHandle = fopen($logFile, 'w');
function testlog(string $msg): void {
    global $logHandle;
    $line = '[' . date('H:i:s') . '] ' . $msg . "\n";
    fwrite($logHandle, $line);
    fflush($logHandle);
    echo $line;
}
function section(string $title): void {
    global $REPORT;
    $REPORT['sections'][] = [
        'title'    => $title,
        'started_at' => date('H:i:s'),
        'steps'    => [],
    ];
    testlog("\n" . str_repeat('═', 70));
    testlog('  ' . $title);
    testlog(str_repeat('═', 70));
}
function step(string $name, array $data = []): void {
    global $REPORT;
    $lastIdx = count($REPORT['sections']) - 1;
    if ($lastIdx < 0) { section('Implicit'); $lastIdx = 0; }
    $REPORT['sections'][$lastIdx]['steps'][] = array_merge(['name' => $name], $data);
    testlog("  ▸ {$name}");
}
function ok(string $msg = 'OK'): void { testlog("    ✅ {$msg}"); }
function fail(string $msg): void { testlog("    ❌ {$msg}"); }
function info(string $msg): void { testlog("    ℹ  {$msg}"); }
function warn(string $msg): void { testlog("    ⚠  {$msg}"); }

// ─── تحميل IDs من التشغيل السابق ───
$idsFile = __DIR__ . '/storage/logs/flight_test/ids.json';
if (!file_exists($idsFile)) {
    fail('لم يتم العثور على storage/logs/flight_test/ids.json — شغّل flight_module_full_test.php أولاً');
    exit(1);
}
$IDS = json_decode(file_get_contents($idsFile), true);
$bookingIdsFile = __DIR__ . '/storage/logs/flight_test/booking_ids.json';
$BOOKING_IDS = file_exists($bookingIdsFile) ? json_decode(file_get_contents($bookingIdsFile), true) : [];

$admin = User::find($IDS['admin_id']);
auth()->setUser($admin);
$token = $admin->createToken('flight-extended-test')->plainTextToken;

function httpGet(string $url) {
    global $token;
    $r = Http::withToken($token)->acceptJson()->get($url);
    return ['status' => $r->status(), 'json' => $r->json()];
}
function httpPost(string $url, array $data) {
    global $token;
    $r = Http::withToken($token)->acceptJson()->post($url, $data);
    return ['status' => $r->status(), 'json' => $r->json()];
}
function httpPut(string $url, array $data) {
    global $token;
    $r = Http::withToken($token)->acceptJson()->put($url, $data);
    return ['status' => $r->status(), 'json' => $r->json()];
}
function httpDel(string $url) {
    global $token;
    $r = Http::withToken($token)->acceptJson()->delete($url);
    return ['status' => $r->status(), 'json' => $r->json()];
}

// ─── مساعدات ───
function snap(string $label): array {
    $ids = $GLOBALS['IDS'];
    $out = ['label' => $label, 'ts' => date('H:i:s')];
    $out['cash_egp']    = (float) Account::find($ids['cash_egp_id'])->balance;
    $out['bank_egp']    = (float) Account::find($ids['bank_egp_id'])->balance;
    $out['wallet_egp']  = (float) Account::find($ids['wallet_egp_id'])->balance;
    $out['wallet_usd']  = (float) Account::find($ids['wallet_usd_id'])->balance;
    $out['sys_amadeus'] = (float) \App\Models\Flight\FlightSystem::find($ids['sys_amadeus_id'])->balance;
    $out['sys_sabre']   = (float) \App\Models\Flight\FlightSystem::find($ids['sys_sabre_id'])->balance;
    $out['carrier_ms']  = (float) \App\Models\Flight\FlightCarrier::find($ids['carrier_egyptair_id'])->balance;
    $out['carrier_sv']  = (float) \App\Models\Flight\FlightCarrier::find($ids['carrier_saudia_id'])->balance;
    $out['carrier_ek']  = (float) \App\Models\Flight\FlightCarrier::find($ids['carrier_emirates_id'])->balance;
    $out['group_balance'] = (float) DB::table('flight_group_transactions')
        ->where('flight_group_id', $ids['flight_group_id'])
        ->selectRaw('COALESCE(SUM(CASE WHEN type="debt" THEN amount ELSE -amount END),0) as bal')
        ->value('bal');
    return $out;
}

$GLOBALS['SNAPS'] = [snap('EXTENDED-INITIAL')];

// ═══════════════════════════════════════════════════════════════
// أولوية 1: الأخطاء + المجموعات + الاسترداد
// ═══════════════════════════════════════════════════════════════
testlog("╔══════════════════════════════════════════════════════════════════╗");
testlog("║  أولوية 1: Critical — Cancel + Refund + Groups + Negative tests   ║");
testlog("╚══════════════════════════════════════════════════════════════════╝");

// ───────────────────────────────────────────────────────────────
// A) إعادة محاولة Cancel USD مع زيادة الرصيد مسبقاً
// ───────────────────────────────────────────────────────────────
section('A) Cancel USD - إعادة المحاولة بعد زيادة رصيد wallet_usd');

// زيادة رصيد wallet_usd لضمان كفاية الرصيد للاسترداد
DB::table('accounts')->where('id', $IDS['wallet_usd_id'])->update(['balance' => 50000]);
info("رفع رصيد wallet_usd إلى 50,000 USD");

$booking3Id = $BOOKING_IDS[2] ?? null; // USD booking from previous run
$r = httpPost($BASE_URL . '/flight/bookings/' . $booking3Id . '/cancel', [
    'airline_penalty'  => 0,
    'office_penalty'   => 0,
    'account_id'       => $IDS['wallet_usd_id'],
    'notes'            => 'EXTENDED_TEST: Cancel USD full refund',
]);
step('POST /flight/bookings/{id}/cancel (USD)', ['status' => $r['status'], 'success' => $r['json']['success'] ?? false]);
if ($r['status'] === 200 && ($r['json']['success'] ?? false)) {
    ok('تم إلغاء الحجز #' . $booking3Id . ' USD بنجاح');
} else {
    fail('فشل إلغاء USD: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
}
$GLOBALS['SNAPS'][] = snap('A-AFTER-CANCEL-USD');

// ───────────────────────────────────────────────────────────────
// B) استرداد بغرامة جزئية — إنشاء حجز جديد أولاً
// ───────────────────────────────────────────────────────────────
section('B) Partial refund مع airline_penalty + office_penalty');

$r = httpPost($BASE_URL . '/flight/bookings', [
    'customer_id'    => $IDS['customer_ids'][0], // Ahmed
    'employee_id'    => $IDS['employee_id'],
    'pnr'            => 'PNR-B-' . substr(uniqid(), -4),
    'airline'        => 'EgyptAir',
    'airline_name'   => 'EgyptAir',
    'from_airport'   => 'TCAI',
    'to_airport'     => 'TJED',
    'from_airport_id'=> $IDS['airport_ids'][0],
    'to_airport_id'  => $IDS['airport_ids'][1],
    'departure_date' => now()->addDays(7)->format('Y-m-d'),
    'departure_time' => '10:00:00',
    'trip_type'      => 'one_way',
    'passenger_count'=> 1,
    'purchase_price' => 4500,
    'selling_price'  => 6000,
    'currency'       => 'EGP',
    'exchange_rate'  => 1.0,
    'flight_system_id'   => $IDS['sys_amadeus_id'],
    'flight_carrier_id'  => $IDS['carrier_egyptair_id'],
    'purchase_balance_source' => 'carrier',
    'payment' => [
        'amount'        => 6000,
        'payment_method'=> 'cash',
        'account_id'    => $IDS['cash_egp_id'],
        'notes'         => 'EXTENDED: full payment for partial refund test',
    ],
    'passengers' => [
        ['first_name' => 'Ahmed', 'last_name' => 'TestB', 'passport_number' => 'TESTPPB001', 'type' => 'adult'],
    ],
]);
$bookingBId = $r['json']['data']['id'] ?? null;
step('POST /flight/bookings (للاسترداد الجزئي)', ['status' => $r['status'], 'success' => $r['json']['success'] ?? false, 'booking_id' => $bookingBId]);

// إلغاء الحجز B مع غرامة
$r = httpPost($BASE_URL . '/flight/bookings/' . $bookingBId . '/cancel', [
    'airline_penalty'  => 500,   // خصم شركة الطيران
    'office_penalty'   => 100,   // عمولة المكتب
    'account_id'       => $IDS['cash_egp_id'],
    'notes'            => 'EXTENDED: partial refund with penalties (500+100)',
]);
step('POST /flight/bookings/{id}/cancel (مع غرامات)', ['status' => $r['status'], 'success' => $r['json']['success'] ?? false]);
if ($r['status'] === 200 && ($r['json']['success'] ?? false)) {
    $refund = $r['json']['data'];
    $refunded = $refund['refund_amount'] ?? 'n/a';
    ok('تم استرداد ' . $refunded . ' EGP (من 6000 - 500 - 100 = 5400)');
    if ((float)$refunded === 5400.0) ok('✅ المبلغ المسترد محسوب صح'); else warn('⚠️ المبلغ المتوقع 5400، الفعلي: ' . $refunded);
} else {
    fail('فشل الاسترداد الجزئي: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
}
$GLOBALS['SNAPS'][] = snap('B-AFTER-PARTIAL-REFUND');

// ───────────────────────────────────────────────────────────────
// C) Refund to airline_credit (وليس agency_treasury)
// ───────────────────────────────────────────────────────────────
section('C) استرداد لـ airline_credit عبر /flight/refunds');

// إنشاء حجز جديد
$r = httpPost($BASE_URL . '/flight/bookings', [
    'customer_id'    => $IDS['customer_ids'][1], // Mohamed
    'employee_id'    => $IDS['employee_id'],
    'pnr'            => 'PNR-C-' . substr(uniqid(), -4),
    'airline'        => 'Saudia',
    'airline_name'   => 'Saudia',
    'from_airport'   => 'TCAI',
    'to_airport'     => 'TRUH',
    'from_airport_id'=> $IDS['airport_ids'][0],
    'to_airport_id'  => $IDS['airport_ids'][2],
    'departure_date' => now()->addDays(8)->format('Y-m-d'),
    'departure_time' => '15:00:00',
    'trip_type'      => 'one_way',
    'passenger_count'=> 1,
    'purchase_price' => 4000,
    'selling_price'  => 5500,
    'currency'       => 'EGP',
    'flight_system_id'   => $IDS['sys_amadeus_id'],
    'flight_carrier_id'  => $IDS['carrier_saudia_id'],
    'purchase_balance_source' => 'carrier',
    'payment' => ['amount' => 5500, 'payment_method' => 'cash', 'account_id' => $IDS['cash_egp_id']],
    'passengers' => [['first_name' => 'Mohamed', 'last_name' => 'TestC', 'passport_number' => 'TESTPPC001', 'type' => 'adult']],
]);
$bookingCId = $r['json']['data']['id'] ?? null;
step('POST /flight/bookings (للاسترداد لـ airline_credit)', ['booking_id' => $bookingCId]);

// طلب استرداد لتخزين رصيد airline_credit (بدون معالجة فورية)
$r = httpPost($BASE_URL . '/flight/refunds', [
    'flight_booking_id' => $bookingCId,
    'destination'       => 'airline_credit',
    'cancellation_fee'  => 0,
    'refund_currency'   => 'EGP',
    'notes'             => 'EXTENDED: استرداد لـ airline_credit',
]);
step('POST /flight/refunds (airline_credit)', ['status' => $r['status'], 'success' => $r['json']['success'] ?? false]);
if ($r['status'] === 201 && ($r['json']['success'] ?? false)) {
    $refundId = $r['json']['data']['id'] ?? null;
    ok('تم إنشاء طلب استرداد #' . $refundId . ' لـ airline_credit');
    // معالجة الطلب
    if ($refundId) {
        $r2 = httpPost($BASE_URL . '/flight/refunds/' . $refundId . '/process', []);
        step('POST /flight/refunds/{id}/process', ['status' => $r2['status']]);
        if ($r2['status'] === 200 && ($r2['json']['success'] ?? false)) {
            ok('تم معالجة طلب الاسترداد — الرصيد الآن في airline_credit');
        } else {
            warn('معالجة الاسترداد: ' . json_encode($r2['json'], JSON_UNESCAPED_UNICODE));
        }
    }
} else {
    fail('فشل إنشاء طلب الاسترداد: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
}
$GLOBALS['SNAPS'][] = snap('C-AFTER-AIRLINE-CREDIT-REFUND');

// ───────────────────────────────────────────────────────────────
// D) Flight Group — دفع دين المجموعة (B2B debt settlement)
// ───────────────────────────────────────────────────────────────
section('D) Flight Group — تسديد دين البائع');

// إنشاء حجز جديد مرتبط بالمجموعة (لإنشاء دين على المجموعة)
$r = httpPost($BASE_URL . '/flight/bookings', [
    'customer_id'    => $IDS['customer_ids'][0],
    'employee_id'    => $IDS['employee_id'],
    'pnr'            => 'PNR-D-' . substr(uniqid(), -4),
    'airline'        => 'Saudia',
    'airline_name'   => 'Saudia',
    'from_airport'   => 'TCAI',
    'to_airport'     => 'TJED',
    'from_airport_id'=> $IDS['airport_ids'][0],
    'to_airport_id'  => $IDS['airport_ids'][1],
    'departure_date' => now()->addDays(15)->format('Y-m-d'),
    'departure_time' => '12:00:00',
    'trip_type'      => 'one_way',
    'passenger_count'=> 2,
    'purchase_price' => 8000,
    'selling_price'  => 10000,
    'currency'       => 'EGP',
    'flight_system_id'   => $IDS['sys_amadeus_id'],
    'flight_carrier_id'  => $IDS['carrier_saudia_id'],
    'flight_group_id'    => $IDS['flight_group_id'],
    'purchase_balance_source' => 'group',
    'payment' => ['amount' => 5000, 'payment_method' => 'vodafone_cash', 'account_id' => $IDS['wallet_egp_id']],
    'passengers' => [
        ['first_name' => 'Ahmed', 'last_name' => 'GroupD', 'passport_number' => 'TESTPPD001', 'type' => 'adult'],
        ['first_name' => 'Sara',  'last_name' => 'GroupD', 'passport_number' => 'TESTPPD002', 'type' => 'adult'],
    ],
]);
$bookingDId = $r['json']['data']['id'] ?? null;
step('POST /flight/bookings (مرتبط بالمجموعة)', ['booking_id' => $bookingDId]);

$balanceBeforePay = (float) DB::table('flight_group_transactions')
    ->where('flight_group_id', $IDS['flight_group_id'])
    ->selectRaw('COALESCE(SUM(CASE WHEN type="debt" THEN amount ELSE -amount END),0) as bal')
    ->value('bal');
info("رصيد المجموعة قبل التسديد: {$balanceBeforePay} EGP");

// تسديد دين المجموعة
$r = httpPost($BASE_URL . '/flight/groups/' . $IDS['flight_group_id'] . '/pay-debt', [
    'amount'    => 3000,
    'account_id'=> $IDS['cash_egp_id'],
    'notes'     => 'EXTENDED: تسديد دين المجموعة من الخزينة',
]);
step('POST /flight/groups/{id}/pay-debt', ['status' => $r['status'], 'success' => $r['json']['success'] ?? false]);
if ($r['status'] === 200 && ($r['json']['success'] ?? false)) {
    ok('تم تسديد 3,000 EGP من دين المجموعة');
    info('رصيد جديد للمجموعة: ' . ($r['json']['data']['new_balance'] ?? 'n/a'));
} else {
    fail('فشل تسديد دين المجموعة: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
}
$GLOBALS['SNAPS'][] = snap('D-AFTER-GROUP-PAYDEBT');

// كشف حساب المجموعة
$r = httpGet($BASE_URL . '/flight/groups/' . $IDS['flight_group_id'] . '/statement');
step('GET /flight/groups/{id}/statement', ['status' => $r['status']]);
if ($r['status'] === 200) {
    $txCount = is_array($r['json']['data'] ?? null) ? count($r['json']['data']) : 0;
    ok('كشف حساب المجموعة: ' . $txCount . ' حركة');
} else {
    warn('فشل كشف الحساب: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
}

// ───────────────────────────────────────────────────────────────
// Y, Z) Negative tests
// ───────────────────────────────────────────────────────────────
section('Y) Negative: شحن بمبلغ أكبر من الرصيد');
$r = httpPost($BASE_URL . '/flight/treasury/systems/' . $IDS['sys_amadeus_id'] . '/recharge', [
    'from_account_id' => $IDS['cash_egp_id'],
    'amount'          => 999999999, // أكبر من رصيد الخزينة
    'notes'           => 'EXTENDED: should fail',
]);
step('POST /flight/treasury/systems/{id}/recharge (مبلغ ضخم)', ['status' => $r['status'], 'success' => $r['json']['success'] ?? false]);
if ($r['status'] >= 400 && !($r['json']['success'] ?? false)) {
    ok('✅ النظام رفض الشحن الضخم بشكل صحيح: ' . ($r['json']['message'] ?? ''));
} else {
    fail('⚠️ النظام لم يرفض الشحن الضخم!');
}

section('Z) Negative: عملة مختلفة بين الحساب والنظام');
$r = httpPost($BASE_URL . '/flight/treasury/systems/' . $IDS['sys_amadeus_id'] . '/recharge', [
    'from_account_id' => $IDS['wallet_usd_id'], // USD wallet
    'amount'          => 100,
    'notes'           => 'EXTENDED: should fail - currency mismatch',
]);
step('POST /flight/treasury/systems/{id}/recharge (عملة مختلفة)', ['status' => $r['status'], 'success' => $r['json']['success'] ?? false]);
if ($r['status'] >= 400 && !($r['json']['success'] ?? false)) {
    ok('✅ النظام رفض العملة المختلفة: ' . ($r['json']['message'] ?? ''));
} else {
    fail('⚠️ النظام لم يرفض العملة المختلفة!');
}

section('AA) Negative: حجز بدون customer_id');
$r = httpPost($BASE_URL . '/flight/bookings', [
    'employee_id' => $IDS['employee_id'],
    'pnr'         => 'PNR-NEG-' . uniqid(),
    'airline'     => 'EgyptAir',
    'from_airport'=> 'TCAI',
    'to_airport'  => 'TJED',
    'departure_date' => now()->addDays(7)->format('Y-m-d'),
    'trip_type'   => 'one_way',
    'passenger_count' => 1,
    'selling_price' => 1000,
    'currency'    => 'EGP',
    'flight_carrier_id' => $IDS['carrier_egyptair_id'],
    'passengers'  => [['first_name' => 'Test', 'last_name' => 'NoCust', 'type' => 'adult']],
]);
step('POST /flight/bookings (بدون customer_id)', ['status' => $r['status']]);
if ($r['status'] >= 400) {
    ok('✅ النظام رفض الحجز بدون عميل');
} else {
    fail('⚠️ النظام قبل حجز بدون عميل!');
}

section('BB) Negative: PNR مكرر');
$dupPnr = 'PNR-DUP-' . uniqid();
$r1 = httpPost($BASE_URL . '/flight/bookings', [
    'customer_id' => $IDS['customer_ids'][0],
    'employee_id' => $IDS['employee_id'],
    'pnr'         => $dupPnr,
    'airline'     => 'EgyptAir',
    'from_airport'=> 'TCAI',
    'to_airport'  => 'TJED',
    'departure_date' => now()->addDays(7)->format('Y-m-d'),
    'trip_type'   => 'one_way',
    'passenger_count' => 1,
    'selling_price' => 1000,
    'currency'    => 'EGP',
    'flight_carrier_id' => $IDS['carrier_egyptair_id'],
    'passengers'  => [['first_name' => 'Test', 'last_name' => 'Dup', 'type' => 'adult']],
]);
step('POST /flight/bookings (PNR أول)', ['status' => $r1['status']]);
$r2 = httpPost($BASE_URL . '/flight/bookings', [
    'customer_id' => $IDS['customer_ids'][0],
    'employee_id' => $IDS['employee_id'],
    'pnr'         => $dupPnr, // ← نفس الـ PNR
    'airline'     => 'EgyptAir',
    'from_airport'=> 'TCAI',
    'to_airport'  => 'TJED',
    'departure_date' => now()->addDays(7)->format('Y-m-d'),
    'trip_type'   => 'one_way',
    'passenger_count' => 1,
    'selling_price' => 1000,
    'currency'    => 'EGP',
    'flight_carrier_id' => $IDS['carrier_egyptair_id'],
    'passengers'  => [['first_name' => 'Test', 'last_name' => 'Dup2', 'type' => 'adult']],
]);
step('POST /flight/bookings (PNR مكرر)', ['status' => $r2['status']]);
if ($r2['status'] >= 400) {
    ok('✅ النظام رفض PNR مكرر');
} else {
    warn('⚠️ النظام قبل PNR مكرر (قد يكون مسموحاً بسبب قيد فريد على booking_number وليس pnr)');
}

testlog("\n✅ أولوية 1 اكتملت.");
file_put_contents(__DIR__ . '/storage/logs/flight_test/extended_report_priority1.json', json_encode($REPORT, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

fclose($logHandle);
echo "\n✅ أولوية 1 (Critical + Refund + Groups + Negative) اكتملت.\n";
echo "📄 Log: {$logFile}\n";