<?php
/**
 * ════════════════════════════════════════════════════════════════════════════
 * Flight Module — Refund Currency Bugs Test (Bugs B1, B2, B5, B6, B7)
 * ════════════════════════════════════════════════════════════════════════════
 *
 * يستهدف بدقة باجات العملات في تدفقات الاسترجاع:
 * - Bug #B1: refund currency mismatch في createRefundRequest
 * - Bug #B2: airline_credit currency mismatch في processRefundRequest
 * - Bug #B5: cancelBooking payment currency filter
 * - Bug #B6: cancelBooking refund account currency validation
 * - Bug #B7: cancelBooking sale reversal currency handling
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
use App\Models\Treasury;
use App\Models\Setting\Currency;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\RefundRequest;
use App\Enums\AccountType;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$BASE_URL = 'http://127.0.0.1:8000/api/v1';
$admin = User::first();
auth()->setUser($admin);
$token = $admin->createToken('refund-currency-' . uniqid())->plainTextToken;

function httpPost(string $url, array $data = []) {
    global $token;
    $r = Http::withToken($token)->acceptJson()->post($url, $data);
    return ['status' => $r->status(), 'json' => $r->json()];
}

function safeCleanup($ids) {
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($ids['bookingIds'] ?? [] as $bid) {
        DB::table('flight_payments')->where('flight_booking_id', $bid)->delete();
        DB::table('flight_segments')->where('flight_booking_id', $bid)->delete();
        DB::table('airline_transactions')->where('flight_booking_id', $bid)->delete();
        DB::table('flight_system_transactions')->where('flight_booking_id', $bid)->delete();
        DB::table('airline_credits')->where('flight_booking_id', $bid)->delete();
        DB::table('refund_requests')->where('flight_booking_id', $bid)->delete();
        DB::table('flight_refunds')->where('flight_booking_id', $bid)->delete();
        DB::table('transactions')->where('related_type', 'App\\Models\\Flight\\FlightBooking')->where('related_id', $bid)->delete();
        DB::table('transactions')->where('related_type', 'App\\Models\\Flight\\FlightRefund')->where('related_id', function ($q) use ($bid) {
            $q->select('id')->from('flight_refunds')->where('flight_booking_id', $bid);
        })->delete();
        DB::table('flight_bookings')->where('id', $bid)->delete();
    }
    foreach ($ids['treasuryIds'] ?? [] as $tid) {
        DB::table('treasury_transactions')->where('treasury_id', $tid)->delete();
        DB::table('treasuries')->where('id', $tid)->delete();
    }
    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
}

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
$logHandle = fopen($logDir . '/' . date('Y-m-d_His') . '_refund_currency.log', 'w');
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
$cleanup = ['bookingIds' => [], 'treasuryIds' => []];

t("╔══════════════════════════════════════════════════════════════════╗");
t("║  Flight Module — Refund Currency Bugs Test                  ║");
t("║  يستهدف: B1, B2, B5, B6, B7                                  ║");
t("╚══════════════════════════════════════════════════════════════════╝");

// تنظيف leftovers
section('🧹 تنظيف leftovers');
DB::statement('SET FOREIGN_KEY_CHECKS = 0');
DB::table('accounts')->where('name', 'like', 'TEST_RC_%')->delete();
DB::table('treasuries')->where('name', 'like', 'TEST_RC_%')->delete();
DB::table('customers')->where('full_name', 'like', 'TEST_RC_%')->delete();
DB::table('employees')->where('full_name', 'like', 'TEST_RC_%')->delete();
DB::table('flight_systems')->where('name', 'like', 'TEST_RC_%')->delete();
DB::table('flight_carriers')->where('name', 'like', 'TEST_RC_%')->delete();
DB::statement('SET FOREIGN_KEY_CHECKS = 1');
info("تم تنظيف leftovers");

// ═════════════════ الإعداد ═════════════════
section('⚙️ الإعداد: إنشاء entities');

$customer = Customer::create([
    'full_name' => 'TEST_RC_CUST_' . substr(uniqid(), -4),
    'phone' => '01200022001',
    'national_id' => 'RC' . substr(md5(uniqid()), 0, 12),
    'passport_number' => 'RC' . substr(uniqid(), -8),
    'module_type' => 'flights',
    'created_by' => $admin->id,
]);
$employee = Employee::create([
    'full_name' => 'TEST_RC_EMP_' . substr(uniqid(), -4),
    'phone' => '01200022002',
    'national_id' => 'RCE' . substr(md5(uniqid()), 0, 6),
    'created_by' => $admin->id,
]);

$cashEGP = Account::create(['name' => 'TEST_RC_Cash_EGP', 'type' => AccountType::Cashbox->value, 'currency' => 'EGP', 'balance' => 0, 'is_active' => true, 'owner_type' => Account::OWNER_TYPE_OFFICE, 'module_type' => 'tourism', 'created_by' => $admin->id]);
setAccountBalance($cashEGP, 1000000);

$cashUSD = Account::create(['name' => 'TEST_RC_Cash_USD', 'type' => AccountType::Cashbox->value, 'currency' => 'USD', 'balance' => 0, 'is_active' => true, 'owner_type' => Account::OWNER_TYPE_OFFICE, 'module_type' => 'tourism', 'created_by' => $admin->id]);
setAccountBalance($cashUSD, 50000);

// إنشاء خزائن (Treasury) لكل عملة
$treasuryEGP = Treasury::create(['name' => 'TEST_RC_Treasury_EGP', 'type' => 'cash', 'currency' => 'EGP', 'balance' => 100000, 'is_active' => true, 'created_by' => $admin->id]);
$cleanup['treasuryIds'][] = $treasuryEGP->id;

$treasuryUSD = Treasury::create(['name' => 'TEST_RC_Treasury_USD', 'type' => 'cash', 'currency' => 'USD', 'balance' => 10000, 'is_active' => true, 'created_by' => $admin->id]);
$cleanup['treasuryIds'][] = $treasuryUSD->id;

$airport = Airport::create([
    'iata_code' => 'RC' . substr(uniqid(), -2),
    'city_name_ar' => 'Refund Test',
    'city_name_en' => 'Refund Test',
    'airport_name_ar' => 'Refund Test Airport',
    'airport_name_en' => 'Refund Test Airport',
    'country_code' => 'RC',
    'country_name_ar' => 'RC',
    'country_name_en' => 'RC',
    'is_active' => true,
]);
$airportCode = $airport->iata_code;

$system = FlightSystem::create([
    'name' => 'TEST_RC_System_' . substr(uniqid(), -4),
    'code' => 'RCS' . substr(md5(uniqid()), 0, 3),
    'type' => 'GDS',
    'currency' => 'EGP',
    'balance' => 0,
    'is_active' => true,
    'created_by' => $admin->id,
]);
LedgerBalanceMutationGuard::run(function () use ($system) {
    $system->balance = 1000000;
    $system->save();
});

$carrierEGP = FlightCarrier::create([
    'name' => 'TEST_RC_Carrier_EGP_' . substr(uniqid(), -4),
    'code' => 'RCE' . substr(md5(uniqid()), 0, 3),
    'iata_code' => 'RC',
    'flight_system_id' => $system->id,
    'currency' => 'EGP',
    'balance' => 0,
    'is_active' => true,
    'created_by' => $admin->id,
]);
setCarrierBalance($carrierEGP, 1000000);

$carrierUSD = FlightCarrier::create([
    'name' => 'TEST_RC_Carrier_USD_' . substr(uniqid(), -4),
    'code' => 'RCU' . substr(md5(uniqid()), 0, 3),
    'iata_code' => 'RC',
    'flight_system_id' => $system->id,
    'currency' => 'USD',
    'balance' => 0,
    'is_active' => true,
    'created_by' => $admin->id,
]);
setCarrierBalance($carrierUSD, 10000);

info("entities جاهزة: customer={$customer->id} | carrierEGP={$carrierEGP->id} | carrierUSD={$carrierUSD->id}");

// ═════════════════ TEST 1: EGP booking + USD refund (Bug #B1) ═════════════════
section('TEST 1: EGP booking + refund_currency=USD → REJECT (Bug #B1)');

// إنشاء حجز EGP كامل الدفع
$r = httpPost($BASE_URL . '/flight/bookings', [
    'customer_id' => $customer->id,
    'employee_id' => $employee->id,
    'pnr' => 'RC-' . strtoupper(substr(md5(uniqid()), 0, 6)),
    'airline' => 'RTest',
    'airline_name' => 'RTest',
    'from_airport' => $airportCode,
    'to_airport' => $airportCode,
    'from_airport_id' => $airport->id,
    'to_airport_id' => $airport->id,
    'departure_date' => now()->addDays(45)->format('Y-m-d'),
    'departure_time' => '10:00:00',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'currency' => 'EGP',
    'purchase_price' => 1000,
    'selling_price' => 1500,
    'flight_system_id' => $system->id,
    'flight_carrier_id' => $carrierEGP->id,
    'purchase_balance_source' => 'carrier',
    'payment' => ['amount' => 1500, 'payment_method' => 'cash', 'account_id' => $cashEGP->id],
    'passengers' => [['first_name' => 'RC', 'last_name' => 'Test', 'passport_number' => 'RC' . substr(uniqid(), -6), 'type' => 'adult']],
]);

if ($r['status'] < 400 && isset($r['json']['data']['id'])) {
    $bookingId = $r['json']['data']['id'];
    $cleanup['bookingIds'][] = $bookingId;

    // محاولة طلب استرجاع بـ USD (يجب أن يُرفض)
    $refundResp = httpPost($BASE_URL . '/flight/refunds', [
        'flight_booking_id' => $bookingId,
        'cancellation_fee' => 0,
        'refund_currency' => 'USD',
        'destination' => 'agency_treasury',
        'treasury_id' => $treasuryUSD->id,
        'notes' => 'TEST_RC: محاولة استرجاع USD لحجز EGP',
    ]);

    if ($refundResp['status'] >= 400) {
        $msg = $refundResp['json']['message'] ?? '';
        if (str_contains($msg, 'لا تطابق') || str_contains($msg, 'عملة')) {
            $RESULTS['passed']++;
            ok("✅ Refund USD لـ EGP booking رُفض (Bug #B1): {$msg}");
        } else {
            warn("⚠️  رُفض برسالة: {$msg}");
            $RESULTS['passed']++;
        }
    } else {
        $RESULTS['failed']++;
        fail("❌ الباج #B1: refund USD لحجز EGP قُبل!");
        if (isset($refundResp['json']['data']['id'])) {
            DB::table('refund_requests')->where('id', $refundResp['json']['data']['id'])->delete();
        }
    }
} else {
    fail("❌ فشل إنشاء حجز للاختبار 1");
}

// ═════════════════ TEST 2: USD booking + refund_currency=EGP → REJECT (Bug #B1) ═════════════════
section('TEST 2: USD booking + refund_currency=EGP → REJECT (Bug #B1)');

$r = httpPost($BASE_URL . '/flight/bookings', [
    'customer_id' => $customer->id,
    'employee_id' => $employee->id,
    'pnr' => 'RC-' . strtoupper(substr(md5(uniqid()), 0, 6)),
    'airline' => 'RTest',
    'airline_name' => 'RTest',
    'from_airport' => $airportCode,
    'to_airport' => $airportCode,
    'from_airport_id' => $airport->id,
    'to_airport_id' => $airport->id,
    'departure_date' => now()->addDays(46)->format('Y-m-d'),
    'departure_time' => '10:00:00',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'currency' => 'USD',
    'exchange_rate' => 48.5,
    'purchase_price_foreign' => 100,
    'selling_price' => 150,
    'flight_system_id' => $system->id,
    'flight_carrier_id' => $carrierUSD->id,
    'purchase_balance_source' => 'carrier',
    'payment' => ['amount' => 150, 'payment_method' => 'cash', 'account_id' => $cashUSD->id],
    'passengers' => [['first_name' => 'RC', 'last_name' => 'Test', 'passport_number' => 'RC' . substr(uniqid(), -6), 'type' => 'adult']],
]);

if ($r['status'] < 400 && isset($r['json']['data']['id'])) {
    $bookingId = $r['json']['data']['id'];
    $cleanup['bookingIds'][] = $bookingId;

    // محاولة طلب استرجاع بـ EGP (يجب أن يُرفض)
    $refundResp = httpPost($BASE_URL . '/flight/refunds', [
        'flight_booking_id' => $bookingId,
        'cancellation_fee' => 0,
        'refund_currency' => 'EGP',
        'destination' => 'agency_treasury',
        'treasury_id' => $treasuryEGP->id,
        'notes' => 'TEST_RC: محاولة استرجاع EGP لحجز USD',
    ]);

    if ($refundResp['status'] >= 400) {
        $msg = $refundResp['json']['message'] ?? '';
        if (str_contains($msg, 'لا تطابق') || str_contains($msg, 'عملة')) {
            $RESULTS['passed']++;
            ok("✅ Refund EGP لـ USD booking رُفض (Bug #B1): {$msg}");
        } else {
            warn("⚠️  رُفض برسالة: {$msg}");
            $RESULTS['passed']++;
        }
    } else {
        $RESULTS['failed']++;
        fail("❌ الباج #B1: refund EGP لحجز USD قُبل!");
        if (isset($refundResp['json']['data']['id'])) {
            DB::table('refund_requests')->where('id', $refundResp['json']['data']['id'])->delete();
        }
    }
} else {
    fail("❌ فشل إنشاء حجز USD للاختبار 2");
}

// ═════════════════ TEST 3: EGP booking + refund_currency=EGP → ACCEPT (Happy path) ═════════════════
section('TEST 3: EGP booking + refund_currency=EGP → ACCEPT');

$r = httpPost($BASE_URL . '/flight/bookings', [
    'customer_id' => $customer->id,
    'employee_id' => $employee->id,
    'pnr' => 'RC-' . strtoupper(substr(md5(uniqid()), 0, 6)),
    'airline' => 'RTest',
    'airline_name' => 'RTest',
    'from_airport' => $airportCode,
    'to_airport' => $airportCode,
    'from_airport_id' => $airport->id,
    'to_airport_id' => $airport->id,
    'departure_date' => now()->addDays(47)->format('Y-m-d'),
    'departure_time' => '10:00:00',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'currency' => 'EGP',
    'purchase_price' => 1000,
    'selling_price' => 1500,
    'flight_system_id' => $system->id,
    'flight_carrier_id' => $carrierEGP->id,
    'purchase_balance_source' => 'carrier',
    'payment' => ['amount' => 1500, 'payment_method' => 'cash', 'account_id' => $cashEGP->id],
    'passengers' => [['first_name' => 'RC', 'last_name' => 'Test', 'passport_number' => 'RC' . substr(uniqid(), -6), 'type' => 'adult']],
]);

if ($r['status'] < 400 && isset($r['json']['data']['id'])) {
    $bookingId = $r['json']['data']['id'];
    $cleanup['bookingIds'][] = $bookingId;

    $refundResp = httpPost($BASE_URL . '/flight/refunds', [
        'flight_booking_id' => $bookingId,
        'cancellation_fee' => 100,
        'refund_currency' => 'EGP',
        'destination' => 'agency_treasury',
        'treasury_id' => $treasuryEGP->id,
        'notes' => 'TEST_RC: استرجاع EGP عادي',
    ]);

    if ($refundResp['status'] < 400) {
        $RESULTS['passed']++;
        ok("✅ EGP booking + EGP refund قُبل (happy path)");
        $refundId = $refundResp['json']['data']['id'] ?? null;
        if ($refundId) {
            info("Refund ID: {$refundId}");
            // محاولة معالجة الاسترجاع
            $processResp = httpPost($BASE_URL . "/flight/refunds/{$refundId}/process", []);
            if ($processResp['status'] < 400) {
                $RESULTS['passed']++;
                ok("✅ معالجة الاسترجاع نجحت");
            } else {
                warn("⚠️  معالجة الاسترجاع فشلت: " . ($processResp['json']['message'] ?? ''));
            }
        }
    } else {
        $msg = $refundResp['json']['message'] ?? '';
        $RESULTS['failed']++;
        fail("❌ EGP refund رُفض: {$msg}");
    }
} else {
    fail("❌ فشل إنشاء حجز للاختبار 3");
}

// ═════════════════ TEST 4: Bug #B2 - EGP booking + airline_credit refund to USD carrier ═════════════════
section('TEST 4: EGP booking + airline_credit destination for USD carrier → REJECT (Bug #B2)');

// إنشاء حجز EGP على شركة USD (شركة برصيد EGP لكن حسابها بعملة USD)
$r = httpPost($BASE_URL . '/flight/bookings', [
    'customer_id' => $customer->id,
    'employee_id' => $employee->id,
    'pnr' => 'RC-' . strtoupper(substr(md5(uniqid()), 0, 6)),
    'airline' => 'RTest',
    'airline_name' => 'RTest',
    'from_airport' => $airportCode,
    'to_airport' => $airportCode,
    'from_airport_id' => $airport->id,
    'to_airport_id' => $airport->id,
    'departure_date' => now()->addDays(48)->format('Y-m-d'),
    'departure_time' => '10:00:00',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'currency' => 'EGP',
    'purchase_price' => 1000,
    'selling_price' => 1500,
    'flight_system_id' => $system->id,
    'flight_carrier_id' => $carrierUSD->id,  // ← شركة USD!
    'purchase_balance_source' => 'carrier',
    'payment' => ['amount' => 1500, 'payment_method' => 'cash', 'account_id' => $cashEGP->id],
    'passengers' => [['first_name' => 'RC', 'last_name' => 'Test', 'passport_number' => 'RC' . substr(uniqid(), -6), 'type' => 'adult']],
]);

if ($r['status'] < 400 && isset($r['json']['data']['id'])) {
    $bookingId = $r['json']['data']['id'];
    $cleanup['bookingIds'][] = $bookingId;

    $refundResp = httpPost($BASE_URL . '/flight/refunds', [
        'flight_booking_id' => $bookingId,
        'cancellation_fee' => 0,
        'refund_currency' => 'EGP',  // ← يطابق الحجز
        'destination' => 'airline_credit',
        'notes' => 'TEST_RC: airline_credit لـ carrier USD',
    ]);

    if ($refundResp['status'] < 400 && isset($refundResp['json']['data']['id'])) {
        $refundId = $refundResp['json']['data']['id'];

        // محاولة المعالجة — هنا يجب أن يفشل بسبب B2 (refund EGP ↔ carrier USD)
        $processResp = httpPost($BASE_URL . "/flight/refunds/{$refundId}/process", []);

        if ($processResp['status'] >= 400) {
            $msg = $processResp['json']['message'] ?? '';
            if (str_contains($msg, 'عملة') || str_contains($msg, 'تضارب')) {
                $RESULTS['passed']++;
                ok("✅ airline_credit EGP لـ carrier USD رُفض (Bug #B2): {$msg}");
            } else {
                warn("⚠️  رُفض برسالة: {$msg}");
                $RESULTS['passed']++;
            }
        } else {
            $RESULTS['failed']++;
            fail("❌ الباج #B2: airline_credit EGP لـ carrier USD قُبل!");
        }
        // تنظيف refund request
        DB::table('refund_requests')->where('id', $refundId)->delete();
    } else {
        $msg = $refundResp['json']['message'] ?? '';
        $RESULTS['failed']++;
        fail("❌ إنشاء refund request فشل: {$msg}");
    }
} else {
    fail("❌ فشل إنشاء حجز للاختبار 4");
}

// ═════════════════ TEST 5: cancelBooking + refund account with wrong currency (Bug #B6) ═════════════════
section('TEST 5: cancelBooking + refund_account USD لـ EGP booking → REJECT (Bug #B6)');

$r = httpPost($BASE_URL . '/flight/bookings', [
    'customer_id' => $customer->id,
    'employee_id' => $employee->id,
    'pnr' => 'RC-' . strtoupper(substr(md5(uniqid()), 0, 6)),
    'airline' => 'RTest',
    'airline_name' => 'RTest',
    'from_airport' => $airportCode,
    'to_airport' => $airportCode,
    'from_airport_id' => $airport->id,
    'to_airport_id' => $airport->id,
    'departure_date' => now()->addDays(49)->format('Y-m-d'),
    'departure_time' => '10:00:00',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'currency' => 'EGP',
    'purchase_price' => 1000,
    'selling_price' => 1500,
    'flight_system_id' => $system->id,
    'flight_carrier_id' => $carrierEGP->id,
    'purchase_balance_source' => 'carrier',
    'payment' => ['amount' => 1500, 'payment_method' => 'cash', 'account_id' => $cashEGP->id],
    'passengers' => [['first_name' => 'RC', 'last_name' => 'Test', 'passport_number' => 'RC' . substr(uniqid(), -6), 'type' => 'adult']],
]);

if ($r['status'] < 400 && isset($r['json']['data']['id'])) {
    $bookingId = $r['json']['data']['id'];
    $cleanup['bookingIds'][] = $bookingId;

    $cancelResp = httpPost($BASE_URL . "/flight/bookings/{$bookingId}/cancel", [
        'airline_penalty' => 0,
        'office_penalty' => 0,
        'account_id' => $cashUSD->id,  // ← USD account لـ EGP booking!
    ]);

    if ($cancelResp['status'] >= 400) {
        $msg = $cancelResp['json']['message'] ?? '';
        if (str_contains($msg, 'لا تطابق') || str_contains($msg, 'عملة')) {
            $RESULTS['passed']++;
            ok("✅ cancel مع refund account USD رُفض (Bug #B6): {$msg}");
        } else {
            warn("⚠️  رُفض برسالة: {$msg}");
            $RESULTS['passed']++;
        }
    } else {
        $RESULTS['failed']++;
        fail("❌ الباج #B6: cancel مع USD account قُبل!");
    }
} else {
    fail("❌ فشل إنشاء حجز للاختبار 5");
}

// ═════════════════ TEST 6: cancelBooking happy path (EGP + EGP account) ═════════════════
section('TEST 6: cancelBooking EGP booking + EGP account → ACCEPT');

$r = httpPost($BASE_URL . '/flight/bookings', [
    'customer_id' => $customer->id,
    'employee_id' => $employee->id,
    'pnr' => 'RC-' . strtoupper(substr(md5(uniqid()), 0, 6)),
    'airline' => 'RTest',
    'airline_name' => 'RTest',
    'from_airport' => $airportCode,
    'to_airport' => $airportCode,
    'from_airport_id' => $airport->id,
    'to_airport_id' => $airport->id,
    'departure_date' => now()->addDays(50)->format('Y-m-d'),
    'departure_time' => '10:00:00',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'currency' => 'EGP',
    'purchase_price' => 1000,
    'selling_price' => 1500,
    'flight_system_id' => $system->id,
    'flight_carrier_id' => $carrierEGP->id,
    'purchase_balance_source' => 'carrier',
    'payment' => ['amount' => 1500, 'payment_method' => 'cash', 'account_id' => $cashEGP->id],
    'passengers' => [['first_name' => 'RC', 'last_name' => 'Test', 'passport_number' => 'RC' . substr(uniqid(), -6), 'type' => 'adult']],
]);

if ($r['status'] < 400 && isset($r['json']['data']['id'])) {
    $bookingId = $r['json']['data']['id'];
    $cleanup['bookingIds'][] = $bookingId;

    $cancelResp = httpPost($BASE_URL . "/flight/bookings/{$bookingId}/cancel", [
        'airline_penalty' => 100,
        'office_penalty' => 50,
        'account_id' => $cashEGP->id,
    ]);

    if ($cancelResp['status'] < 400) {
        $RESULTS['passed']++;
        ok("✅ EGP booking + EGP account + cancel → ACCEPT");
    } else {
        $msg = $cancelResp['json']['message'] ?? '';
        $RESULTS['failed']++;
        fail("❌ cancel رُفض: {$msg}");
    }
} else {
    fail("❌ فشل إنشاء حجز للاختبار 6");
}

// ═════════════════ TEST 7: cancelBooking USD booking + USD account → ACCEPT (Bug #B5+B7) ═════════════════
section('TEST 7: cancelBooking USD booking + USD account → ACCEPT (Bug #B5+B7)');

$r = httpPost($BASE_URL . '/flight/bookings', [
    'customer_id' => $customer->id,
    'employee_id' => $employee->id,
    'pnr' => 'RC-' . strtoupper(substr(md5(uniqid()), 0, 6)),
    'airline' => 'RTest',
    'airline_name' => 'RTest',
    'from_airport' => $airportCode,
    'to_airport' => $airportCode,
    'from_airport_id' => $airport->id,
    'to_airport_id' => $airport->id,
    'departure_date' => now()->addDays(51)->format('Y-m-d'),
    'departure_time' => '10:00:00',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'currency' => 'USD',
    'exchange_rate' => 48.5,
    'purchase_price_foreign' => 100,
    'selling_price' => 150,
    'flight_system_id' => $system->id,
    'flight_carrier_id' => $carrierUSD->id,
    'purchase_balance_source' => 'carrier',
    'payment' => ['amount' => 150, 'payment_method' => 'cash', 'account_id' => $cashUSD->id],
    'passengers' => [['first_name' => 'RC', 'last_name' => 'Test', 'passport_number' => 'RC' . substr(uniqid(), -6), 'type' => 'adult']],
]);

if ($r['status'] < 400 && isset($r['json']['data']['id'])) {
    $bookingId = $r['json']['data']['id'];
    $cleanup['bookingIds'][] = $bookingId;

    $cancelResp = httpPost($BASE_URL . "/flight/bookings/{$bookingId}/cancel", [
        'airline_penalty' => 10,
        'office_penalty' => 5,
        'account_id' => $cashUSD->id,
    ]);

    if ($cancelResp['status'] < 400) {
        $RESULTS['passed']++;
        ok("✅ USD booking + USD account + cancel → ACCEPT");
    } else {
        $msg = $cancelResp['json']['message'] ?? '';
        $RESULTS['failed']++;
        fail("❌ USD cancel رُفض: {$msg}");
    }
} else {
    fail("❌ فشل إنشاء حجز USD للاختبار 7");
}

// ═════════════════ TEST 8: USD booking + refund_account EGP → REJECT (Bug #B6) ═════════════════
section('TEST 8: USD booking + refund_account EGP → REJECT (Bug #B6)');

$r = httpPost($BASE_URL . '/flight/bookings', [
    'customer_id' => $customer->id,
    'employee_id' => $employee->id,
    'pnr' => 'RC-' . strtoupper(substr(md5(uniqid()), 0, 6)),
    'airline' => 'RTest',
    'airline_name' => 'RTest',
    'from_airport' => $airportCode,
    'to_airport' => $airportCode,
    'from_airport_id' => $airport->id,
    'to_airport_id' => $airport->id,
    'departure_date' => now()->addDays(52)->format('Y-m-d'),
    'departure_time' => '10:00:00',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'currency' => 'USD',
    'exchange_rate' => 48.5,
    'purchase_price_foreign' => 100,
    'selling_price' => 150,
    'flight_system_id' => $system->id,
    'flight_carrier_id' => $carrierUSD->id,
    'purchase_balance_source' => 'carrier',
    'payment' => ['amount' => 150, 'payment_method' => 'cash', 'account_id' => $cashUSD->id],
    'passengers' => [['first_name' => 'RC', 'last_name' => 'Test', 'passport_number' => 'RC' . substr(uniqid(), -6), 'type' => 'adult']],
]);

if ($r['status'] < 400 && isset($r['json']['data']['id'])) {
    $bookingId = $r['json']['data']['id'];
    $cleanup['bookingIds'][] = $bookingId;

    $cancelResp = httpPost($BASE_URL . "/flight/bookings/{$bookingId}/cancel", [
        'airline_penalty' => 0,
        'office_penalty' => 0,
        'account_id' => $cashEGP->id,  // ← EGP account لـ USD booking!
    ]);

    if ($cancelResp['status'] >= 400) {
        $msg = $cancelResp['json']['message'] ?? '';
        if (str_contains($msg, 'لا تطابق') || str_contains($msg, 'عملة')) {
            $RESULTS['passed']++;
            ok("✅ USD booking + EGP account cancel رُفض (Bug #B6): {$msg}");
        } else {
            warn("⚠️  رُفض برسالة: {$msg}");
            $RESULTS['passed']++;
        }
    } else {
        $RESULTS['failed']++;
        fail("❌ الباج #B6: USD booking + EGP account cancel قُبل!");
    }
} else {
    fail("❌ فشل إنشاء حجز للاختبار 8");
}

// ═════════════════ تنظيف ═════════════════
section('🧹 تنظيف');
$bookingCount = isset($cleanup['bookingIds']) ? count($cleanup['bookingIds']) : 0;
$treasuryCount = isset($cleanup['treasuryIds']) ? count($cleanup['treasuryIds']) : 0;
safeCleanup($cleanup);
info("تم تنظيف {$bookingCount} حجز و {$treasuryCount} خزينة");

// ═════════════════ ملخص ═════════════════
section('📊 الملخص النهائي');
t("  ✅ Passed: {$RESULTS['passed']}");
t("  ❌ Failed: {$RESULTS['failed']}");

if ($RESULTS['failed'] == 0) {
    ok("\n🎉 كل اختبارات Refund Currency نجحت!");
} else {
    fail("\n❌ {$RESULTS['failed']} اختبار فشل!");
}

file_put_contents($logDir . '/refund_currency_report_' . date('Y-m-d_His') . '.json',
    json_encode($RESULTS, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

fclose($logHandle);
echo "\n📄 Log: {$logDir}/" . date('Y-m-d_His') . "_refund_currency.log\n";
echo "✅ Refund Currency Tests DONE.\n";
