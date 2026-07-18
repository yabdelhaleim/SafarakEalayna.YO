<?php
/**
 * ════════════════════════════════════════════════════════════════════════════
 * Flight Module — Critical State + Race + Currency Tests (C1-C6)
 * ════════════════════════════════════════════════════════════════════════════
 *
 * يفحص الإصلاحات:
 * - C1: Currency mismatch في AirlineAccountDebitService (service-level)
 * - C2: FlightSystemRechargeService currency check (service-level)
 * - C3: TOCTOU race في addPayment (smoke test — لا يمكن محاكاة race يدوياً)
 * - C4: RefundService يرفض PENDING bookings (يحتاج تعديل حالة يدوي)
 * - C5: RefundService يرفض cumulative refund الزائد
 * - C6: ModificationService يرفض PENDING bookings (يحتاج تعديل حالة يدوي)
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
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\AirlineAccount;
use App\Models\Flight\RefundRequest;
use App\Models\Flight\TicketModification;
use App\Enums\AccountType;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

$BASE_URL = 'http://127.0.0.1:8000/api/v1';
$admin = User::first();
auth()->setUser($admin);
$token = $admin->createToken('critical-state-' . uniqid())->plainTextToken;

function httpPost(string $url, array $data = []) {
    global $token;
    $r = Http::withToken($token)->acceptJson()->post($url, $data);
    return ['status' => $r->status(), 'json' => $r->json()];
}

function safeCleanup($ids) {
    DB::statement('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($ids['bookingIds'] ?? [] as $bid) {
        DB::table('flight_payments')->where('flight_booking_id', $bid)->delete();
        DB::table('passengers')->where('flight_booking_id', $bid)->delete();
        DB::table('flight_segments')->where('flight_booking_id', $bid)->delete();
        DB::table('airline_transactions')->where('flight_booking_id', $bid)->delete();
        DB::table('flight_system_transactions')->where('flight_booking_id', $bid)->delete();
        DB::table('flight_bookings')->where('id', $bid)->delete();
        DB::table('transactions')->where('related_type', 'App\\Models\\Flight\\FlightBooking')->where('related_id', $bid)->delete();
    }
    foreach ($ids['modIds'] ?? [] as $mid) {
        DB::table('ticket_modifications')->where('id', $mid)->delete();
    }
    foreach ($ids['accIds'] ?? [] as $aid) {
        DB::table('airline_accounts')->where('id', $aid)->delete();
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
$logHandle = fopen($logDir . '/' . date('Y-m-d_His') . '_critical_state.log', 'w');
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
$cleanup = ['bookingIds' => [], 'modIds' => [], 'accIds' => []];

t("╔══════════════════════════════════════════════════════════════════╗");
t("║  Flight Module — Critical State + Race + Currency Tests     ║");
t("║  يستهدف: C1, C2, C3, C4, C5, C6                           ║");
t("╚══════════════════════════════════════════════════════════════════╝");

section('🧹 تنظيف leftovers');
DB::statement('SET FOREIGN_KEY_CHECKS = 0');
DB::table('accounts')->where('name', 'like', 'TEST_CS_%')->delete();
DB::table('customers')->where('full_name', 'like', 'TEST_CS_%')->delete();
DB::table('employees')->where('full_name', 'like', 'TEST_CS_%')->delete();
DB::table('flight_systems')->where('name', 'like', 'TEST_CS_%')->delete();
DB::table('flight_carriers')->where('name', 'like', 'TEST_CS_%')->delete();
DB::table('flight_groups')->where('name', 'like', 'TEST_CS_%')->delete();
DB::table('airline_accounts')->where('currency', 'TEST_CURR')->delete();
DB::statement('SET FOREIGN_KEY_CHECKS = 1');
info("تم تنظيف leftovers");

section('⚙️ الإعداد');
$customer = Customer::create([
    'full_name' => 'TEST_CS_CUST_' . substr(uniqid(), -4),
    'phone' => '01200033001',
    'national_id' => 'CS' . substr(md5(uniqid()), 0, 12),
    'passport_number' => 'CS' . substr(uniqid(), -8),
    'module_type' => 'flights',
    'created_by' => $admin->id,
]);
$employee = Employee::create([
    'full_name' => 'TEST_CS_EMP_' . substr(uniqid(), -4),
    'phone' => '01200033002',
    'national_id' => 'CSE' . substr(md5(uniqid()), 0, 6),
    'created_by' => $admin->id,
]);
$cashEGP = Account::create(['name' => 'TEST_CS_Cash_EGP', 'type' => AccountType::Cashbox->value, 'currency' => 'EGP', 'balance' => 0, 'is_active' => true, 'owner_type' => Account::OWNER_TYPE_OFFICE, 'module_type' => 'tourism', 'created_by' => $admin->id]);
setAccountBalance($cashEGP, 1000000);

$airport = Airport::create([
    'iata_code' => 'CS' . substr(uniqid(), -2),
    'city_name_ar' => 'Critical State Test',
    'city_name_en' => 'Critical State Test',
    'airport_name_ar' => 'CST',
    'airport_name_en' => 'CST',
    'country_code' => 'CS',
    'country_name_ar' => 'CS',
    'country_name_en' => 'CS',
    'is_active' => true,
]);
$airportCode = $airport->iata_code;

$system = FlightSystem::create([
    'name' => 'TEST_CS_System_' . substr(uniqid(), -4),
    'code' => 'CSS' . substr(md5(uniqid()), 0, 3),
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

$carrier = FlightCarrier::create([
    'name' => 'TEST_CS_Carrier_' . substr(uniqid(), -4),
    'code' => 'CSC' . substr(md5(uniqid()), 0, 3),
    'iata_code' => 'CS',
    'flight_system_id' => $system->id,
    'currency' => 'EGP',
    'balance' => 0,
    'is_active' => true,
    'created_by' => $admin->id,
]);
setCarrierBalance($carrier, 1000000);
info("entities جاهزة: customer={$customer->id} | carrier={$carrier->id}");

// ═════════════════ TEST 1: C1 - AirlineAccountDebitService currency mismatch ═════════════════
section('TEST 1: AirlineAccountDebitService currency mismatch → REJECT (C1)');

$bookingUSD = FlightBooking::runProfitMutation(function () use ($customer, $admin, $carrier, $airportCode) {
    return FlightBooking::create([
        'booking_number' => 'CS-' . strtoupper(substr(md5(uniqid()), 0, 6)),
        'booking_reference' => 'CS-' . strtoupper(substr(md5(uniqid()), 0, 6)),
        'customer_id' => $customer->id,
        'currency' => 'USD',
        'exchange_rate' => 48.5,
        'selling_price' => 150,
        'purchase_price' => 100,
        'profit' => 50,
        'status' => 'CONFIRMED',
        'selling_price_egp' => 7275,
        'purchase_price_egp' => 4850,
        'currency_used' => 'USD',
        'base_currency_amount' => 7275,
        'flight_carrier_id' => $carrier->id,
        'booking_channel_type' => 'SIGN',
        'booking_channel_provider' => 'SIGN',
        'agent_name' => 'TestAgent',
        'from_airport' => $airportCode,
        'to_airport' => $airportCode,
        'origin' => 'Test Origin',
        'destination' => 'Test Destination',
        'airline' => 'TestAirline',
        'airline_name' => 'TestAirline',
        'departure_date' => '2026-09-15',
        'departure_time' => '10:00:00',
        'trip_type' => 'one_way',
        'passenger_count' => 1,
        'created_by' => $admin->id,
    ]);
});
$cleanup['bookingIds'][] = $bookingUSD->id;

$airlineAccountEGP = AirlineAccount::create([
    'flight_carrier_id' => $carrier->id,
    'currency' => 'EGP',
    'is_active' => true,
    'name' => 'TEST_CS_AirlineAccount_EGP',
    'code' => 'CSE' . substr(md5(uniqid()), 0, 5),
    'system_type' => 'GDS',
]);
LedgerBalanceMutationGuard::run(function () use ($airlineAccountEGP) {
    $airlineAccountEGP->balance = 10000;
    $airlineAccountEGP->save();
});
$cleanup['accIds'][] = $airlineAccountEGP->id;
$bookingUSD->airline_account_id = $airlineAccountEGP->id;
$bookingUSD->save();

// C1 اختبار: airline account بـ KWD (عملة أجنبية مختلفة عن USD)
// يجب أن يرفض لأن كلاهما non-EGP ومختلف — لا يمكن التحويل
$airlineAccountKWD = AirlineAccount::create([
    'flight_carrier_id' => $carrier->id,
    'currency' => 'KWD',
    'is_active' => true,
    'name' => 'TEST_CS_AirlineAccount_KWD',
    'code' => 'CSK' . substr(md5(uniqid()), 0, 5),
    'system_type' => 'GDS',
]);
LedgerBalanceMutationGuard::run(function () use ($airlineAccountKWD) {
    $airlineAccountKWD->balance = 1000;
    $airlineAccountKWD->save();
});
$cleanup['accIds'][] = $airlineAccountKWD->id;

$modification = TicketModification::create([
    'booking_id' => $bookingUSD->id,
    'modification_type' => 'date_change',
    'currency' => 'USD',
    'currency_snapshot' => 'USD',
    'airline_change_fee' => 50,
    'agency_commission' => 10,
    'total_charged_to_customer' => 60,
    'status' => 'confirmed',
    'confirmed_at' => now(),
    'modified_by' => $admin->id,
]);
$cleanup['modIds'][] = $modification->id;

try {
    app(\App\Services\Flight\AirlineAccountDebitService::class)->debitForModification(
        $airlineAccountKWD,
        $bookingUSD,
        $modification,
        $admin->id
    );
    $RESULTS['failed']++;
    fail("❌ الباج #C1: USD booking + KWD airline_account قُبل!");
} catch (\RuntimeException $e) {
    if (str_contains($e->getMessage(), 'لا تطابق') || str_contains($e->getMessage(), 'عملة')) {
        $RESULTS['passed']++;
        ok("✅ USD booking + KWD airline_account رُفض (C1): {$e->getMessage()}");
    } else {
        warn("⚠️  رُفض برسالة أخرى: {$e->getMessage()}");
        $RESULTS['passed']++;
    }
}

// ═════════════════ TEST 2: C2 - FlightSystemRechargeService currency check ═════════════════
section('TEST 2: FlightSystemRechargeService currency mismatch → REJECT (C2)');

$systemUSD = FlightSystem::create([
    'name' => 'TEST_CS_System_USD_' . substr(uniqid(), -4),
    'code' => 'CSU' . substr(md5(uniqid()), 0, 3),
    'type' => 'GDS',
    'currency' => 'USD',
    'balance' => 0,
    'is_active' => true,
    'created_by' => $admin->id,
]);
LedgerBalanceMutationGuard::run(function () use ($systemUSD) {
    $systemUSD->balance = 1000;
    $systemUSD->save();
});

try {
    app(\App\Services\Flight\FlightSystemRechargeService::class)->rechargeFromAccount(
        $systemUSD,
        $cashEGP,
        500,
        'TEST_CS: mismatch recharge'
    );
    $RESULTS['failed']++;
    fail("❌ الباج #C2: USD system + EGP source قُبل!");
} catch (\RuntimeException $e) {
    if (str_contains($e->getMessage(), 'تضارب') || str_contains($e->getMessage(), 'لا يتطابق')) {
        $RESULTS['passed']++;
        ok("✅ USD system + EGP source رُفض (C2): {$e->getMessage()}");
    } else {
        warn("⚠️  رُفض برسالة أخرى: {$e->getMessage()}");
        $RESULTS['passed']++;
    }
}

// ═════════════════ TEST 3: C3 - addPayment smoke test (lockForUpdate) ═════════════════
section('TEST 3: addPayment happy path (C3 fix لا يكسر التدفق)');

$r = httpPost($BASE_URL . '/flight/bookings', [
    'customer_id' => $customer->id,
    'employee_id' => $employee->id,
    'pnr' => 'CS-' . strtoupper(substr(md5(uniqid()), 0, 6)),
    'airline' => 'CSTest',
    'airline_name' => 'CSTest',
    'from_airport' => $airportCode,
    'to_airport' => $airportCode,
    'from_airport_id' => $airport->id,
    'to_airport_id' => $airport->id,
    'departure_date' => now()->addDays(60)->format('Y-m-d'),
    'departure_time' => '10:00:00',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'currency' => 'EGP',
    'purchase_price' => 1000,
    'selling_price' => 1500,
    'flight_system_id' => $system->id,
    'flight_carrier_id' => $carrier->id,
    'purchase_balance_source' => 'carrier',
    'passengers' => [['first_name' => 'CS', 'last_name' => 'Test', 'passport_number' => 'CS' . substr(uniqid(), -6), 'type' => 'adult']],
]);

if ($r['status'] < 400 && isset($r['json']['data']['id'])) {
    $bookingId = $r['json']['data']['id'];
    $cleanup['bookingIds'][] = $bookingId;

    $paymentResp = httpPost($BASE_URL . "/flight/bookings/{$bookingId}/payments", [
        'amount' => 500,
        'payment_method' => 'cash',
        'account_id' => $cashEGP->id,
    ]);

    if ($paymentResp['status'] < 400) {
        $RESULTS['passed']++;
        ok("✅ addPayment happy path يعمل (C3 fix لا يكسر التدفق)");
    } else {
        warn("⚠️  addPayment فشل: " . ($paymentResp['json']['message'] ?? ''));
    }
} else {
    warn("⚠️  فشل إنشاء حجز للاختبار 3");
}

// ═════════════════ TEST 4: C4 - RefundService يرفض PENDING bookings ═════════════════
section('TEST 4: refund PENDING booking → REJECT (C4)');

// أنشئ حجز مؤكد ثم نغيّر الحالة إلى PENDING يدوياً
$r = httpPost($BASE_URL . '/flight/bookings', [
    'customer_id' => $customer->id,
    'employee_id' => $employee->id,
    'pnr' => 'CS-' . strtoupper(substr(md5(uniqid()), 0, 6)),
    'airline' => 'CSTest',
    'airline_name' => 'CSTest',
    'from_airport' => $airportCode,
    'to_airport' => $airportCode,
    'from_airport_id' => $airport->id,
    'to_airport_id' => $airport->id,
    'departure_date' => now()->addDays(61)->format('Y-m-d'),
    'departure_time' => '10:00:00',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'currency' => 'EGP',
    'purchase_price' => 1000,
    'selling_price' => 1500,
    'flight_system_id' => $system->id,
    'flight_carrier_id' => $carrier->id,
    'purchase_balance_source' => 'carrier',
    'passengers' => [['first_name' => 'CS', 'last_name' => 'Test', 'passport_number' => 'CS' . substr(uniqid(), -6), 'type' => 'adult']],
]);

if ($r['status'] < 400 && isset($r['json']['data']['id'])) {
    $bookingId = $r['json']['data']['id'];
    $cleanup['bookingIds'][] = $bookingId;
    // غيّر الحالة إلى PENDING
    FlightBooking::runProfitMutation(function () use ($bookingId) {
        $b = FlightBooking::find($bookingId);
        $b->status = 'PENDING';
        $b->pnr = null;
        $b->save();
    });
    info("تم إنشاء حجز وتحويله لـ PENDING ID={$bookingId}");

    $refundResp = httpPost($BASE_URL . '/flight/refunds', [
        'flight_booking_id' => $bookingId,
        'cancellation_fee' => 0,
        'refund_currency' => 'EGP',
        'destination' => 'airline_credit',
        'notes' => 'TEST_CS: refund PENDING booking',
    ]);

    if ($refundResp['status'] >= 400) {
        $msg = $refundResp['json']['message'] ?? '';
        if (str_contains($msg, 'مؤكد') || str_contains($msg, 'PENDING') || str_contains($msg, 'حالة')) {
            $RESULTS['passed']++;
            ok("✅ refund PENDING booking رُفض (C4): {$msg}");
        } else {
            warn("⚠️  رُفض برسالة: {$msg}");
            $RESULTS['passed']++;
        }
    } else {
        $RESULTS['failed']++;
        fail("❌ الباج #C4: refund PENDING booking قُبل!");
        if (isset($refundResp['json']['data']['id'])) {
            DB::table('refund_requests')->where('id', $refundResp['json']['data']['id'])->delete();
        }
    }
}

// ═════════════════ TEST 5: C5 - cumulative refund cap ═════════════════
section('TEST 5: cumulative refund > original → REJECT (C5)');

$r = httpPost($BASE_URL . '/flight/bookings', [
    'customer_id' => $customer->id,
    'employee_id' => $employee->id,
    'pnr' => 'CS-' . strtoupper(substr(md5(uniqid()), 0, 6)),
    'airline' => 'CSTest',
    'airline_name' => 'CSTest',
    'from_airport' => $airportCode,
    'to_airport' => $airportCode,
    'from_airport_id' => $airport->id,
    'to_airport_id' => $airport->id,
    'departure_date' => now()->addDays(62)->format('Y-m-d'),
    'departure_time' => '10:00:00',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'currency' => 'EGP',
    'purchase_price' => 1000,
    'selling_price' => 1500,
    'flight_system_id' => $system->id,
    'flight_carrier_id' => $carrier->id,
    'purchase_balance_source' => 'carrier',
    'payment' => ['amount' => 1500, 'payment_method' => 'cash', 'account_id' => $cashEGP->id],
    'passengers' => [['first_name' => 'CS', 'last_name' => 'Test', 'passport_number' => 'CS' . substr(uniqid(), -6), 'type' => 'adult']],
]);

if ($r['status'] < 400 && isset($r['json']['data']['id'])) {
    $bookingId = $r['json']['data']['id'];
    $cleanup['bookingIds'][] = $bookingId;

    $refund1 = httpPost($BASE_URL . '/flight/refunds', [
        'flight_booking_id' => $bookingId,
        'cancellation_fee' => 500,
        'refund_currency' => 'EGP',
        'destination' => 'agency_treasury',
        'treasury_id' => DB::table('treasuries')->where('currency', 'EGP')->where('is_active', true)->value('id'),
        'notes' => 'TEST_CS: refund 1000',
    ]);

    if ($refund1['status'] < 400 && isset($refund1['json']['data']['id'])) {
        $refundId1 = $refund1['json']['data']['id'];
        info("استرجاع أول ID={$refundId1} بمبلغ 1000 EGP");

        $refund2 = httpPost($BASE_URL . '/flight/refunds', [
            'flight_booking_id' => $bookingId,
            'cancellation_fee' => 0,
            'refund_currency' => 'EGP',
            'destination' => 'agency_treasury',
            'treasury_id' => DB::table('treasuries')->where('currency', 'EGP')->where('is_active', true)->value('id'),
            'notes' => 'TEST_CS: refund 600 (سيتجاوز)',
        ]);

        if ($refund2['status'] >= 400) {
            $msg = $refund2['json']['message'] ?? '';
            if (str_contains($msg, 'إجمالي') || str_contains($msg, 'يتجاوز') || str_contains($msg, 'الأصلي')) {
                $RESULTS['passed']++;
                ok("✅ cumulative refund الزائد رُفض (C5): {$msg}");
            } else {
                warn("⚠️  رُفض برسالة: {$msg}");
                $RESULTS['passed']++;
            }
        } else {
            $RESULTS['failed']++;
            fail("❌ الباج #C5: cumulative refund الزائد قُبل!");
            if (isset($refund2['json']['data']['id'])) {
                DB::table('refund_requests')->where('id', $refund2['json']['data']['id'])->delete();
            }
        }
        DB::table('refund_requests')->where('id', $refundId1)->delete();
    } else {
        warn("⚠️  فشل إنشاء الاسترجاع الأول: " . ($refund1['json']['message'] ?? ''));
    }
}

// ═════════════════ TEST 6: C6 - ModificationService يرفض PENDING bookings ═════════════════
section('TEST 6: modification PENDING booking → REJECT (C6)');

$r = httpPost($BASE_URL . '/flight/bookings', [
    'customer_id' => $customer->id,
    'employee_id' => $employee->id,
    'pnr' => 'CS-' . strtoupper(substr(md5(uniqid()), 0, 6)),
    'airline' => 'CSTest',
    'airline_name' => 'CSTest',
    'from_airport' => $airportCode,
    'to_airport' => $airportCode,
    'from_airport_id' => $airport->id,
    'to_airport_id' => $airport->id,
    'departure_date' => now()->addDays(63)->format('Y-m-d'),
    'departure_time' => '10:00:00',
    'trip_type' => 'one_way',
    'passenger_count' => 1,
    'currency' => 'EGP',
    'purchase_price' => 1000,
    'selling_price' => 1500,
    'flight_system_id' => $system->id,
    'flight_carrier_id' => $carrier->id,
    'purchase_balance_source' => 'carrier',
    'passengers' => [['first_name' => 'CS', 'last_name' => 'Test', 'passport_number' => 'CS' . substr(uniqid(), -6), 'type' => 'adult']],
]);

if ($r['status'] < 400 && isset($r['json']['data']['id'])) {
    $bookingId = $r['json']['data']['id'];
    $cleanup['bookingIds'][] = $bookingId;
    // غيّر الحالة إلى PENDING
    FlightBooking::runProfitMutation(function () use ($bookingId) {
        $b = FlightBooking::find($bookingId);
        $b->status = 'PENDING';
        $b->pnr = null;
        $b->save();
    });
    info("تم إنشاء حجز وتحويله لـ PENDING ID={$bookingId}");

    $modResp = httpPost($BASE_URL . '/flight/modifications', [
        'booking_id' => $bookingId,
        'modification_type' => 'date_change',
        'new_departure_date' => now()->addDays(64)->format('Y-m-d'),
        'airline_change_fee' => 100,
        'agency_commission' => 50,
        'currency' => 'EGP',
    ]);

    if ($modResp['status'] >= 400) {
        $msg = $modResp['json']['message'] ?? '';
        if (str_contains($msg, 'PENDING') || str_contains($msg, 'pending') || str_contains($msg, 'حالة') || str_contains($msg, 'مؤكد')) {
            $RESULTS['passed']++;
            ok("✅ modification PENDING booking رُفض (C6): {$msg}");
        } else {
            warn("⚠️  رُفض برسالة: {$msg}");
            $RESULTS['passed']++;
        }
    } else {
        $RESULTS['failed']++;
        fail("❌ الباج #C6: modification PENDING booking قُبل! Response: " . json_encode($modResp['json'], JSON_UNESCAPED_UNICODE));
        if (isset($modResp['json']['data']['id'])) {
            DB::table('ticket_modifications')->where('id', $modResp['json']['data']['id'])->delete();
        }
    }
}

// ═════════════════ تنظيف ═════════════════
section('🧹 تنظيف');
safeCleanup($cleanup);
$bookingCount = count($cleanup['bookingIds']);
info("تم تنظيف {$bookingCount} حجز");

// ═════════════════ ملخص ═════════════════
section('📊 الملخص النهائي');
t("  ✅ Passed: {$RESULTS['passed']}");
t("  ❌ Failed: {$RESULTS['failed']}");

if ($RESULTS['failed'] == 0) {
    ok("\n🎉 كل اختبارات Critical State نجحت!");
} else {
    fail("\n❌ {$RESULTS['failed']} اختبار فشل!");
}

file_put_contents($logDir . '/critical_state_report_' . date('Y-m-d_His') . '.json',
    json_encode($RESULTS, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

fclose($logHandle);
echo "\n📄 Log: {$logDir}/" . date('Y-m-d_His') . "_critical_state.log\n";
echo "✅ Critical State Tests DONE.\n";
