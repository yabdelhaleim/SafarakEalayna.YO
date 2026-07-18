<?php
/**
 * ════════════════════════════════════════════════════════════════════════════
 * Flight Module — Insufficient Balance Tests
 * ════════════════════════════════════════════════════════════════════════════
 *
 * يتحقق من سلوك النظام عند نقص الرصيد في كل سيناريو:
 * 1) Carrier balance insufficient
 * 2) Customer wallet insufficient
 * 3) System balance insufficient
 * 4) Group balance insufficient
 * 5) Mixed currency issues
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\FlightGroup;
use App\Enums\AccountType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$BASE_URL = 'http://127.0.0.1:8000/api/v1';
$admin = User::first();
auth()->setUser($admin);
$token = $admin->createToken('insufficient-balance-test')->plainTextToken;

function httpGet(string $url) { global $token; $r = Http::withToken($token)->acceptJson()->get($url); return ['status' => $r->status(), 'json' => $r->json()]; }
function httpPost(string $url, array $data = []) { global $token; $r = Http::withToken($token)->acceptJson()->post($url, $data); return ['status' => $r->status(), 'json' => $r->json()]; }

function safeCleanupBooking($id) {
    if (!$id) return;
    \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS = 0');
    try {
        \Illuminate\Support\Facades\DB::table('flight_payments')->where('flight_booking_id', $id)->delete();
        \Illuminate\Support\Facades\DB::table('passengers')->where('flight_booking_id', $id)->delete();
        \Illuminate\Support\Facades\DB::table('flight_segments')->where('flight_booking_id', $id)->delete();
        \Illuminate\Support\Facades\DB::table('airline_transactions')->where('flight_booking_id', $id)->delete();
        \Illuminate\Support\Facades\DB::table('flight_system_transactions')->where('flight_booking_id', $id)->delete();
        \Illuminate\Support\Facades\DB::table('flight_bookings')->where('id', $id)->delete();
        \Illuminate\Support\Facades\DB::table('transactions')->where('related_type', 'App\\Models\\Flight\\FlightBooking')->where('related_id', $id)->delete();
    } catch (\Throwable $e) {}
    \Illuminate\Support\Facades\DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

$logHandle = fopen(__DIR__ . '/storage/logs/flight_test/' . date('Y-m-d_His') . '_insufficient.log', 'w');
function t(string $m) {
    global $logHandle;
    $l = '[' . date('H:i:s') . '] ' . $m . "\n";
    fwrite($logHandle, $l); fflush($logHandle); echo $l;
}
function ok(string $m='OK') { t("    ✅ {$m}"); }
function fail(string $m) { t("    ❌ {$m}"); }
function info(string $m) { t("    ℹ  {$m}"); }
function warn(string $m) { t("    ⚠  {$m}"); }
function section(string $title) {
    t("\n" . str_repeat('═', 70));
    t('  ' . $title);
    t(str_repeat('═', 70));
}

$RESULTS = [
    'correctly_rejected' => 0,
    'incorrectly_accepted' => 0,
    'errors' => 0,
];

t("╔══════════════════════════════════════════════════════════════════╗");
t("║  Flight Module — Insufficient Balance Tests                ║");
t("╚══════════════════════════════════════════════════════════════════╝");

// ═══════════════════════════════════════════════════════════════════════════
// الإعداد: إنشاء entities جديدة مع أرصدة صغيرة
// ═══════════════════════════════════════════════════════════════════════════
section('الإعداد: إنشاء entities جديدة');

// عميل
$customer = \App\Models\Customer::create([
    'full_name'      => 'TEST_INSUFFICIENT_CUST',
    'phone'          => '01200000001',
    'national_id'    => 'NC' . substr(md5(uniqid()), 0, 12),
    'passport_number'=> 'TESTPPI' . substr(uniqid(), -4),
    'module_type'    => 'flights',
    'created_by'     => $admin->id,
]);
info("عميل جديد ID={$customer->id}");

// موظف
$employee = \App\Models\Employee::create([
    'full_name'    => 'TEST_INSUFFICIENT_EMP',
    'phone'        => '01200000002',
    'national_id'  => 'TE' . substr(md5(uniqid()), 0, 6),
    'created_by'   => $admin->id,
]);
info("موظف جديد ID={$employee->id}");

// حساب خزينة بكميية كبيرة
$cashAccount = \App\Models\Account::create([
    'name'        => 'TEST_INSUFFICIENT_Cash',
    'type'        => AccountType::Cashbox->value,
    'currency'    => 'EGP',
    'balance'     => 0,  // صفر! لاختبار النقص
    'is_active'   => true,
    'owner_type'  => \App\Models\Account::OWNER_TYPE_OFFICE,
    'module_type' => 'tourism',
    'created_by'  => $admin->id,
]);
DB::table('accounts')->where('id', $cashAccount->id)->update(['balance' => 10000]);  // رصيد قليل
info("خزينة EGP ID={$cashAccount->id} balance=10,000 EGP");

// حساب wallet USD
$walletUSD = \App\Models\Account::create([
    'name'        => 'TEST_INSUFFICIENT_WalletUSD',
    'type'        => AccountType::Wallet->value,
    'currency'    => 'USD',
    'balance'     => 0,
    'is_active'   => true,
    'owner_type'  => \App\Models\Account::OWNER_TYPE_OFFICE,
    'module_type' => 'tourism',
    'wallet_provider' => 'instapay',
    'created_by'  => $admin->id,
]);
DB::table('accounts')->where('id', $walletUSD->id)->update(['balance' => 50]);  // 50 USD فقط
info("محفظة USD ID={$walletUSD->id} balance=50 USD");

// نظام Amadeus مع رصيد قليل
$system = FlightSystem::create([
    'name'        => 'TEST_INSUFFICIENT_System',
    'code'        => 'INS' . substr(md5(uniqid()), 0, 4),
    'type'        => 'GDS',
    'currency'    => 'EGP',
    'balance'     => 1000,  // رصيد قليل
    'credit_limit'=> 0,
    'is_active'   => true,
    'created_by'  => $admin->id,
]);
info("نظام حجز ID={$system->id} balance=1,000 EGP");

// شركة EgyptAir مع رصيد قليل
$carrier = FlightCarrier::create([
    'name'            => 'TEST_INSUFFICIENT_Carrier',
    'code'            => 'TIN' . substr(md5(uniqid()), 0, 3),
    'iata_code'       => 'TI',
    'flight_system_id'=> $system->id,
    'currency'        => 'EGP',
    'balance'         => 500,  // رصيد قليل
    'credit_limit'    => 0,
    'is_active'       => true,
    'created_by'      => $admin->id,
]);
info("شركة طيران ID={$carrier->id} balance=500 EGP");

// مطار
$airport = \App\Models\Airport::create([
    'iata_code'        => 'TI' . substr(uniqid(), -2),
    'city_name_ar'     => 'Test Insufficient',
    'city_name_en'     => 'Test Insufficient',
    'airport_name_ar'  => 'Test Insufficient',
    'airport_name_en'  => 'Test Insufficient',
    'country_code'     => 'TS',
    'country_name_ar'  => 'Test',
    'country_name_en'  => 'Test',
    'is_active'        => true,
]);
$airportFrom = $airport->iata_code;

// مجموعة
$group = FlightGroup::create([
    'name'         => 'TEST_INSUFFICIENT_Group',
    'code'         => 'TIG' . substr(md5(uniqid()), 0, 5),
    'flight_carrier_id' => $carrier->id,
    'is_active'    => true,
    'created_by'   => $admin->id,
]);
info("مجموعة ID={$group->id}");

// ═══════════════════════════════════════════════════════════════════════════
// 1) TEST: Carrier balance insufficient
// ═══════════════════════════════════════════════════════════════════════════
section('TEST 1: Carrier balance insufficient');

// محاولة حجز بـ purchase_price=2000 (carrier عنده 500 فقط)
$r = httpPost($BASE_URL . '/flight/bookings', [
    'customer_id'    => $customer->id,
    'employee_id'    => $employee->id,
    'pnr'            => 'PNR-INS1-' . substr(uniqid(), -4),
    'airline'        => 'TestInsufficient',
    'airline_name'   => 'TestInsufficient',
    'from_airport'   => $airportFrom,
    'to_airport'     => $airportFrom,
    'from_airport_id'=> $airport->id,
    'to_airport_id'  => $airport->id,
    'departure_date' => now()->addDays(30)->format('Y-m-d'),
    'departure_time' => '10:00:00',
    'trip_type'      => 'one_way',
    'passenger_count'=> 1,
    'purchase_price' => 2000,  // أكبر من رصيد carrier (500)
    'selling_price'  => 2500,
    'currency'       => 'EGP',
    'flight_system_id'   => $system->id,
    'flight_carrier_id'  => $carrier->id,
    'purchase_balance_source' => 'carrier',
    'payment' => ['amount' => 2500, 'payment_method' => 'cash', 'account_id' => $cashAccount->id],
    'passengers' => [['first_name' => 'T', 'last_name' => 'Test', 'passport_number' => 'TI001', 'type' => 'adult']],
]);
t('  ▸ POST /flight/bookings (carrier balance=500, purchase=2000)');
if ($r['status'] >= 400) {
    $msg = $r['json']['message'] ?? '';
    if (str_contains($msg, 'رصيد') || str_contains($msg, 'كافٍ') || str_contains($msg, 'insufficient') || str_contains($msg, 'prepaid')) {
        $RESULTS['correctly_rejected']++;
        ok("✅ النظام رفض صح: {$msg}");
    } else {
        $RESULTS['errors']++;
        warn("⚠️ رفض لكن برسالة غير واضحة: {$msg}");
    }
} else {
    $RESULTS['incorrectly_accepted']++;
    fail("❌ الباج: النظام قبل حجز بـ carrier balance=500 و purchase=2000!");
    // تنظيف الحجز الخاطئ
    if (isset($r['json']['data']['id'])) {
        safeCleanupBooking($r['json']['data']['id'] ?? null);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// 2) TEST: Customer wallet insufficient (EGP)
// ═══════════════════════════════════════════════════════════════════════════
section('TEST 2: Customer wallet insufficient (EGP)');

// الخزينة عندها 10,000 EGP - كافية للحجز
// لكن الـ purchase price على carrier (500) كافي
// Selling price = 15000 (أعلى من الخزينة 10000)
$r = httpPost($BASE_URL . '/flight/bookings', [
    'customer_id'    => $customer->id,
    'employee_id'    => $employee->id,
    'pnr'            => 'PNR-INS2-' . substr(uniqid(), -4),
    'airline'        => 'TestInsufficient',
    'airline_name'   => 'TestInsufficient',
    'from_airport'   => $airportFrom,
    'to_airport'     => $airportFrom,
    'from_airport_id'=> $airport->id,
    'to_airport_id'  => $airport->id,
    'departure_date' => now()->addDays(31)->format('Y-m-d'),
    'departure_time' => '10:00:00',
    'trip_type'      => 'one_way',
    'passenger_count'=> 1,
    'purchase_price' => 400,  // أقل من carrier (500)
    'selling_price'  => 20000, // أكبر من الخزينة (10000) - لكن الـ payment=15000
    'currency'       => 'EGP',
    'flight_system_id'   => $system->id,
    'flight_carrier_id'  => $carrier->id,
    'purchase_balance_source' => 'carrier',
    'payment' => ['amount' => 15000, 'payment_method' => 'cash', 'account_id' => $cashAccount->id],
    'passengers' => [['first_name' => 'T', 'last_name' => 'Test', 'passport_number' => 'TI002', 'type' => 'adult']],
]);
t('  ▸ POST /flight/bookings (cash=10000, payment=15000)');
if ($r['status'] >= 400) {
    $msg = $r['json']['message'] ?? '';
    if (str_contains($msg, 'رصيد') || str_contains($msg, 'كافٍ') || str_contains($msg, 'cashbox') || str_contains($msg, 'CASHBOX') || str_contains($msg, 'insufficient')) {
        $RESULTS['correctly_rejected']++;
        ok("✅ النظام رفض صح: {$msg}");
    } else {
        $RESULTS['errors']++;
        warn("⚠️ رفض برسالة: {$msg}");
    }
} else {
    $RESULTS['incorrectly_accepted']++;
    fail("❌ الباج: النظام قبل حجز والـ cashbox رصيدها أقل من payment!");
    if (isset($r['json']['data']['id'])) {
        safeCleanupBooking($r['json']['data']['id'] ?? null);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// 3) TEST: System balance insufficient (purchase_balance_source=system)
// ═══════════════════════════════════════════════════════════════════════════
section('TEST 3: System balance insufficient (purchase_balance_source=system)');

// شحن carrier لاختبار purchase_balance_source=system
$carrier->update(['balance' => 100000, 'credit_limit' => 0]);

// System balance=1000, نحاول حجز بـ purchase=5000
$r = httpPost($BASE_URL . '/flight/bookings', [
    'customer_id'    => $customer->id,
    'employee_id'    => $employee->id,
    'pnr'            => 'PNR-INS3-' . substr(uniqid(), -4),
    'airline'        => 'TestInsufficient',
    'airline_name'   => 'TestInsufficient',
    'from_airport'   => $airportFrom,
    'to_airport'     => $airportFrom,
    'from_airport_id'=> $airport->id,
    'to_airport_id'  => $airport->id,
    'departure_date' => now()->addDays(32)->format('Y-m-d'),
    'departure_time' => '10:00:00',
    'trip_type'      => 'one_way',
    'passenger_count'=> 1,
    'purchase_price' => 5000,  // أكبر من system balance (1000)
    'selling_price'  => 5500,
    'currency'       => 'EGP',
    'flight_system_id'   => $system->id,
    'flight_carrier_id'  => $carrier->id,
    'purchase_balance_source' => 'system',  // ← من النظام
    'payment' => ['amount' => 5500, 'payment_method' => 'cash', 'account_id' => $cashAccount->id],
    'passengers' => [['first_name' => 'T', 'last_name' => 'Test', 'passport_number' => 'TI003', 'type' => 'adult']],
]);
t('  ▸ POST /flight/bookings (system=1000, purchase=5000, source=system)');
if ($r['status'] >= 400) {
    $msg = $r['json']['message'] ?? '';
    if (str_contains($msg, 'رصيد') || str_contains($msg, 'كافٍ') || str_contains($msg, 'prepaid') || str_contains($msg, 'system')) {
        $RESULTS['correctly_rejected']++;
        ok("✅ النظام رفض صح: {$msg}");
    } else {
        $RESULTS['errors']++;
        warn("⚠️ رفض برسالة: {$msg}");
    }
} else {
    $RESULTS['incorrectly_accepted']++;
    fail("❌ الباج: النظام قبل حجز والـ system balance غير كافٍ!");
    if (isset($r['json']['data']['id'])) {
        safeCleanupBooking($r['json']['data']['id'] ?? null);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// 4) TEST: Mixed currency (USD booking with EGP wallet)
// ═══════════════════════════════════════════════════════════════════════════
section('TEST 4: Currency mismatch (USD booking, EGP wallet)');

$carrier->update(['balance' => 100000, 'credit_limit' => 0]);
$r = httpPost($BASE_URL . '/flight/bookings', [
    'customer_id'    => $customer->id,
    'employee_id'    => $employee->id,
    'pnr'            => 'PNR-INS4-' . substr(uniqid(), -4),
    'airline'        => 'TestInsufficient',
    'airline_name'   => 'TestInsufficient',
    'from_airport'   => $airportFrom,
    'to_airport'     => $airportFrom,
    'from_airport_id'=> $airport->id,
    'to_airport_id'  => $airport->id,
    'departure_date' => now()->addDays(33)->format('Y-m-d'),
    'departure_time' => '10:00:00',
    'trip_type'      => 'one_way',
    'passenger_count'=> 1,
    'purchase_price' => 100,  // 100 USD
    'selling_price'  => 200,  // 200 USD
    'currency'       => 'USD',
    'flight_system_id'   => $system->id,
    'flight_carrier_id'  => $carrier->id,
    'purchase_balance_source' => 'carrier',
    'payment' => ['amount' => 200, 'payment_method' => 'cash', 'account_id' => $cashAccount->id], // EGP wallet!
    'passengers' => [['first_name' => 'T', 'last_name' => 'Test', 'passport_number' => 'TI004', 'type' => 'adult']],
]);
t('  ▸ POST /flight/bookings (booking=USD, payment=EGP)');
if ($r['status'] >= 400) {
    $msg = $r['json']['message'] ?? '';
    $RESULTS['correctly_rejected']++;
    ok("✅ النظام رفض: {$msg}");
} else {
    $RESULTS['incorrectly_accepted']++;
    fail("❌ الباج: النظام قبل booking USD لكن payment بـ EGP wallet!");
    if (isset($r['json']['data']['id'])) {
        safeCleanupBooking($r['json']['data']['id'] ?? null);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// 5) TEST: Group balance insufficient
// ═══════════════════════════════════════════════════════════════════════════
section('TEST 5: Group balance insufficient');

// حجز بـ purchase=200 مع purchase_balance_source=group
// Group ليس لديه رصيد
$r = httpPost($BASE_URL . '/flight/bookings', [
    'customer_id'    => $customer->id,
    'employee_id'    => $employee->id,
    'pnr'            => 'PNR-INS5-' . substr(uniqid(), -4),
    'airline'        => 'TestInsufficient',
    'airline_name'   => 'TestInsufficient',
    'from_airport'   => $airportFrom,
    'to_airport'     => $airportFrom,
    'from_airport_id'=> $airport->id,
    'to_airport_id'  => $airport->id,
    'departure_date' => now()->addDays(34)->format('Y-m-d'),
    'departure_time' => '10:00:00',
    'trip_type'      => 'one_way',
    'passenger_count'=> 1,
    'purchase_price' => 200,  // group balance=0
    'selling_price'  => 300,
    'currency'       => 'EGP',
    'flight_system_id'   => $system->id,
    'flight_carrier_id'  => $carrier->id,
    'flight_group_id'    => $group->id,
    'purchase_balance_source' => 'group',  // ← من المجموعة
    'payment' => ['amount' => 300, 'payment_method' => 'cash', 'account_id' => $cashAccount->id],
    'passengers' => [['first_name' => 'T', 'last_name' => 'Test', 'passport_number' => 'TI005', 'type' => 'adult']],
]);
t('  ▸ POST /flight/bookings (group=0, purchase=200)');
if ($r['status'] >= 400) {
    $msg = $r['json']['message'] ?? '';
    if (str_contains($msg, 'رصيد') || str_contains($msg, 'كافٍ') || str_contains($msg, 'prepaid') || str_contains($msg, 'group')) {
        $RESULTS['correctly_rejected']++;
        ok("✅ النظام رفض صح: {$msg}");
    } else {
        $RESULTS['errors']++;
        warn("⚠️ رفض برسالة: {$msg}");
    }
} else {
    $RESULTS['incorrectly_accepted']++;
    fail("❌ الباج: النظام قبل حجز والـ group balance=0!");
    if (isset($r['json']['data']['id'])) {
        safeCleanupBooking($r['json']['data']['id'] ?? null);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// 6) TEST: Payment > wallet balance
// ═══════════════════════════════════════════════════════════════════════════
section('TEST 6: Payment > Wallet USD balance');

// wallet USD = 50 فقط
// نحاول payment = 100 USD
$r = httpPost($BASE_URL . '/flight/bookings', [
    'customer_id'    => $customer->id,
    'employee_id'    => $employee->id,
    'pnr'            => 'PNR-INS6-' . substr(uniqid(), -4),
    'airline'        => 'TestInsufficient',
    'airline_name'   => 'TestInsufficient',
    'from_airport'   => $airportFrom,
    'to_airport'     => $airportFrom,
    'from_airport_id'=> $airport->id,
    'to_airport_id'  => $airport->id,
    'departure_date' => now()->addDays(35)->format('Y-m-d'),
    'departure_time' => '10:00:00',
    'trip_type'      => 'one_way',
    'passenger_count'=> 1,
    'purchase_price' => 30,
    'selling_price'  => 50,
    'currency'       => 'USD',
    'flight_system_id'   => $system->id,
    'flight_carrier_id'  => $carrier->id,
    'purchase_balance_source' => 'carrier',
    'payment' => ['amount' => 100, 'payment_method' => 'cash', 'account_id' => $walletUSD->id], // 100 USD > 50 USD
    'passengers' => [['first_name' => 'T', 'last_name' => 'Test', 'passport_number' => 'TI006', 'type' => 'adult']],
]);
t('  ▸ POST /flight/bookings (wallet=50 USD, payment=100 USD)');
if ($r['status'] >= 400) {
    $msg = $r['json']['message'] ?? '';
    $RESULTS['correctly_rejected']++;
    ok("✅ النظام رفض: {$msg}");
} else {
    $RESULTS['incorrectly_accepted']++;
    fail("❌ الباج: النظام قبل payment أكبر من wallet!");
    if (isset($r['json']['data']['id'])) {
        safeCleanupBooking($r['json']['data']['id'] ?? null);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// 7) TEST: Edge case - balance exactly 0
// ═══════════════════════════════════════════════════════════════════════════
section('TEST 7: Edge case - Balance exactly 0');

// ضبط رصيد carrier على 0 تماماً
$carrier->update(['balance' => 0, 'credit_limit' => 0]);
$r = httpPost($BASE_URL . '/flight/bookings', [
    'customer_id'    => $customer->id,
    'employee_id'    => $employee->id,
    'pnr'            => 'PNR-INS7-' . substr(uniqid(), -4),
    'airline'        => 'TestInsufficient',
    'airline_name'   => 'TestInsufficient',
    'from_airport'   => $airportFrom,
    'to_airport'     => $airportFrom,
    'from_airport_id'=> $airport->id,
    'to_airport_id'  => $airport->id,
    'departure_date' => now()->addDays(36)->format('Y-m-d'),
    'departure_time' => '10:00:00',
    'trip_type'      => 'one_way',
    'passenger_count'=> 1,
    'purchase_price' => 1,  // أي مبلغ > 0
    'selling_price'  => 2,
    'currency'       => 'EGP',
    'flight_system_id'   => $system->id,
    'flight_carrier_id'  => $carrier->id,
    'purchase_balance_source' => 'carrier',
    'payment' => ['amount' => 2, 'payment_method' => 'cash', 'account_id' => $cashAccount->id],
    'passengers' => [['first_name' => 'T', 'last_name' => 'Test', 'passport_number' => 'TI007', 'type' => 'adult']],
]);
t('  ▸ POST /flight/bookings (carrier=0, purchase=1)');
if ($r['status'] >= 400) {
    $msg = $r['json']['message'] ?? '';
    $RESULTS['correctly_rejected']++;
    ok("✅ النظام رفض balance=0: {$msg}");
} else {
    $RESULTS['incorrectly_accepted']++;
    fail("❌ الباج: النظام قبل حجز والـ carrier balance=0 تماماً!");
    if (isset($r['json']['data']['id'])) {
        safeCleanupBooking($r['json']['data']['id'] ?? null);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// ملخص
// ═══════════════════════════════════════════════════════════════════════════
section('الملخص النهائي');
t("  ✅ Cases correctly rejected:  {$RESULTS['correctly_rejected']}");
t("  ❌ Cases incorrectly accepted: {$RESULTS['incorrectly_accepted']}");
t("  ⚠️  Cases rejected with unclear message: {$RESULTS['errors']}");

if ($RESULTS['incorrectly_accepted'] == 0) {
    ok("\n🎉 النظام آمن: كل حالات نقص الرصيد رُفضت بشكل صحيح");
} else {
    fail("\n❌ الباج: {$RESULTS['incorrectly_accepted']} حالة نقص رصيد قُبلت!");
}

// حفظ التقرير
file_put_contents(__DIR__ . '/storage/logs/flight_test/insufficient_balance_report_' . date('Y-m-d_His') . '.json',
    json_encode($RESULTS, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

fclose($logHandle);
echo "\n📄 Log: " . __DIR__ . "/storage/logs/flight_test/" . date('Y-m-d_His') . "_insufficient.log\n";
echo "✅ Insufficient Balance Tests DONE.\n";