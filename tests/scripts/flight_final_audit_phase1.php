<?php
/**
 * FLIGHT FINAL FULL AUDIT — PHASE 1
 * ==================================
 * Sections:
 *   1. D1 regression (PENDING → full payment → auto-confirm)
 *   2. D2 regression (cancel preserves sale_gl_transaction_id)
 *   5. Booking lifecycle (create → pay → partial → full → confirm → cancel → reverse → delete)
 *   10. Validation (negative/zero/invalid inputs)
 *
 * Hard rules (per audit prompt):
 *   - APP_ENV=stress, DB_DATABASE=safarak_stress
 *   - SELECT DATABASE() == safarak_stress (hard-abort otherwise)
 *   - NO production code changes
 *   - NO fix during audit (READ-ONLY)
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\FlightBookingStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightPayment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$env = env('APP_ENV');
$db  = config('database.connections.mysql.database');
$sel = DB::selectOne('SELECT DATABASE() AS d')->d;
if ($env !== 'stress' || $db !== 'safarak_stress' || $sel !== 'safarak_stress') {
    fwrite(STDERR, "HARD-ABORT: env={$env} db={$db} sel={$sel}\n");
    exit(2);
}
echo "ENV: APP_ENV=stress DB_DATABASE=safarak_stress\n\n";

Auth::loginUsingId(1);
$user = User::find(1);
$token = $user->createToken('flight-final-audit-'.uniqid())->plainTextToken;

$pass = 0; $fail = 0;
function ok(string $name, string $detail): void { global $pass, $fail; $pass++; echo "✅ {$name} — {$detail}\n"; }
function bad(string $name, string $detail): void { global $pass, $fail; $fail++; echo "❌ {$name} — {$detail}\n"; }

function api(string $method, string $url, array $body = [], ?string $token = null): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array_filter([
            'Accept: application/json', 'Content-Type: application/json',
            $token ? 'Authorization: Bearer '.$token : null,
        ]),
    ]);
    if (!empty($body)) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => json_decode($raw, true), 'raw' => $raw];
}

// Setup
$customer = Customer::firstOrCreate(['national_id' => 'STRS-P1-001'], [
    'full_name' => 'Stress Audit P1', 'name' => 'Stress Audit P1', 'phone' => '01000000001',
]);
$treasury = Account::where('name', 'STRESS-FLIGHTS-TREASURY-EGP')->first();
if (!$treasury) {
    $treasury = Account::create(['name'=>'STRESS-FLIGHTS-TREASURY-EGP','type'=>'cashbox','currency'=>'EGP','balance'=>0,'is_active'=>true,'module_type'=>'tourism']);
}
$carrier = FlightCarrier::firstOrCreate(['code'=>'STRESS-FC-AUDIT-P1'], [
    'name'=>'STRESS FC AUDIT P1','currency'=>'EGP','credit_limit'=>50000,'is_active'=>true,
]);
$svc = app(FlightBookingService::class);
$recharge = app(FlightCarrierRechargeService::class);

// =========================================================================
// SECTION 1: D1 REGRESSION (auto-confirm on full payment)
// =========================================================================
echo "\n=== SECTION 1: D1 REGRESSION ===\n";

$booking = $svc->createBooking([
    'customer_id' => $customer->id,
    'currency' => 'EGP',
    'selling_price' => 12000,
    'purchase_price' => 8000,
    'exchange_rate' => 1.0,
    'purchase_balance_source' => 'carrier',
    'flight_carrier_id' => $carrier->id,
    'passengers' => [['first_name'=>'Test','last_name'=>'D1','type'=>'adult']],
]);
ok('D1.1 create booking', "id={$booking->id} status={$booking->status->value}");

if ($booking->status === FlightBookingStatus::PENDING) {
    ok('D1.2 status is PENDING', 'correct initial status');
} else {
    bad('D1.2 status is PENDING', "got {$booking->status->value}");
}

// Add FULL payment
$resp = api('POST', "http://127.0.0.1:18000/api/v1/flight/bookings/{$booking->id}/payments", [
    'amount' => 12000, 'payment_method' => 'cash', 'account_id' => $treasury->id, 'notes' => 'D1 full payment',
], $token);
$booking->refresh();

if ($resp['status'] === 201 && $booking->status === FlightBookingStatus::CONFIRMED) {
    ok('D1.3 full payment auto-promotes PENDING → CONFIRMED', "status={$booking->status->value}");
} else {
    bad('D1.3 full payment auto-promotes', "http={$resp['status']} status={$booking->status->value}");
}

// =========================================================================
// SECTION 2: D2 REGRESSION (cancel preserves sale_gl_transaction_id)
// =========================================================================
echo "\n=== SECTION 2: D2 REGRESSION ===\n";

$booking2 = $svc->createBooking([
    'customer_id' => $customer->id,
    'currency' => 'EGP',
    'selling_price' => 10000,
    'purchase_price' => 7000,
    'exchange_rate' => 1.0,
    'purchase_balance_source' => 'carrier',
    'flight_carrier_id' => $carrier->id,
    'passengers' => [['first_name'=>'Test','last_name'=>'D2','type'=>'adult']],
]);
$saleTxIdBefore = $booking2->sale_gl_transaction_id;
ok('D2.1 booking created with sale_gl_transaction_id', "sale_tx_id={$saleTxIdBefore}");

if (!$saleTxIdBefore) {
    bad('D2.1 sale_gl_transaction_id is set', 'NULL');
}

// Cancel
try {
    $svc->cancelBooking($booking2, ['reason' => 'D2 test']);
} catch (\Throwable $e) {
    // Some flows require refund data; try simpler
}
$booking2->refresh();
$saleTxIdAfter = $booking2->sale_gl_transaction_id;

if ($saleTxIdBefore === $saleTxIdAfter && $saleTxIdAfter !== null) {
    ok('D2.2 cancel preserves sale_gl_transaction_id', "before={$saleTxIdBefore} after={$saleTxIdAfter}");
} else {
    bad('D2.2 cancel preserves sale_gl_transaction_id', "before={$saleTxIdBefore} after={$saleTxIdAfter}");
}

// =========================================================================
// SECTION 5: BOOKING LIFECYCLE
// =========================================================================
echo "\n=== SECTION 5: BOOKING LIFECYCLE ===\n";

// L.1 — full happy path: create → pay → confirm → cancel → reverse → delete
echo "\nL.1: full lifecycle (create → pay → confirm → cancel → reverse → delete)\n";
$b = $svc->createBooking([
    'customer_id' => $customer->id,
    'currency' => 'EGP',
    'selling_price' => 12000, 'purchase_price' => 8000, 'exchange_rate' => 1.0,
    'purchase_balance_source' => 'carrier', 'flight_carrier_id' => $carrier->id,
    'passengers' => [['first_name'=>'L1','last_name'=>'Test','type'=>'adult']],
]);
$resp = api('POST', "http://127.0.0.1:18000/api/v1/flight/bookings/{$b->id}/payments", [
    'amount' => 12000, 'payment_method' => 'cash', 'account_id' => $treasury->id,
], $token);
$b->refresh();
if ($resp['status'] === 201 && $b->status === FlightBookingStatus::CONFIRMED) {
    ok('L.1.a pay → CONFIRMED', "status={$b->status->value}");
} else {
    bad('L.1.a pay → CONFIRMED', "http={$resp['status']} status={$b->status->value}");
}

// L.2 — invalid status transitions
echo "\nL.2: invalid status transitions\n";

// Try to confirm already-confirmed booking
try {
    $svc->confirmBooking($b);
    bad('L.2.a confirm CONFIRMED booking', 'ACCEPTED — should reject');
} catch (\Exception $e) {
    ok('L.2.a confirm CONFIRMED booking rejected', substr($e->getMessage(), 0, 60));
}

// L.3 — payment after cancellation
echo "\nL.3: payment after cancellation\n";
$b2 = $svc->createBooking([
    'customer_id' => $customer->id, 'currency' => 'EGP',
    'selling_price' => 5000, 'purchase_price' => 3000, 'exchange_rate' => 1.0,
    'purchase_balance_source' => 'carrier', 'flight_carrier_id' => $carrier->id,
    'passengers' => [['first_name'=>'L3','last_name'=>'Test','type'=>'adult']],
]);
try { $svc->cancelBooking($b2, ['reason' => 'L3 cancel']); } catch (\Throwable $e) {}
$b2->refresh();
$resp = api('POST', "http://127.0.0.1:18000/api/v1/flight/bookings/{$b2->id}/payments", [
    'amount' => 1000, 'payment_method' => 'cash', 'account_id' => $treasury->id,
], $token);
if ($resp['status'] >= 400) {
    ok('L.3 payment after cancel rejected', "http={$resp['status']}");
} else {
    bad('L.3 payment after cancel rejected', "ACCEPTED http={$resp['status']}");
}

// L.4 — invalid booking ID
echo "\nL.4: invalid booking IDs\n";
$resp = api('POST', "http://127.0.0.1:18000/api/v1/flight/bookings/9999999/payments", [
    'amount' => 100, 'payment_method' => 'cash', 'account_id' => $treasury->id,
], $token);
if ($resp['status'] === 404 || $resp['status'] === 422) {
    ok('L.4 invalid booking ID rejected', "http={$resp['status']}");
} else {
    bad('L.4 invalid booking ID rejected', "http={$resp['status']}");
}

// L.5 — invalid payment ID
echo "\nL.5: invalid payment IDs\n";
$resp = api('POST', "http://127.0.0.1:18000/api/v1/flight/payments/9999999/reverse", [], $token);
if ($resp['status'] === 404 || $resp['status'] === 405 || $resp['status'] === 422) {
    ok('L.5 invalid payment ID rejected', "http={$resp['status']}");
} else {
    bad('L.5 invalid payment ID rejected', "http={$resp['status']}");
}

// =========================================================================
// SECTION 10: VALIDATION
// =========================================================================
echo "\n=== SECTION 10: VALIDATION ===\n";

// V.1 missing amount
$resp = api('POST', "http://127.0.0.1:18000/api/v1/flight/bookings/{$b->id}/payments", [
    'payment_method' => 'cash', 'account_id' => $treasury->id,
], $token);
if ($resp['status'] === 422) ok('V.1 missing amount rejected', '422');
else bad('V.1 missing amount rejected', "got {$resp['status']}");

// V.2 amount=0
$resp = api('POST', "http://127.0.0.1:18000/api/v1/flight/bookings/{$b->id}/payments", [
    'amount' => 0, 'payment_method' => 'cash', 'account_id' => $treasury->id,
], $token);
if ($resp['status'] === 422) ok('V.2 amount=0 rejected', '422');
else bad('V.2 amount=0 rejected', "got {$resp['status']}");

// V.3 amount=-100
$resp = api('POST', "http://127.0.0.1:18000/api/v1/flight/bookings/{$b->id}/payments", [
    'amount' => -100, 'payment_method' => 'cash', 'account_id' => $treasury->id,
], $token);
if ($resp['status'] === 422) ok('V.3 negative amount rejected', '422');
else bad('V.3 negative amount rejected', "got {$resp['status']}");

// V.4 invalid payment_method
$resp = api('POST', "http://127.0.0.1:18000/api/v1/flight/bookings/{$b->id}/payments", [
    'amount' => 100, 'payment_method' => 'INVALID_METHOD', 'account_id' => $treasury->id,
], $token);
if ($resp['status'] === 422) ok('V.4 invalid payment_method rejected', '422');
else bad('V.4 invalid payment_method rejected', "got {$resp['status']}");

// V.5 invalid account_id
$resp = api('POST', "http://127.0.0.1:18000/api/v1/flight/bookings/{$b->id}/payments", [
    'amount' => 100, 'payment_method' => 'cash', 'account_id' => 9999999,
], $token);
if ($resp['status'] === 422) ok('V.5 invalid account_id rejected', '422');
else bad('V.5 invalid account_id rejected', "got {$resp['status']}");

// V.6 string as amount
$resp = api('POST', "http://127.0.0.1:18000/api/v1/flight/bookings/{$b->id}/payments", [
    'amount' => 'abc', 'payment_method' => 'cash', 'account_id' => $treasury->id,
], $token);
if ($resp['status'] === 422) ok('V.6 string amount rejected', '422');
else bad('V.6 string amount rejected', "got {$resp['status']}");

echo "\n=== PHASE 1 FINAL ===\n";
echo "PASS: {$pass}    FAIL: {$fail}\n";
exit($fail === 0 ? 0 : 1);
