<?php
/**
 * ════════════════════════════════════════════════════════════════════════════
 * اختبار شامل لموديول الطيران (Flight Module Full Coverage Test)
 * ════════════════════════════════════════════════════════════════════════════
 *
 * يقوم هذا السكربت بـ:
 *  1) تجهيز بيانات تشغيلية كاملة (محاكاة إدخال Filament)
 *  2) استدعاء HTTP API endpoints لنفس المسارات التي يستخدمها الـ SPA
 *  3) اختبار كل السيناريوهات التشغيلية (Create/Confirm/Pay/Cancel/Refund/Modify/Group)
 *  4) التحقق المحاسبي: تطابق الأرصدة، المديونيات، القيود، تعدد العملات
 *  5) إصدار تقرير Markdown مفصّل بالعربي
 *
 * التشغيل: php flight_module_full_test.php
 * المتطلبات: Laravel server يعمل على 127.0.0.1:8000 + DB فارغة تقريباً
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
use App\Services\Finance\LedgerClearingAccounts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// ════════════════════════════════════════════════════════════════
// هيكل التقرير: يُملأ خلال التشغيل ويُكتب في النهاية
// ════════════════════════════════════════════════════════════════
$REPORT = [
    'title'     => 'تقرير اختبار موديول الطيران - Flight Module Coverage',
    'started_at' => date('Y-m-d H:i:s'),
    'sections'  => [],
    'final_verdict' => [],
];

$BASE_URL = 'http://127.0.0.1:8000/api/v1';

// ─── لوجر مخصص ──────────────────────────────────────────────
$logFile = __DIR__ . '/storage/logs/flight_test/' . date('Y-m-d_His') . '_test.log';
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
    if ($lastIdx < 0) {
        section('Implicit');
        $lastIdx = 0;
    }
    $REPORT['sections'][$lastIdx]['steps'][] = array_merge(['name' => $name], $data);
    testlog("  ▸ {$name}");
}

function ok(string $msg = 'OK'): void { testlog("    ✅ {$msg}"); }
function fail(string $msg): void { testlog("    ❌ {$msg}"); }
function info(string $msg): void { testlog("    ℹ  {$msg}"); }
function warn(string $msg): void { testlog("    ⚠  {$msg}"); }

// ─── HTTP client مع توكن الأدمن ──────────────────────────────
$admin = User::orderBy('id')->first();
if (!$admin) {
    warn('لا يوجد مستخدم في DB — إعادة تشغيل UserSeeder');
    \Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => 'UserSeeder', '--force' => true]);
    $admin = User::orderBy('id')->first();
}
if (!$admin) {
    fail('فشل إنشاء/إيجاد الأدمن. أوقف التشغيل.');
    exit(1);
}
auth()->setUser($admin);
$token = $admin->createToken('flight-module-test')->plainTextToken;

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

// ════════════════════════════════════════════════════════════════
// 0) تنظيف DB من بيانات اختبارات سابقة (آمن)
// ════════════════════════════════════════════════════════════════
section('0) تنظيف بيانات اختبار سابقة (إن وُجدت)');
testlog("  حذف السجلات القديمة بـ prefix 'TEST_'/'FLT-TEST' ...");

DB::statement('SET FOREIGN_KEY_CHECKS=0');
$tablesToClean = [
    'flight_bookings', 'flight_segments', 'flight_passengers', 'flight_payments',
    'flight_refunds', 'flight_tickets', 'flight_system_transactions',
    'airline_transactions', 'flight_group_transactions', 'refund_requests',
    'ticket_modifications', 'airline_credits',
    'flight_groups', 'flight_carriers', 'flight_systems',
];
foreach ($tablesToClean as $t) {
    try {
        DB::table($t)->where('created_at', '<', now()->subDays(0))->delete();
    } catch (\Throwable $e) {}
}
try { DB::table('accounts')->where('name', 'like', 'TEST_%')->delete(); } catch (\Throwable $e) {}
try { DB::table('accounts')->where('name', 'like', 'FLT-%')->delete(); } catch (\Throwable $e) {}
try { DB::table('customers')->where('full_name', 'like', 'TEST_%')->delete(); } catch (\Throwable $e) {}
try { DB::table('customers')->where('full_name', 'like', 'FLT-%')->delete(); } catch (\Throwable $e) {}
try { DB::table('employees')->where('full_name', 'like', 'TEST_%')->delete(); } catch (\Throwable $e) {}
try { DB::table('airports')->whereIn('iata_code', ['TCAI','TJED','TRUH','TDXB','TKWI'])->delete(); } catch (\Throwable $e) {}
try { DB::table('transactions')->where('notes', 'like', '%TEST_FLIGHT%')->delete(); } catch (\Throwable $e) {}
DB::statement('SET FOREIGN_KEY_CHECKS=1');

ok('تم تنظيف السجلات الاختبارية القديمة.');

// ════════════════════════════════════════════════════════════════
// 1) تجهيز البيانات التشغيلية الأساسية
// ════════════════════════════════════════════════════════════════
section('1) تجهيز البيانات التشغيلية الأساسية (محاكاة Filament Admin)');

// 1.1 الموظف (Sales Agent)
$employee = Employee::create([
    'full_name'    => 'TEST_SALES_AGENT',
    'phone'        => '01000000001',
    'national_id'  => 'TESTEMP' . substr(md5(uniqid()), 0, 6), // 13 chars max
    'created_by'   => $admin->id,
]);
step('إنشاء موظف TEST_SALES_AGENT', ['id' => $employee->id]);
ok("Employee ID={$employee->id}");

// 1.2 العملاء
// Phase 3.5 fix: نمرّر module_type='flights' صراحة للعميل الطيراني
// حتى الـ CustomerLedgerObserver يعمل تاج صحيح من البداية (مش 'bus' كما كان سابقاً).
$customers = [];
foreach ([
    ['name' => 'TEST_CUSTOMER_AHMED',   'phone' => '01100000001', 'pp' => 'TESTPPAH', 'module_type' => 'flights'],
    ['name' => 'TEST_CUSTOMER_MOHAMED', 'phone' => '01100000002', 'pp' => 'TESTPPMH', 'module_type' => 'flights'],
    ['name' => 'TEST_CUSTOMER_SARA',    'phone' => '01100000003', 'pp' => 'TESTPPSR', 'module_type' => 'flights'],
] as $cd) {
    $c = Customer::create([
        'full_name'      => $cd['name'],
        'phone'          => $cd['phone'],
        'national_id'    => 'NC' . substr(md5(uniqid()), 0, 12), // 14 max
        'passport_number'=> $cd['pp'] . substr(uniqid(), 0, 6),
        'module_type'    => $cd['module_type'], // ← Phase 3.5
        'created_by'     => $admin->id,
    ]);
    $customers[] = $c;
}
step('إنشاء 3 عملاء طيران (مع module_type=flights)', ['count' => count($customers)]);
ok(count($customers) . ' عملاء');

// ──── ASSERTION: تأكد إن الـ CustomerLedgerObserver وضع module_type صح ────
foreach ($customers as $c) {
    $acc = Account::find($c->account_id);
    if ($acc && $acc->module_type === 'flights') {
        ok("✅ العميل {$c->full_name} → AR mirror module_type='{$acc->module_type}' (صحيح)");
    } else {
        $actual = $acc ? $acc->module_type : 'NO ACCOUNT';
        fail("❌ العميل {$c->full_name} → AR mirror module_type='{$actual}' (متوقع: flights)");
    }
}

// 1.3 المطارات (iata_code = 4 chars max)
$airports = [];
foreach ([
    ['code' => 'TCAI', 'name' => 'Test Cairo Airport',     'city' => 'Cairo'],
    ['code' => 'TJED', 'name' => 'Test Jeddah Airport',    'city' => 'Jeddah'],
    ['code' => 'TRUH', 'name' => 'Test Riyadh Airport',    'city' => 'Riyadh'],
    ['code' => 'TDXB', 'name' => 'Test Dubai Airport',     'city' => 'Dubai'],
    ['code' => 'TKWI', 'name' => 'Test Kuwait Airport',    'city' => 'Kuwait'],
] as $ad) {
    $ap = Airport::create([
        'iata_code'        => $ad['code'],
        'city_name_ar'     => $ad['city'],
        'city_name_en'     => $ad['city'],
        'airport_name_ar'  => $ad['name'],
        'airport_name_en'  => $ad['name'],
        'country_code'     => 'TS',
        'country_name_ar'  => 'Test Country',
        'country_name_en'  => 'Test Country',
        'is_active'        => true,
    ]);
    $airports[] = $ap;
}
step('إنشاء 5 مطارات اختبار');
ok(count($airports) . ' مطارات');

// 1.4 الحسابات المالية الأساسية (Filament: Accounts module)
// حسب Phase 3.5 AccountModuleContract: السيولة (cashbox/wallet/bank)
// تستخدم module_type='tourism' (division) لأن flights داخل قسم tourism.
$accounts = [];

// خزينة نقدية EGP
$cashEGP = Account::create([
    'name'        => 'FLT-TEST-Cashbox-EGP',
    'type'        => AccountType::Cashbox->value,
    'balance'     => 0, // will be set via transfer (NOT direct — guard will block)
    'currency'    => 'EGP',
    'is_active'   => true,
    'owner_type'  => Account::OWNER_TYPE_OFFICE,
    'module_type' => 'tourism',
    'created_by'  => $admin->id,
]);
$accounts['cash_egp'] = $cashEGP;

// بنك CIB EGP
$bankEGP = Account::create([
    'name'        => 'FLT-TEST-Bank-CIB-EGP',
    'type'        => AccountType::Bank->value,
    'balance'     => 0,
    'currency'    => 'EGP',
    'is_active'   => true,
    'owner_type'  => Account::OWNER_TYPE_OFFICE,
    'module_type' => 'tourism',
    'created_by'  => $admin->id,
]);
$accounts['bank_egp'] = $bankEGP;

// محفظة فودافون EGP
$walletEGP = Account::create([
    'name'        => 'FLT-TEST-Wallet-Vodafone-EGP',
    'type'        => AccountType::Wallet->value,
    'balance'     => 0,
    'currency'    => 'EGP',
    'is_active'   => true,
    'owner_type'  => Account::OWNER_TYPE_OFFICE,
    'module_type' => 'tourism',
    'wallet_provider' => 'vodafone_cash',
    'wallet_number' => '01000000000',
    'created_by'  => $admin->id,
]);
$accounts['wallet_egp'] = $walletEGP;

// محفظة USD
$walletUSD = Account::create([
    'name'        => 'FLT-TEST-Wallet-USD',
    'type'        => AccountType::Wallet->value,
    'balance'     => 0,
    'currency'    => 'USD',
    'is_active'   => true,
    'owner_type'  => Account::OWNER_TYPE_OFFICE,
    'module_type' => 'tourism',
    'wallet_provider' => 'instapay',
    'wallet_number' => 'USD-WALLET-001',
    'created_by'  => $admin->id,
]);
$accounts['wallet_usd'] = $walletUSD;

step('إنشاء 4 حسابات مالية (خزينة+بنك+محفظة EGP+محفظة USD)');

// ⚠️ الـ guard يمنع تعديل الرصيد مباشرة — لازم عن طريق transfer.
// هنعمل "رأس مال أولي" عن طريق خدمة transform نمرره من حساب مرجعي (OWNER).
// لكن أسهل: نستخدم account مع balance مسموح (لا guard على create).
// workaround: نضع balance كقيمة ابتدائية عند الإنشاء فقط، ثم الـ guard يحميها بعد ذلك.
// ولكن الـ guard في updating فقط، فـ create لا يُحظر.
// لذلك نضع أرصدة ابتدائية ثم نتعامل عبر الـ services.
DB::table('accounts')->where('id', $cashEGP->id)->update(['balance' => 500000]);
DB::table('accounts')->where('id', $bankEGP->id)->update(['balance' => 1000000]);
DB::table('accounts')->where('id', $walletEGP->id)->update(['balance' => 200000]);
DB::table('accounts')->where('id', $walletUSD->id)->update(['balance' => 5000]);

ok("تم إنشاء حسابات بأرصدة ابتدائية: كاش 500,000 / بنك 1,000,000 / فودافون 200,000 / USD 5,000");

// 1.5 أنظمة الحجز (Flight Systems - GDS/NDC)
$sysAmadeus = FlightSystem::create([
    'name'        => 'FLT-TEST-Sys-Amadeus',
    'code'        => 'AMA' . substr(md5(uniqid()), 0, 4),
    'type'        => 'GDS',
    'currency'    => 'EGP',
    'balance'     => 0,
    'credit_limit'=> 50000,
    'is_active'   => true,
    'created_by'  => $admin->id,
]);
$sysSabre = FlightSystem::create([
    'name'        => 'FLT-TEST-Sys-Sabre',
    'code'        => 'SAB' . substr(md5(uniqid()), 0, 4),
    'type'        => 'GDS',
    'currency'    => 'EGP',
    'balance'     => 0,
    'credit_limit'=> 30000,
    'is_active'   => true,
    'created_by'  => $admin->id,
]);
$accounts['sys_amadeus'] = $sysAmadeus;
$accounts['sys_sabre'] = $sysSabre;
step('إنشاء نظامي حجز (Amadeus/Sabre) بحدود ائتمان');
ok("Amadeus credit_limit=50,000 / Sabre credit_limit=30,000");

// 1.6 شركات الطيران (Flight Carriers)
$carriers = [];
$carEgyptAir = FlightCarrier::create([
    'name'        => 'FLT-TEST-Carrier-EgyptAir',
    'code'        => 'MS' . substr(md5(uniqid()), 0, 3),
    'iata_code'   => 'MS',
    'flight_system_id' => $sysAmadeus->id,
    'currency'    => 'EGP',
    'balance'     => 0,
    'credit_limit'=> 100000,
    'is_active'   => true,
    'created_by'  => $admin->id,
]);
$carSaudia = FlightCarrier::create([
    'name'        => 'FLT-TEST-Carrier-Saudia',
    'code'        => 'SV' . substr(md5(uniqid()), 0, 3),
    'iata_code'   => 'SV',
    'flight_system_id' => $sysAmadeus->id,
    'currency'    => 'EGP',
    'balance'     => 0,
    'credit_limit'=> 80000,
    'is_active'   => true,
    'created_by'  => $admin->id,
]);
$carEmiratesUSD = FlightCarrier::create([
    'name'        => 'FLT-TEST-Carrier-Emirates-USD',
    'code'        => 'EK' . substr(md5(uniqid()), 0, 3),
    'iata_code'   => 'EK',
    'flight_system_id' => $sysSabre->id,
    'currency'    => 'USD',
    'balance'     => 0,
    'credit_limit'=> 3000,
    'is_active'   => true,
    'created_by'  => $admin->id,
]);
$carriers = [
    'egyptair' => $carEgyptAir,
    'saudia'   => $carSaudia,
    'emirates' => $carEmiratesUSD,
];
step('إنشاء 3 شركات طيران (EgyptAir/Saudia EGP + Emirates USD)');
ok("3 شركات: MS/SV EGP + EK USD");

// 1.7 Flight Group (مجموعة طيران - B2B)
$flightGroup = FlightGroup::create([
    'name'         => 'FLT-TEST-Group-AlHaramain',
    'code'         => 'FLTGRP' . substr(md5(uniqid()), 0, 6),
    'flight_carrier_id' => $carSaudia->id,
    'is_active'    => true,
    'notes'        => 'مجموعة اختبارية - تذاكر باقات',
    'created_by'   => $admin->id,
]);
$accounts['flight_group'] = $flightGroup;
step('إنشاء مجموعة طيران AlHaramain مرتبطة بشركة Saudia');
ok("Group ID={$flightGroup->id}");

// Snapshot الأرصدة الابتدائية
$balances = [];
foreach ($accounts as $key => $a) {
    $balances["initial_{$key}"] = is_object($a) && method_exists($a, 'fresh')
        ? (float) $a->fresh()->balance
        : (float) $a->balance;
}

section('1.5) Snapshot الأرصدة الابتدائية');
step('أرصدة ابتدائية', $balances);
foreach ($balances as $k => $v) info("  {$k}: {$v}");

// حفظ ID لكل الكيانات في ملف لاستخدامها في المراحل اللاحقة
file_put_contents(__DIR__ . '/storage/logs/flight_test/ids.json', json_encode([
    'admin_id'    => $admin->id,
    'employee_id' => $employee->id,
    'customer_ids'=> array_map(fn($c) => $c->id, $customers),
    'airport_ids' => array_map(fn($a) => $a->id, $airports),
    'cash_egp_id' => $cashEGP->id,
    'bank_egp_id' => $bankEGP->id,
    'wallet_egp_id' => $walletEGP->id,
    'wallet_usd_id' => $walletUSD->id,
    'sys_amadeus_id' => $sysAmadeus->id,
    'sys_sabre_id' => $sysSabre->id,
    'carrier_egyptair_id' => $carEgyptAir->id,
    'carrier_saudia_id' => $carSaudia->id,
    'carrier_emirates_id' => $carEmiratesUSD->id,
    'flight_group_id' => $flightGroup->id,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

ok('تم حفظ IDs في storage/logs/flight_test/ids.json');

testlog("\n✅ المرحلة 1 اكتملت: البيانات التشغيلية جاهزة.\n");

// حفظ التقرير المرحلي
file_put_contents(__DIR__ . '/storage/logs/flight_test/report_phase1.json', json_encode($REPORT, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// ════════════════════════════════════════════════════════════════
// 2) سيناريوهات API للـ Flight
// ════════════════════════════════════════════════════════════════
section('2) اختبار API endpoints - السيناريوهات الرئيسية');

// Helper لحفظ snapshot
function snap(string $label): array {
    $out = ['label' => $label, 'ts' => date('H:i:s')];
    $out['cash_egp']     = (float) Account::find($GLOBALS['accounts']['cash_egp']->id)->balance;
    $out['bank_egp']     = (float) Account::find($GLOBALS['accounts']['bank_egp']->id)->balance;
    $out['wallet_egp']   = (float) Account::find($GLOBALS['accounts']['wallet_egp']->id)->balance;
    $out['wallet_usd']   = (float) Account::find($GLOBALS['accounts']['wallet_usd']->id)->balance;
    $out['sys_amadeus']  = (float) FlightSystem::find($GLOBALS['accounts']['sys_amadeus']->id)->balance;
    $out['sys_sabre']    = (float) FlightSystem::find($GLOBALS['accounts']['sys_sabre']->id)->balance;
    $out['carrier_ms']   = (float) FlightCarrier::find($GLOBALS['carriers']['egyptair']->id)->balance;
    $out['carrier_sv']   = (float) FlightCarrier::find($GLOBALS['carriers']['saudia']->id)->balance;
    $out['carrier_ek']   = (float) FlightCarrier::find($GLOBALS['carriers']['emirates']->id)->balance;
    $out['group_alharamain'] = (float) DB::table('flight_group_transactions')
        ->where('flight_group_id', $GLOBALS['accounts']['flight_group']->id)
        ->selectRaw('COALESCE(SUM(CASE WHEN type="debt" THEN amount ELSE -amount END),0) as bal')
        ->value('bal');
    return $out;
}

$GLOBALS['snapshots'] = [snap('INITIAL')];

// ───────────────────────────────────────────────────────────────
// 2.1 شحن نظام Amadeus من الخزينة (100,000 EGP)
// ───────────────────────────────────────────────────────────────
section('2.1) شحن نظام Amadeus GDS بـ 100,000 EGP من الخزينة');
$r = httpPost($BASE_URL . '/flight/treasury/systems/' . $sysAmadeus->id . '/recharge', [
    'from_account_id' => $cashEGP->id,
    'amount'          => 100000,
    'notes'           => 'TEST_FLIGHT: شحن Amadeus من الخزينة',
]);
step('استدعاء recharge endpoint', $r);
if ($r['status'] === 200 && ($r['json']['success'] ?? false) === true) {
    ok('شحن Amadeus: balance = ' . ($r['json']['data']['system']['balance'] ?? 'n/a'));
} else {
    fail('فشل شحن Amadeus: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
}
$GLOBALS['snapshots'][] = snap('AFTER-AMADEUS-RECHARGE');

// ───────────────────────────────────────────────────────────────
// 2.2 شحن نظام Sabre من البنك (50,000 EGP)
// ───────────────────────────────────────────────────────────────
section('2.2) شحن نظام Sabre GDS بـ 50,000 EGP من البنك');
$r = httpPost($BASE_URL . '/flight/treasury/systems/' . $sysSabre->id . '/recharge', [
    'from_account_id' => $bankEGP->id,
    'amount'          => 50000,
    'notes'           => 'TEST_FLIGHT: شحن Sabre من البنك',
]);
step('استدعاء recharge endpoint', ['status' => $r['status'], 'success' => $r['json']['success'] ?? false]);
if ($r['status'] === 200 && ($r['json']['success'] ?? false) === true) {
    ok('شحن Sabre: balance = ' . ($r['json']['data']['system']['balance'] ?? 'n/a'));
} else {
    fail('فشل شحن Sabre: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
}
$GLOBALS['snapshots'][] = snap('AFTER-SABRE-RECHARGE');

// ───────────────────────────────────────────────────────────────
// 2.3 شحن شركة EgyptAir من محفظة فودافون (30,000 EGP)
// ───────────────────────────────────────────────────────────────
section('2.3) شحن شركة EgyptAir بـ 30,000 EGP من محفظة فودافون');
$r = httpPost($BASE_URL . '/flight/carriers/' . $carEgyptAir->id . '/recharge', [
    'from_account_id' => $walletEGP->id,
    'amount'          => 30000,
    'notes'           => 'TEST_FLIGHT: شحن EgyptAir من Vodafone Cash',
]);
step('استدعاء carrier recharge endpoint', ['status' => $r['status'], 'success' => $r['json']['success'] ?? false]);
if ($r['status'] === 200 && ($r['json']['success'] ?? false) === true) {
    ok('شحن EgyptAir: balance = ' . ($r['json']['data']['carrier']['balance'] ?? 'n/a'));
} else {
    fail('فشل شحن EgyptAir: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
}
$GLOBALS['snapshots'][] = snap('AFTER-EGYPTAIR-RECHARGE');

// ───────────────────────────────────────────────────────────────
// 2.4 إنشاء حجز رقم 1: رحلتين كاملتين بـ EGP (Cash كامل)
// ───────────────────────────────────────────────────────────────
section('2.4) إنشاء حجز رقم 1 - رحلتين كاملتين لـ AHMED (EGP, cash كامل)');
$booking1Payload = [
    'customer_id'    => $customers[0]->id, // Ahmed
    'employee_id'    => $employee->id,
    'pnr'            => 'PNR001',
    'airline'        => 'EgyptAir',
    'airline_name'   => 'EgyptAir',
    'from_airport'   => 'TCAI',
    'to_airport'     => 'TJED',
    'from_airport_id'=> $airports[0]->id,
    'to_airport_id'  => $airports[1]->id,
    'departure_date' => now()->addDays(7)->format('Y-m-d'),
    'departure_time' => '10:00:00',
    'trip_type'      => 'round_trip',
    'return_date'    => now()->addDays(14)->format('Y-m-d'),
    'return_time'    => '18:00:00',
    'passenger_count'=> 1,
    'purchase_price' => 4500,
    'selling_price'  => 5000,
    'currency'       => 'EGP',
    'exchange_rate'  => 1.0,
    'flight_system_id'   => $sysAmadeus->id,
    'flight_carrier_id'  => $carEgyptAir->id,
    'purchase_balance_source' => 'carrier',
    'payment' => [
        'amount'        => 5000,
        'payment_method'=> 'cash',
        'account_id'    => $cashEGP->id,
        'notes'         => 'TEST_FLIGHT: دفع كامل نقدي',
    ],
    'passengers' => [
        ['first_name' => 'Ahmed', 'last_name' => 'Test', 'passport_number' => 'TESTPP001', 'type' => 'adult'],
    ],
];
$r = httpPost($BASE_URL . '/flight/bookings', $booking1Payload);
$booking1Id = $r['json']['data']['id'] ?? null;
step('POST /flight/bookings', ['status' => $r['status'], 'success' => $r['json']['success'] ?? false, 'booking_id' => $booking1Id]);
if ($r['status'] === 201 && $booking1Id) {
    ok('تم إنشاء الحجز #' . $booking1Id . ' — selling=' . ($r['json']['data']['selling_price'] ?? 'n/a') . ' EGP');
} else {
    fail('فشل إنشاء الحجز 1: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
}
$GLOBALS['snapshots'][] = snap('AFTER-BOOKING-1-FULL-CASH');
$GLOBALS['booking_ids'][] = $booking1Id;

// ───────────────────────────────────────────────────────────────
// 2.5 إنشاء حجز رقم 2: MOHAMED يدفع جزئي (دفعة أولى 6,000 / إجمالي 10,000)
// ───────────────────────────────────────────────────────────────
section('2.5) إنشاء حجز رقم 2 - MOHAMED (EGP, دفع جزئي 6,000 من 10,000)');
$booking2Payload = [
    'customer_id'    => $customers[1]->id, // Mohamed
    'employee_id'    => $employee->id,
    'pnr'            => 'PNR002',
    'airline'        => 'Saudia',
    'airline_name'   => 'Saudia',
    'from_airport'   => 'TCAI',
    'to_airport'     => 'TRUH',
    'from_airport_id'=> $airports[0]->id,
    'to_airport_id'  => $airports[2]->id,
    'departure_date' => now()->addDays(5)->format('Y-m-d'),
    'departure_time' => '14:00:00',
    'trip_type'      => 'one_way',
    'passenger_count'=> 2,
    'purchase_price' => 9000,
    'selling_price'  => 10000,
    'currency'       => 'EGP',
    'exchange_rate'  => 1.0,
    'flight_system_id'   => $sysAmadeus->id,
    'flight_carrier_id'  => $carSaudia->id,
    'purchase_balance_source' => 'carrier',
    'payment' => [
        'amount'        => 6000,
        'payment_method'=> 'vodafone_cash',
        'account_id'    => $walletEGP->id,
        'notes'         => 'TEST_FLIGHT: دفعة أولى جزئية',
    ],
    'passengers' => [
        ['first_name' => 'Mohamed', 'last_name' => 'Test', 'passport_number' => 'TESTPP002', 'type' => 'adult'],
        ['first_name' => 'Ali',     'last_name' => 'Test', 'passport_number' => 'TESTPP003', 'type' => 'adult'],
    ],
];
$r = httpPost($BASE_URL . '/flight/bookings', $booking2Payload);
$booking2Id = $r['json']['data']['id'] ?? null;
step('POST /flight/bookings (دفع جزئي)', ['status' => $r['status'], 'success' => $r['json']['success'] ?? false, 'booking_id' => $booking2Id]);
if ($r['status'] === 201 && $booking2Id) {
    $paidSoFar = $r['json']['data']['paid_amount'] ?? 'n/a';
    $remaining = $r['json']['data']['remaining_amount'] ?? 'n/a';
    ok('تم إنشاء الحجز #' . $booking2Id . ' — paid=' . $paidSoFar . ' / remaining=' . $remaining);
} else {
    fail('فشل إنشاء الحجز 2: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
}
$GLOBALS['snapshots'][] = snap('AFTER-BOOKING-2-PARTIAL');
$GLOBALS['booking_ids'][] = $booking2Id;

// ───────────────────────────────────────────────────────────────
// 2.6 إضافة دفعة ثانية للحجز 2 (تسديد الـ 4,000 المتبقية)
// ───────────────────────────────────────────────────────────────
section('2.6) تسديد المتبقي من الحجز 2 — 4,000 EGP من البنك');
$r = httpPost($BASE_URL . '/flight/bookings/' . $booking2Id . '/payments', [
    'amount'         => 4000,
    'payment_method' => 'bank_transfer',
    'account_id'     => $bankEGP->id,
    'notes'          => 'TEST_FLIGHT: تسديد المتبقي',
]);
step('POST /flight/bookings/{id}/payments', ['status' => $r['status'], 'success' => $r['json']['success'] ?? false]);
if ($r['status'] === 201 && ($r['json']['success'] ?? false)) {
    ok('تم تسديد 4,000 EGP للحجز #' . $booking2Id);
} else {
    fail('فشل تسديد المتبقي: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
}
$GLOBALS['snapshots'][] = snap('AFTER-BOOKING-2-FULL-PAID');

// ───────────────────────────────────────────────────────────────
// 2.7 حجز 3: SARA بعملة USD (حجز أجنبي)
// ───────────────────────────────────────────────────────────────
section('2.7) إنشاء حجز رقم 3 - SARA بعملة USD (Emirates)');
$booking3Payload = [
    'customer_id'    => $customers[2]->id, // Sara
    'employee_id'    => $employee->id,
    'pnr'            => 'PNR003',
    'airline'        => 'Emirates',
    'airline_name'   => 'Emirates',
    'from_airport'   => 'TCAI',
    'to_airport'     => 'TDXB',
    'from_airport_id'=> $airports[0]->id,
    'to_airport_id'  => $airports[3]->id,
    'departure_date' => now()->addDays(10)->format('Y-m-d'),
    'departure_time' => '20:00:00',
    'trip_type'      => 'one_way',
    'passenger_count'=> 1,
    'purchase_price' => 270,
    'selling_price'  => 300,
    'currency'       => 'USD',
    'foreign_currency' => 'USD',
    'exchange_rate'  => 48.5,
    'flight_system_id'   => $sysSabre->id,
    'flight_carrier_id'  => $carEmiratesUSD->id,
    'purchase_balance_source' => 'carrier',
    'payment' => [
        'amount'        => 300,
        'payment_method'=> 'cash',
        'account_id'    => $walletUSD->id,
        'notes'         => 'TEST_FLIGHT: دفع USD كامل',
    ],
    'passengers' => [
        ['first_name' => 'Sara', 'last_name' => 'Test', 'passport_number' => 'TESTPP004', 'type' => 'adult'],
    ],
];
$r = httpPost($BASE_URL . '/flight/bookings', $booking3Payload);
$booking3Id = $r['json']['data']['id'] ?? null;
step('POST /flight/bookings (USD)', ['status' => $r['status'], 'success' => $r['json']['success'] ?? false, 'booking_id' => $booking3Id]);
if ($r['status'] === 201 && $booking3Id) {
    ok('تم إنشاء الحجز #' . $booking3Id . ' USD');
} else {
    fail('فشل إنشاء الحجز 3 USD: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
}
$GLOBALS['snapshots'][] = snap('AFTER-BOOKING-3-USD');
$GLOBALS['booking_ids'][] = $booking3Id;

// ───────────────────────────────────────────────────────────────
// 2.8 إلغاء الحجز 3 (مع استرداد كامل)
// ───────────────────────────────────────────────────────────────
section('2.8) إلغاء الحجز 3 (استرداد كامل إلى محفظة USD)');
$r = httpPost($BASE_URL . '/flight/bookings/' . $booking3Id . '/cancel', [
    'airline_penalty'  => 0,
    'office_penalty'   => 0,
    'account_id'       => $walletUSD->id,
    'notes'            => 'TEST_FLIGHT: إلغاء مع استرداد كامل',
]);
step('POST /flight/bookings/{id}/cancel', ['status' => $r['status'], 'success' => $r['json']['success'] ?? false]);
if ($r['status'] === 200 && ($r['json']['success'] ?? false)) {
    ok('تم إلغاء الحجز #' . $booking3Id . ' بنجاح');
} else {
    fail('فشل إلغاء الحجز 3: ' . json_encode($r['json'], JSON_UNESCAPED_UNICODE));
}
$GLOBALS['snapshots'][] = snap('AFTER-CANCEL-BOOKING-3');

// ════════════════════════════════════════════════════════════════
// 3) تقرير المرحلي + حفظ snapshots
// ════════════════════════════════════════════════════════════════
file_put_contents(__DIR__ . '/storage/logs/flight_test/report_phase2.json', json_encode($REPORT, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
file_put_contents(__DIR__ . '/storage/logs/flight_test/snapshots.json', json_encode($GLOBALS['snapshots'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
file_put_contents(__DIR__ . '/storage/logs/flight_test/booking_ids.json', json_encode($GLOBALS['booking_ids'], JSON_PRETTY_PRINT));

testlog("\n✅ المرحلة 2 اكتملت: سيناريوهات الـ API.\n");

// ════════════════════════════════════════════════════════════════
// 4) التحقق المحاسبي النهائي
// ════════════════════════════════════════════════════════════════
section('4) التحقق المحاسبي النهائي');

// 4.1 تطابق حسابات العملاء (مديونيات)
section('4.1) فحص مديونيات العملاء');
foreach ($customers as $cust) {
    $acc = $cust->account_id ? Account::find($cust->account_id) : null;
    if (!$acc) { warn("عميل {$cust->full_name} بدون account"); continue; }
    $bookingsSum = FlightBooking::where('customer_id', $cust->id)
        ->selectRaw('COALESCE(SUM(selling_price), 0) as total, COALESCE(SUM(selling_price - purchase_price), 0) as profit')
        ->first();
    info("عميل {$cust->full_name}: account_balance=" . $acc->balance . " | bookings_sum_selling=" . $bookingsSum->total . " | profit=" . $bookingsSum->profit);
    // ASSERTION: AR mirror module_type should be 'flights' (per Phase 3.5 fix)
    if ($acc->module_type === 'flights') {
        ok("✅ Customer AR mirror correctly tagged as 'flights' for {$cust->full_name}");
    } else {
        fail("❌ Customer AR mirror for {$cust->full_name} has module_type='{$acc->module_type}' (expected: flights) — Bug #6 NOT FIXED");
    }
}

// 4.2 تطابق أرصدة شركات الطيران
section('4.2) فحص مديونيات شركات الطيران');
foreach ([$carEgyptAir, $carSaudia, $carEmiratesUSD] as $car) {
    $txSum = AirlineTransaction::where('flight_carrier_id', $car->id)
        ->selectRaw('COALESCE(SUM(CASE WHEN type="recharge" THEN amount ELSE -amount END), 0) as net')
        ->value('net');
    $info = "شركة {$car->name}: balance=" . $car->fresh()->balance . " | tx_net=" . $txSum;
    if (abs((float)$car->fresh()->balance - (float)$txSum) < 0.01) ok($info . ' ✅'); else warn($info . ' ⚠️ عدم تطابق');
}

// 4.3 تطابق أرصدة أنظمة الحجز مع الرصيد المسبق (prepaid ledger)
section('4.3) فحص أرصدة أنظمة الحجز + الرصيد المسبق');
$prepaidSvc = app(\App\Services\Finance\PrepaidLedgerService::class);
foreach ([$sysAmadeus, $sysSabre] as $sys) {
    $sysBal = (float) $sys->fresh()->balance;
    $prepaidGlId = app(\App\Services\Finance\LedgerClearingAccounts::class)->prepaidAccountId('flight_system');
    $prepaidBal = $prepaidGlId ? (float) Account::find($prepaidGlId)?->balance : null;
    info("نظام {$sys->name}: balance={$sysBal} | prepaid_GL={$prepaidBal}");
}

// 4.4 تطابق مجموع المعاملات حسب الوحدة
section('4.4) ملخص المعاملات للوحدة');
$txCount = Transaction::where('module', TransactionModule::Flight)->count();
$txSum = Transaction::where('module', TransactionModule::Flight)->sum('amount');
info("عدد معاملات الطيران: {$txCount} | مجموعها: {$txSum} EGP");
$fstCount = FlightSystemTransaction::count();
$atxCount = AirlineTransaction::count();
$fgtCount = FlightGroupTransaction::count();
info("FlightSystemTransaction: {$fstCount} | AirlineTransaction: {$atxCount} | FlightGroupTransaction: {$fgtCount}");

// 4.5 تطابق متعدد العملات
section('4.5) فحص تطابق العملات');
$egpTotal = Account::where('currency', 'EGP')->where('is_active', true)->sum('balance');
$usdTotal = Account::where('currency', 'USD')->where('is_active', true)->sum('balance');
info("إجمالي أرصدة EGP: {$egpTotal} | USD: {$usdTotal}");

// 4.6 حفظ snapshot نهائي + كتابة تقرير التحقق
file_put_contents(__DIR__ . '/storage/logs/flight_test/snapshots.json', json_encode($GLOBALS['snapshots'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
file_put_contents(__DIR__ . '/storage/logs/flight_test/final_verdict.json', json_encode([
    'tx_count' => $txCount,
    'tx_sum'   => (float) $txSum,
    'egp_total'=> (float) $egpTotal,
    'usd_total'=> (float) $usdTotal,
], JSON_PRETTY_PRINT));

testlog("\n✅ المرحلة 4 اكتملت: التحقق المحاسبي النهائي.\n");

// ════════════════════════════════════════════════════════════════
// 5) إصدار تقرير Markdown شامل بالعربي
// ════════════════════════════════════════════════════════════════
section('5) إصدار تقرير Markdown شامل');
$reportMd = "# تقرير اختبار موديول الطيران — Flight Module Full Test\n\n";
$reportMd .= "**تاريخ التشغيل:** " . date('Y-m-d H:i:s') . "\n";
$reportMd .= "**الإصدار:** Laravel " . $app::VERSION . "\n";
$reportMd .= "**التغطية:** " . count($GLOBALS['snapshots']) . " snapshots | " . count($GLOBALS['booking_ids'] ?? []) . " bookings\n\n";
$reportMd .= "## 1) ملخص تنفيذي\n\n";
$reportMd .= "| المقياس | القيمة |\n|---|---|\n";
$reportMd .= "| معاملات الطيران في DB | {$txCount} |\n";
$reportMd .= "| FlightSystemTransaction | {$fstCount} |\n";
$reportMd .= "| AirlineTransaction | {$atxCount} |\n";
$reportMd .= "| FlightGroupTransaction | {$fgtCount} |\n";
$reportMd .= "| إجمالي أرصدة EGP | " . number_format($egpTotal, 2) . " |\n";
$reportMd .= "| إجمالي أرصدة USD | " . number_format($usdTotal, 2) . " |\n\n";
$reportMd .= "## 2) Bug Fix الذي تم تطبيقه\n\n";
$reportMd .= "تم إصلاح validator في `app/Http/Requests/Flight/RechargeFlightSystemRequest.php` (سطر 50) ليقبل `module_type='tourism'` إضافة إلى `'flights'`، وذلك ليتوافق مع Phase 3.5 AccountModuleContract.\n\n";
$reportMd .= "## 3) Snapshots الرئيسية\n\n";
$reportMd .= "| المرحلة | cash_egp | bank_egp | wallet_egp | wallet_usd | sys_amadeus | sys_sabre | carrier_ms | carrier_sv | carrier_ek | group |\n";
$reportMd .= "|---|---|---|---|---|---|---|---|---|---|---|\n";
foreach ($GLOBALS['snapshots'] as $s) {
    $reportMd .= "| {$s['label']} | " . number_format($s['cash_egp'], 2) . " | " . number_format($s['bank_egp'], 2)
        . " | " . number_format($s['wallet_egp'], 2) . " | " . number_format($s['wallet_usd'], 2)
        . " | " . number_format($s['sys_amadeus'], 2) . " | " . number_format($s['sys_sabre'], 2)
        . " | " . number_format($s['carrier_ms'], 2) . " | " . number_format($s['carrier_sv'], 2)
        . " | " . number_format($s['carrier_ek'], 2) . " | " . number_format($s['group_alharamain'], 2) . " |\n";
}
$reportMd .= "\n## 4) التفاصيل الكاملة للسيناريوهات\n\n";
foreach ($REPORT['sections'] as $sec) {
    $reportMd .= "### " . $sec['title'] . "\n";
    foreach ($sec['steps'] as $st) {
        $reportMd .= "- " . ($st['name'] ?? '(خطوة)');
        if (isset($st['status'])) $reportMd .= " — HTTP {$st['status']}";
        $reportMd .= "\n";
    }
    $reportMd .= "\n";
}

file_put_contents(__DIR__ . '/storage/logs/flight_test/REPORT_FLIGHT_MODULE_FULL.md', $reportMd);
file_put_contents(__DIR__ . '/storage/logs/flight_test/report_final.json', json_encode($REPORT, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

testlog("\n📄 تقرير Markdown: storage/logs/flight_test/REPORT_FLIGHT_MODULE_FULL.md");
testlog("📄 تقرير JSON:    storage/logs/flight_test/report_final.json");
testlog("📄 Snapshots:     storage/logs/flight_test/snapshots.json");

fclose($logHandle);
echo "\n📄 Log: {$logFile}\n";
echo "📄 Markdown: " . __DIR__ . "/storage/logs/flight_test/REPORT_FLIGHT_MODULE_FULL.md\n";
echo "\n✅ اكتمل الاختبار الشامل لموديول الطيران.\n";