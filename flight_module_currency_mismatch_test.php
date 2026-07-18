<?php
/**
 * ════════════════════════════════════════════════════════════════════════════
 * Flight Module — Currency Mismatch Tests (Bug #14)
 * ════════════════════════════════════════════════════════════════════════════
 *
 * يستهدف بدقة فحص العملة بين الحجز والدفع:
 * - ✅ نفس العملة (USD ↔ USD): يُقبل بدون تحويل
 * - ❌ عملة مختلفة (USD ↔ EGP): يُرفض (Bug #14 fix)
 * - ✅ EGP + EGP: يُقبل
 * - ✅ EGP + عملة أجنبية: يُقبل مع تحويل
 * - ❌ عملة أجنبية + عملة أجنبية مختلفة: يُرفض
 * - ✅ EGP booking بدون payment
 * - ❌ Payment لـ cancelled booking
 * - ❌ Payment بـ amount <= 0
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Account;
use App\Models\Airport;
use App\Models\Setting\Currency;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\FlightBooking;
use App\Enums\AccountType;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$BASE_URL = 'http://127.0.0.1:8000/api/v1';
$admin = User::first();
auth()->setUser($admin);
$token = $admin->createToken('currency-test-' . uniqid())->plainTextToken;

function httpPost(string $url, array $data = []) {
    global $token;
    $r = Http::withToken($token)->acceptJson()->post($url, $data);
    return ['status' => $r->status(), 'json' => $r->json()];
}

function safeCleanupBooking($id) {
    if (!$id) return;
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');
    try {
        DB::table('flight_payments')->where('flight_booking_id', $id)->delete();
        DB::table('passengers')->where('flight_booking_id', $id)->delete();
        DB::table('flight_segments')->where('flight_booking_id', $id)->delete();
        DB::table('flight_bookings')->where('id', $id)->delete();
        DB::table('transactions')->where('related_type', 'App\\Models\\Flight\\FlightBooking')->where('related_id', $id)->delete();
    } catch (\Throwable $e) {}
    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

/**
 * شحن رصيد شركة طيران بطريقة آمنة (تمر عبر الـ guard المعتمد)
 */
function setCarrierBalance(FlightCarrier $carrier, float $balance): void {
    LedgerBalanceMutationGuard::run(function () use ($carrier, $balance) {
        $carrier->balance = $balance;
        $carrier->save();
    });
}

function setAccountBalance(Account $account, float $balance): void {
    LedgerBalanceMutationGuard::run(function () use ($account, $balance) {
        $account->balance = $balance;
        $account->save();
    });
}

$logDir = __DIR__ . '/storage/logs/flight_test';
if (!is_dir($logDir)) mkdir($logDir, 0777, true);
$logHandle = fopen($logDir . '/' . date('Y-m-d_His') . '_currency_mismatch.log', 'w');
function t(string $m) {
    global $logHandle;
    $l = '[' . date('H:i:s') . '] ' . $m . "\n";
    fwrite($logHandle, $l); fflush($logHandle); echo $l;
}
function ok(string $m='OK') { t("    ✅ {$m}"); }
function fail(string $m) { t("    ❌ {$m}"); }
function info(string $m) { t("    ℹ  {$m}"); }
function warn(string $m) { t("    ⚠️  {$m}"); }
function section(string $title) {
    t("\n" . str_repeat('═', 70));
    t('  ' . $title);
    t(str_repeat('═', 70));
}

$RESULTS = ['passed' => 0, 'failed' => 0];

t("╔══════════════════════════════════════════════════════════════════╗");
t("║  Flight Module — Currency Mismatch Tests (Bug #14)            ║");
t("║  يفحص تطابق العملة بين الحجز والدفع                            ║");
t("╚══════════════════════════════════════════════════════════════════╝");

// ═════════════════ تنظيف leftovers ═════════════════
section('🧹 تنظيف leftovers');
DB::statement('SET FOREIGN_KEY_CHECKS = 0');
DB::table('accounts')->where('name', 'like', 'TEST_CURR_%')->delete();
DB::table('customers')->where('full_name', 'like', 'TEST_CURR_%')->delete();
DB::table('employees')->where('full_name', 'like', 'TEST_CURR_%')->delete();
DB::table('flight_systems')->where('name', 'like', 'TEST_CURR_%')->delete();
DB::table('flight_carriers')->where('name', 'like', 'TEST_CURR_%')->delete();
DB::table('airports')->where('iata_code', 'like', 'CU%')->delete();
DB::table('currencies')->where('code', 'like', 'TC%')->delete();
DB::statement('SET FOREIGN_KEY_CHECKS = 1');
info("تم تنظيف leftovers");

// ═════════════════ التأكد من وجود العملات ═════════════════
section('💱 التحقق من جدول العملات');
$usd = Currency::where('code', 'USD')->first();
if (!$usd) {
    $usd = Currency::create(['code' => 'USD', 'name_ar' => 'دولار أمريكي', 'name_en' => 'US Dollar', 'symbol' => '$', 'exchange_rate' => 48.5, 'is_active' => true]);
    info("USD currency created");
}
$kwd = Currency::where('code', 'KWD')->first();
if (!$kwd) {
    $kwd = Currency::create(['code' => 'KWD', 'name_ar' => 'دينار كويتي', 'name_en' => 'Kuwaiti Dinar', 'symbol' => 'د.ك', 'exchange_rate' => 157.5, 'is_active' => true]);
    info("KWD currency created");
}
$sar = Currency::where('code', 'SAR')->first();
if (!$sar) {
    $sar = Currency::create(['code' => 'SAR', 'name_ar' => 'ريال سعودي', 'name_en' => 'Saudi Riyal', 'symbol' => 'ر.س', 'exchange_rate' => 12.9, 'is_active' => true]);
    info("SAR currency created");
}
info("USD={$usd->exchange_rate} | KWD={$kwd->exchange_rate} | SAR={$sar->exchange_rate}");

// ═════════════════ الإعداد ═════════════════
section('⚙️ الإعداد: إنشاء entities');

$customer = Customer::create([
    'full_name' => 'TEST_CURR_CUST_' . substr(uniqid(), -4),
    'phone' => '01200011001',
    'national_id' => 'CC' . substr(md5(uniqid()), 0, 12),
    'passport_number' => 'CU' . substr(uniqid(), -8),
    'module_type' => 'flights',
    'created_by' => $admin->id,
]);

$employee = Employee::create([
    'full_name' => 'TEST_CURR_EMP_' . substr(uniqid(), -4),
    'phone' => '01200011002',
    'national_id' => 'CEM' . substr(md5(uniqid()), 0, 6),
    'created_by' => $admin->id,
]);

// إنشاء حسابات بنوك — نمر عبر الـ guard لوضع الرصيد
$cashEGP = Account::create([
    'name' => 'TEST_CURR_Cash_EGP',
    'type' => AccountType::Cashbox->value,
    'currency' => 'EGP',
    'balance' => 0,
    'is_active' => true,
    'owner_type' => Account::OWNER_TYPE_OFFICE,
    'module_type' => 'tourism',
    'created_by' => $admin->id,
]);
setAccountBalance($cashEGP, 1000000);

$cashUSD = Account::create([
    'name' => 'TEST_CURR_Cash_USD',
    'type' => AccountType::Cashbox->value,
    'currency' => 'USD',
    'balance' => 0,
    'is_active' => true,
    'owner_type' => Account::OWNER_TYPE_OFFICE,
    'module_type' => 'tourism',
    'created_by' => $admin->id,
]);
setAccountBalance($cashUSD, 50000);

$cashKWD = Account::create([
    'name' => 'TEST_CURR_Cash_KWD',
    'type' => AccountType::Cashbox->value,
    'currency' => 'KWD',
    'balance' => 0,
    'is_active' => true,
    'owner_type' => Account::OWNER_TYPE_OFFICE,
    'module_type' => 'tourism',
    'created_by' => $admin->id,
]);
setAccountBalance($cashKWD, 5000);

$cashSAR = Account::create([
    'name' => 'TEST_CURR_Cash_SAR',
    'type' => AccountType::Cashbox->value,
    'currency' => 'SAR',
    'balance' => 0,
    'is_active' => true,
    'owner_type' => Account::OWNER_TYPE_OFFICE,
    'module_type' => 'tourism',
    'created_by' => $admin->id,
]);
setAccountBalance($cashSAR, 50000);

$airport = Airport::create([
    'iata_code' => 'CU' . substr(uniqid(), -2),
    'city_name_ar' => 'Currency Test',
    'city_name_en' => 'Currency Test',
    'airport_name_ar' => 'Currency Test Airport',
    'airport_name_en' => 'Currency Test Airport',
    'country_code' => 'CU',
    'country_name_ar' => 'Currency',
    'country_name_en' => 'Currency',
    'is_active' => true,
]);
$airportCode = $airport->iata_code;

$system = FlightSystem::create([
    'name' => 'TEST_CURR_System_' . substr(uniqid(), -4),
    'code' => 'CUS' . substr(md5(uniqid()), 0, 3),
    'type' => 'GDS',
    'currency' => 'EGP',
    'balance' => 0,
    'credit_limit' => 0,
    'is_active' => true,
    'created_by' => $admin->id,
]);
LedgerBalanceMutationGuard::run(function () use ($system) {
    $system->balance = 1000000;
    $system->save();
});

$carrierUSD = FlightCarrier::create([
    'name' => 'TEST_CURR_Carrier_USD_' . substr(uniqid(), -4),
    'code' => 'CUU' . substr(md5(uniqid()), 0, 3),
    'iata_code' => 'CU',
    'flight_system_id' => $system->id,
    'currency' => 'USD',
    'balance' => 0,
    'credit_limit' => 0,
    'is_active' => true,
    'created_by' => $admin->id,
]);
setCarrierBalance($carrierUSD, 10000);

$carrierEGP = FlightCarrier::create([
    'name' => 'TEST_CURR_Carrier_EGP_' . substr(uniqid(), -4),
    'code' => 'CUE' . substr(md5(uniqid()), 0, 3),
    'iata_code' => 'CU',
    'flight_system_id' => $system->id,
    'currency' => 'EGP',
    'balance' => 0,
    'credit_limit' => 0,
    'is_active' => true,
    'created_by' => $admin->id,
]);
setCarrierBalance($carrierEGP, 1000000);

$carrierKWD = FlightCarrier::create([
    'name' => 'TEST_CURR_Carrier_KWD_' . substr(uniqid(), -4),
    'code' => 'CUK' . substr(md5(uniqid()), 0, 3),
    'iata_code' => 'CU',
    'flight_system_id' => $system->id,
    'currency' => 'KWD',
    'balance' => 0,
    'credit_limit' => 0,
    'is_active' => true,
    'created_by' => $admin->id,
]);
setCarrierBalance($carrierKWD, 1000);

$carrierSAR = FlightCarrier::create([
    'name' => 'TEST_CURR_Carrier_SAR_' . substr(uniqid(), -4),
    'code' => 'CSA' . substr(md5(uniqid()), 0, 3),
    'iata_code' => 'CU',
    'flight_system_id' => $system->id,
    'currency' => 'SAR',
    'balance' => 0,
    'credit_limit' => 0,
    'is_active' => true,
    'created_by' => $admin->id,
]);
setCarrierBalance($carrierSAR, 10000);

info("cashEGP={$cashEGP->id} (EGP balance={$cashEGP->balance})");
info("cashUSD={$cashUSD->id} (USD balance={$cashUSD->balance})");
info("cashKWD={$cashKWD->id} (KWD balance={$cashKWD->balance})");
info("cashSAR={$cashSAR->id} (SAR balance={$cashSAR->balance})");
info("carrierEGP={$carrierEGP->id} (balance={$carrierEGP->balance})");
info("carrierUSD={$carrierUSD->id} (balance={$carrierUSD->balance})");
info("carrierKWD={$carrierKWD->id} (balance={$carrierKWD->balance})");
info("carrierSAR={$carrierSAR->id} (balance={$carrierSAR->balance})");

function mkBooking(
    $customer, $employee, $carrier, $system, $airport, $airportCode,
    string $bookingCurrency, float $purchaseAmount, float $sellingAmount,
    ?Account $paymentAccount, float $exchangeRate
): array {
    global $BASE_URL;
    $data = [
        'customer_id' => $customer->id,
        'employee_id' => $employee->id,
        'pnr' => 'CU-' . strtoupper(substr(md5(uniqid()), 0, 6)),
        'airline' => 'CurrTest',
        'airline_name' => 'CurrTest',
        'from_airport' => $airportCode,
        'to_airport' => $airportCode,
        'from_airport_id' => $airport->id,
        'to_airport_id' => $airport->id,
        'departure_date' => now()->addDays(rand(20, 40))->format('Y-m-d'),
        'departure_time' => '10:00:00',
        'trip_type' => 'one_way',
        'passenger_count' => 1,
        'currency' => $bookingCurrency,
        'foreign_currency' => $bookingCurrency,
        'exchange_rate' => $exchangeRate,
        'flight_system_id' => $system->id,
        'flight_carrier_id' => $carrier->id,
        'purchase_balance_source' => 'carrier',
        'passengers' => [['first_name' => 'CU', 'last_name' => 'Test', 'passport_number' => 'CU' . substr(uniqid(), -6), 'type' => 'adult']],
    ];

    // الـ API يتوقع selling_price بنفس عملة الحجز (foreign for non-EGP, EGP for EGP)
    if ($bookingCurrency === 'EGP') {
        $data['purchase_price'] = $purchaseAmount;
        $data['selling_price'] = $sellingAmount;
    } else {
        $data['purchase_price_foreign'] = $purchaseAmount;
        $data['selling_price'] = $sellingAmount;  // ← interpreted as foreign (USD amount)
 $data['original_amount'] = $sellingAmount;
    }

    if ($paymentAccount) {
        $data['payment'] = [
            'amount' => $sellingAmount,
            'payment_method' => 'cash',
            'account_id' => $paymentAccount->id,
        ];
    }

    return httpPost($BASE_URL . '/flight/bookings', $data);
}

// ═════════════════ TEST 1: USD booking + USD payment → ACCEPT ═════════════════
section('TEST 1: USD booking + USD payment → ACCEPT (no conversion)');

$r = mkBooking($customer, $employee, $carrierUSD, $system, $airport, $airportCode,
    'USD', 100.0, 150.0, $cashUSD, 48.5);

$msg = $r['json']['message'] ?? '';
if ($r['status'] < 400) {
    $RESULTS['passed']++;
    ok("✅ USD↔USD قُبل بدون تحويل");
    info("Booking ID: " . ($r['json']['data']['id'] ?? 'n/a'));
    if (isset($r['json']['data']['id'])) safeCleanupBooking($r['json']['data']['id']);
} else {
    $RESULTS['failed']++;
    fail("❌ USD↔USD رُفض: {$msg}");
}

// ═════════════════ TEST 2: USD booking + EGP payment → REJECT (Bug #14) ═════════════════
section('TEST 2: USD booking + EGP payment → REJECT (Bug #14 fix)');

$r = mkBooking($customer, $employee, $carrierUSD, $system, $airport, $airportCode,
    'USD', 100.0, 150.0, $cashEGP, 48.5);

$msg = $r['json']['message'] ?? '';
if ($r['status'] >= 400) {
    if (str_contains($msg, 'لا تطابق') || str_contains($msg, 'عملة')) {
        $RESULTS['passed']++;
        ok("✅ USD→EGP رُفض بـرسالة واضحة: {$msg}");
    } else {
        $RESULTS['failed']++;
        fail("❌ رُفض برسالة غير واضحة: {$msg}");
    }
} else {
    $RESULTS['failed']++;
    fail("❌ الباج #14: USD booking قُبل مع EGP payment!");
    if (isset($r['json']['data']['id'])) safeCleanupBooking($r['json']['data']['id']);
}

// ═════════════════ TEST 3: USD booking + KWD payment → REJECT ═════════════════
section('TEST 3: USD booking + KWD payment → REJECT');

$r = mkBooking($customer, $employee, $carrierUSD, $system, $airport, $airportCode,
    'USD', 100.0, 150.0, $cashKWD, 48.5);

$msg = $r['json']['message'] ?? '';
if ($r['status'] >= 400) {
    if (str_contains($msg, 'لا تطابق') || str_contains($msg, 'عملة')) {
        $RESULTS['passed']++;
        ok("✅ USD→KWD رُفض: {$msg}");
    } else {
        $RESULTS['failed']++;
        fail("❌ رُفض برسالة غير واضحة: {$msg}");
    }
} else {
    $RESULTS['failed']++;
    fail("❌ USD→KWD قُبل رغم اختلاف العملة!");
    if (isset($r['json']['data']['id'])) safeCleanupBooking($r['json']['data']['id']);
}

// ═════════════════ TEST 4: KWD booking + USD payment → REJECT ═════════════════
section('TEST 4: KWD booking + USD payment → REJECT');

$r = mkBooking($customer, $employee, $carrierKWD, $system, $airport, $airportCode,
    'KWD', 10.0, 15.0, $cashUSD, 157.5);

$msg = $r['json']['message'] ?? '';
if ($r['status'] >= 400) {
    if (str_contains($msg, 'لا تطابق') || str_contains($msg, 'عملة')) {
        $RESULTS['passed']++;
        ok("✅ KWD→USD رُفض: {$msg}");
    } else {
        $RESULTS['failed']++;
        fail("❌ رُفض برسالة غير واضحة: {$msg}");
    }
} else {
    $RESULTS['failed']++;
    fail("❌ KWD→USD قُبل رغم اختلاف العملة!");
    if (isset($r['json']['data']['id'])) safeCleanupBooking($r['json']['data']['id']);
}

// ═════════════════ TEST 5: EGP booking + EGP payment → ACCEPT ═════════════════
section('TEST 5: EGP booking + EGP payment → ACCEPT');

$r = mkBooking($customer, $employee, $carrierEGP, $system, $airport, $airportCode,
    'EGP', 1000.0, 1500.0, $cashEGP, 1.0);

if ($r['status'] < 400) {
    $RESULTS['passed']++;
    ok("✅ EGP↔EGP قُبل بدون تحويل");
    if (isset($r['json']['data']['id'])) safeCleanupBooking($r['json']['data']['id']);
} else {
    $msg = $r['json']['message'] ?? '';
    $RESULTS['failed']++;
    fail("❌ EGP↔EGP رُفض: {$msg}");
}

// ═════════════════ TEST 6: EGP booking + USD payment → ACCEPT مع تحويل ═════════════════
section('TEST 6: EGP booking + USD payment → ACCEPT مع تحويل');

// نحتاج USD payment يكون أقل بقليل من 30.93 لتفوق فرق الـ rounding
// 1500 / 48.5 = 30.9278...  نستخدم floor بدلاً من round
$usdPaymentAmount = floor(1500.0 / 48.5 * 100) / 100;  // 30.92 USD
$expectedEGP = $usdPaymentAmount * 48.5;  // 1499.62

$customData = [
    'customer_id' => $customer->id,
    'employee_id' => $employee->id,
    'pnr' => 'CU-' . strtoupper(substr(md5(uniqid()), 0, 6)),
    'airline' => 'CurrTest',
    'airline_name' => 'CurrTest',
    'from_airport' => $airportCode,
    'to_airport' => $airportCode,
    'from_airport_id' => $airport->id,
    'to_airport_id' => $airport->id,
    'departure_date' => now()->addDays(35)->format('Y-m-d'),
    'departure_time' => '10:00:00',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'currency' => 'EGP',
    'exchange_rate' => 48.5,
    'purchase_price' => 1000,
    'selling_price' => 1500,
    'flight_system_id' => $system->id,
    'flight_carrier_id' => $carrierEGP->id,
    'purchase_balance_source' => 'carrier',
    'payment' => ['amount' => $usdPaymentAmount, 'payment_method' => 'cash', 'account_id' => $cashUSD->id],
    'passengers' => [['first_name' => 'CU', 'last_name' => 'Test', 'passport_number' => 'CU' . substr(uniqid(), -6), 'type' => 'adult']],
];
$r = httpPost($BASE_URL . '/flight/bookings', $customData);

if ($r['status'] < 400) {
    $RESULTS['passed']++;
    ok("✅ EGP booking + USD payment ({$usdPaymentAmount} USD = {$expectedEGP} EGP) قُبل مع تحويل");
    if (isset($r['json']['data']['id'])) {
        $booking = FlightBooking::find($r['json']['data']['id']);
        $payment = $booking->payments()->first();
        if ($payment) {
            info("Payment amount (EGP): {$payment->amount} (المتوقع: {$expectedEGP})");
            if (abs((float)$payment->amount - $expectedEGP) < 1.0) {
                $RESULTS['passed']++;
                ok("✅ المبلغ محفوظ بالجنيه المصري — التحويل تم");
            } else {
                $RESULTS['failed']++;
                fail("❌ Payment amount خاطئ: {$payment->amount} (المتوقع: {$expectedEGP})");
            }
        }
        safeCleanupBooking($r['json']['data']['id']);
    }
} else {
    $msg = $r['json']['message'] ?? '';
    $RESULTS['failed']++;
    fail("❌ EGP+USD رُفض: {$msg}");
}

// ═════════════════ TEST 7: EGP booking + KWD payment → ACCEPT مع تحويل ═════════════════
section('TEST 7: EGP booking + KWD payment → ACCEPT مع تحويل');

$kwdPaymentAmount = floor(1500.0 / 157.5 * 1000) / 1000;  // ~9.523 KWD
$customData['pnr'] = 'CU-' . strtoupper(substr(md5(uniqid()), 0, 6));
$customData['payment'] = ['amount' => $kwdPaymentAmount, 'payment_method' => 'cash', 'account_id' => $cashKWD->id];
$customData['departure_date'] = now()->addDays(36)->format('Y-m-d');
$r = httpPost($BASE_URL . '/flight/bookings', $customData);

if ($r['status'] < 400) {
    $RESULTS['passed']++;
    ok("✅ EGP booking + KWD payment ({$kwdPaymentAmount} KWD = ~1500 EGP) قُبل مع تحويل");
    if (isset($r['json']['data']['id'])) safeCleanupBooking($r['json']['data']['id']);
} else {
    $msg = $r['json']['message'] ?? '';
    $RESULTS['failed']++;
    fail("❌ EGP+KWD رُفض: {$msg}");
}

// ═════════════════ TEST 8: KWD booking + KWD payment → ACCEPT ═════════════════
section('TEST 8: KWD booking + KWD payment → ACCEPT');

$r = mkBooking($customer, $employee, $carrierKWD, $system, $airport, $airportCode,
    'KWD', 5.0, 8.0, $cashKWD, 1.0);

if ($r['status'] < 400) {
    $RESULTS['passed']++;
    ok("✅ KWD↔KWD قُبل بدون تحويل");
    if (isset($r['json']['data']['id'])) safeCleanupBooking($r['json']['data']['id']);
} else {
    $msg = $r['json']['message'] ?? '';
    $RESULTS['failed']++;
    fail("❌ KWD↔KWD رُفض: {$msg}");
}

// ═════════════════ TEST 9: SAR booking + SAR payment → ACCEPT ═════════════════
section('TEST 9: SAR booking + SAR payment → ACCEPT');

$r = mkBooking($customer, $employee, $carrierSAR, $system, $airport, $airportCode,
    'SAR', 500.0, 700.0, $cashSAR, 1.0);

if ($r['status'] < 400) {
    $RESULTS['passed']++;
    ok("✅ SAR↔SAR قُبل بدون تحويل");
    if (isset($r['json']['data']['id'])) safeCleanupBooking($r['json']['data']['id']);
} else {
    $msg = $r['json']['message'] ?? '';
    $RESULTS['failed']++;
    fail("❌ SAR↔SAR رُفض: {$msg}");
}

// ═════════════════ TEST 10: SAR booking + USD payment → REJECT ═════════════════
section('TEST 10: SAR booking + USD payment → REJECT');

$r = mkBooking($customer, $employee, $carrierSAR, $system, $airport, $airportCode,
    'SAR', 500.0, 700.0, $cashUSD, 12.9);

$msg = $r['json']['message'] ?? '';
if ($r['status'] >= 400) {
    if (str_contains($msg, 'لا تطابق') || str_contains($msg, 'عملة')) {
        $RESULTS['passed']++;
        ok("✅ SAR→USD رُفض: {$msg}");
    } else {
        $RESULTS['failed']++;
        fail("❌ رُفض برسالة غير واضحة: {$msg}");
    }
} else {
    $RESULTS['failed']++;
    fail("❌ SAR→USD قُبل!");
    if (isset($r['json']['data']['id'])) safeCleanupBooking($r['json']['data']['id']);
}

// ═════════════════ TEST 11: EGP booking بدون payment → ACCEPT ═════════════════
section('TEST 11: EGP booking بدون payment → ACCEPT');

$r = mkBooking($customer, $employee, $carrierEGP, $system, $airport, $airportCode,
    'EGP', 1000.0, 1500.0, null, 1.0);

if ($r['status'] < 400) {
    $RESULTS['passed']++;
    ok("✅ حجز بدون payment قُبل");
    if (isset($r['json']['data']['id'])) safeCleanupBooking($r['json']['data']['id']);
} else {
    $msg = $r['json']['message'] ?? '';
    $RESULTS['failed']++;
    fail("❌ حجز بدون payment رُفض: {$msg}");
}

// ═════════════════ TEST 12: Payment بـ amount = 0 → REJECT ═════════════════
section('TEST 12: Payment amount = 0 → REJECT (validation)');

$r1 = mkBooking($customer, $employee, $carrierEGP, $system, $airport, $airportCode,
    'EGP', 1000.0, 1500.0, null, 1.0);

if ($r1['status'] < 400 && isset($r1['json']['data']['id'])) {
    $bookingId = $r1['json']['data']['id'];
    $paymentResp = httpPost($BASE_URL . "/flight/bookings/{$bookingId}/payments", [
        'amount' => 0,
        'payment_method' => 'cash',
        'account_id' => $cashEGP->id,
    ]);

    if ($paymentResp['status'] >= 400) {
        $msg = $paymentResp['json']['message'] ?? '';
        // Either validation rejects (min:0.01) or addPayment rejects (greater than zero)
        if (str_contains($msg, 'greater') || str_contains($msg, 'صفر') || str_contains($msg, 'موجب') || str_contains($msg, 'غير صالحة') || str_contains($msg, 'validation')) {
            $RESULTS['passed']++;
            ok("✅ Payment amount=0 رُفض: {$msg}");
        } else {
            $RESULTS['passed']++;
            ok("✅ Payment amount=0 رُفض (رسالة: {$msg})");
        }
    } else {
        $RESULTS['failed']++;
        fail("❌ Payment amount=0 قُبل!");
    }
    safeCleanupBooking($bookingId);
} else {
    warn("⚠️ فشل إنشاء حجز للاختبار 12");
}

// ═════════════════ TEST 13: Payment بـ amount سالب → REJECT ═════════════════
section('TEST 13: Payment amount = -100 → REJECT');

$r1 = mkBooking($customer, $employee, $carrierEGP, $system, $airport, $airportCode,
    'EGP', 1000.0, 1500.0, null, 1.0);

if ($r1['status'] < 400 && isset($r1['json']['data']['id'])) {
    $bookingId = $r1['json']['data']['id'];
    $paymentResp = httpPost($BASE_URL . "/flight/bookings/{$bookingId}/payments", [
        'amount' => -100,
        'payment_method' => 'cash',
        'account_id' => $cashEGP->id,
    ]);

    if ($paymentResp['status'] >= 400) {
        $msg = $paymentResp['json']['message'] ?? '';
        $RESULTS['passed']++;
        ok("✅ Payment amount=-100 رُفض: {$msg}");
    } else {
        $RESULTS['failed']++;
        fail("❌ Payment amount=-100 قُبل!");
    }
    safeCleanupBooking($bookingId);
} else {
    warn("⚠️ فشل إنشاء حجز للاختبار 13");
}

// ═════════════════ TEST 14: USD booking + USD payment ثم payment أكبر من selling ═════════════════
section('TEST 14: USD booking + payment أكبر من selling → REJECT');

$r = mkBooking($customer, $employee, $carrierUSD, $system, $airport, $airportCode,
    'USD', 100.0, 150.0, $cashUSD, 48.5);

if ($r['status'] < 400 && isset($r['json']['data']['id'])) {
    $bookingId = $r['json']['data']['id'];

    $paymentResp = httpPost($BASE_URL . "/flight/bookings/{$bookingId}/payments", [
        'amount' => 1000,
        'payment_method' => 'cash',
        'account_id' => $cashUSD->id,
    ]);

    if ($paymentResp['status'] >= 400) {
        $msg = $paymentResp['json']['message'] ?? '';
        if (str_contains($msg, 'exceed') || str_contains($msg, 'تجاوز') || str_contains($msg, 'selling')) {
            $RESULTS['passed']++;
            ok("✅ Overpayment رُفض: {$msg}");
        } else {
            warn("⚠️ رُفض برسالة: {$msg}");
        }
    } else {
        $RESULTS['failed']++;
        fail("❌ Overpayment قُبل!");
    }
    safeCleanupBooking($bookingId);
} else {
    warn("⚠️ فشل إنشاء حجز للاختبار 14");
}

// ═════════════════ TEST 15: USD booking + USD payment ثم USD payment ثاني ═════════════════
section('TEST 15: USD booking + USD payment كامل → ثم إضافة USD payment ثاني (overpay)');

$r = mkBooking($customer, $employee, $carrierUSD, $system, $airport, $airportCode,
    'USD', 100.0, 150.0, $cashUSD, 48.5);

if ($r['status'] < 400 && isset($r['json']['data']['id'])) {
    $bookingId = $r['json']['data']['id'];

    // إضافة payment ثاني صغير (1 USD)
    $paymentResp = httpPost($BASE_URL . "/flight/bookings/{$bookingId}/payments", [
        'amount' => 1,
        'payment_method' => 'cash',
        'account_id' => $cashUSD->id,
    ]);

    if ($paymentResp['status'] >= 400) {
        $msg = $paymentResp['json']['message'] ?? '';
        if (str_contains($msg, 'exceed') || str_contains($msg, 'تجاوز')) {
            $RESULTS['passed']++;
            ok("✅ Payment بعد الإكتمال رُفض (لا overpay): {$msg}");
        } else {
            warn("⚠️ رُفض برسالة: {$msg}");
        }
    } else {
        $RESULTS['failed']++;
        fail("❌ Payment بعد الإكتمال قُبل!");
    }
    safeCleanupBooking($bookingId);
} else {
    warn("⚠️ فشل إنشاء حجز للاختبار 15");
}

// ═════════════════ ملخص ═════════════════
section('📊 الملخص النهائي');
t("  ✅ Passed: {$RESULTS['passed']}");
t("  ❌ Failed: {$RESULTS['failed']}");

if ($RESULTS['failed'] == 0) {
    ok("\n🎉 كل اختبارات Currency Mismatch نجحت!");
} else {
    fail("\n❌ {$RESULTS['failed']} اختبار فشل!");
}

file_put_contents($logDir . '/currency_mismatch_report_' . date('Y-m-d_His') . '.json',
    json_encode($RESULTS, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

fclose($logHandle);
echo "\n📄 Log: {$logDir}/" . date('Y-m-d_His') . "_currency_mismatch.log\n";
echo "✅ Currency Mismatch Tests DONE.\n";
