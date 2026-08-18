<?php
/**
 * FLIGHT D4/D3/D5 REMEDIATION — REGRESSION + CONCURRENCY + ROLLBACK TESTS.
 *
 * Requires:
 *   - APP_ENV=stress
 *   - DB_DATABASE=safarak_stress
 *   - Server running on 127.0.0.1:18000 with --env=stress
 *
 * Tests:
 *   D4 — negative/zero prices rejected
 *   D3 — partial-payment lifecycle works + idempotency contract
 *   D5 — inactive carrier recharge rejected
 *   Concurrency — curl_multi on payments (same key, distinct keys) + recharge (inactive vs active)
 *   Rollback — failed payment leaves no artifacts
 *
 * Hard rules (verbatim):
 *   - DO NOT modify production code (read-only on app/)
 *   - Use ONLY safarak_stress
 *   - Verify SELECT DATABASE() == safarak_stress before any write
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Exceptions\InactiveFlightCarrierException;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\AirlineTransaction;
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

$pass = 0; $fail = 0;
function ok(string $name, string $detail): void { global $pass, $fail; $pass++; echo "✅ {$name} — {$detail}\n"; }
function bad(string $name, string $detail): void { global $pass, $fail; $fail++; echo "❌ {$name} — {$detail}\n"; }

// =========================================================================
// Setup: get a Sanctum token and seed a customer + carrier + booking
// =========================================================================
Auth::loginUsingId(1);
$user = User::find(1);
$token = $user->createToken('flight-remediation-'.uniqid())->plainTextToken;
echo "Auth: token acquired (user_id=1)\n\n";

// Helper: API call
function api(string $method, string $url, array $body = [], ?string $token = null): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => array_filter([
            'Accept: application/json',
            'Content-Type: application/json',
            $token ? 'Authorization: Bearer '.$token : null,
        ]),
    ]);
    if (!empty($body)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($raw, true);
    return ['status' => $status, 'body' => $json, 'raw' => $raw];
}

// Helper: concurrent curl_multi
function fireConcurrent(string $method, string $url, array $payload, int $workers, string $token, bool $varyKey = false): array {
    $mh = curl_multi_init();
    $handles = [];
    for ($i = 0; $i < $workers; $i++) {
        $ch = curl_init();
        $body = $payload;
        if ($varyKey && isset($body['idempotency_key'])) {
            // Replace the key with a unique per-worker value
            $body['idempotency_key'] = $body['idempotency_key'].'-w'.$i;
        }
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer '.$token],
            CURLOPT_POSTFIELDS     => json_encode($body),
        ]);
        $handles[$i] = $ch;
        curl_multi_add_handle($mh, $ch);
    }
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 0.05);
    } while ($running && $status === CURLM_OK);
    $results = [];
    foreach ($handles as $i => $ch) {
        $raw = curl_multi_getcontent($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $results[$i] = ['status' => $code, 'raw' => $raw];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $results;
}

// Seed customer (use national_id as the lookup key; phone is required)
$customer = Customer::firstOrCreate(
    ['national_id' => 'STRESS-REM-001'],
    ['full_name' => 'Stress Customer REM', 'name' => 'Stress Customer REM', 'phone' => '01000000000']
);

// Seed EGP treasury account (use existing if available)
$treasury = Account::where('name', 'STRESS-FLIGHTS-TREASURY-EGP')->first();
if (!$treasury) {
    $treasury = Account::create([
        'name' => 'STRESS-FLIGHTS-TREASURY-EGP',
        'type' => 'cashbox',
        'currency' => 'EGP',
        'balance' => 0,
        'is_active' => true,
        'module_type' => 'tourism',
    ]);
}
// Make sure treasury has funds via canonical funding path
$existingFund = Transaction::where('to_account_id', $treasury->id)->where('notes', 'LIKE', 'AUDIT fixture seed: fund flights EGP treasury%')->exists();
if (!$existingFund && (float) $treasury->balance < 100000) {
    $funder = Account::where('module_type', 'tourism')->where('type', 'cashbox')->where('balance', '>=', 100000)->first();
    if ($funder) {
        app(\App\Services\Finance\TransactionService::class)->recordJournalTransfer([
            'from_account_id' => $funder->id,
            'to_account_id' => $treasury->id,
            'amount' => 100000,
            'currency' => 'EGP',
            'module' => 'flight',
            'related_type' => null,
            'related_id' => null,
            'notes' => 'AUDIT fixture seed: fund flights EGP treasury',
        ]);
    }
}

// Seed EGP carrier (active) for D4 booking creation
$carrier = FlightCarrier::firstOrCreate(
    ['code' => 'STRESS-FC-REM-EGP'],
    ['name' => 'STRESS FC REM EGP', 'currency' => 'EGP', 'credit_limit' => 50000, 'is_active' => true]
);
$carrier->refresh();
// Make sure carrier has funds
$recharge = app(FlightCarrierRechargeService::class);
try {
    $recharge->rechargeFromAccount($carrier, $treasury, 10000, 'AUDIT fixture seed: fund REM carrier');
} catch (\Throwable $e) {
    // may already be funded
}

// Seed an INACTIVE carrier for D5
$inactiveCarrier = FlightCarrier::firstOrCreate(
    ['code' => 'STRESS-FC-REM-INACTIVE'],
    ['name' => 'INACTIVE REM', 'currency' => 'EGP', 'credit_limit' => 0, 'is_active' => false]
);

// Create a fresh booking for D4 + D3 tests
$svc = app(FlightBookingService::class);
echo "Creating fresh booking for tests...\n";
$bookingData = [
    'customer_id' => $customer->id,
    'currency' => 'EGP',
    'selling_price' => 12000,
    'purchase_price' => 8000,
    'exchange_rate' => 1.0,
    'purchase_balance_source' => 'carrier',
    'flight_carrier_id' => $carrier->id,
    'passengers' => [
        ['first_name' => 'Test', 'last_name' => 'REM', 'type' => 'adult'],
    ],
];
$booking = null;
try {
    $booking = $svc->createBooking($bookingData);
    echo "  ✅ Booking #{$booking->id} created: selling=12000 purchase=8000\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "FATAL: could not create booking — {$e->getMessage()}\n");
    exit(3);
}

// =========================================================================
// D4 — NEGATIVE/ZERO PRICE REJECTION
// =========================================================================
echo "\n=== D4 — NEGATIVE/ZERO PRICE REJECTION ===\n";

// Capture carrier balance BEFORE any D4 attempts so we can verify NO mutation.
$balanceBeforeD4 = (float) $carrier->balance;

// D4.1 purchase=-100 via service (bypasses HTTP FormRequest)
echo "\nD4.1: purchase=-100 via direct service call\n";
try {
    $svc->updatePrices($booking->fresh(), -100, 1000);
    bad('D4.1 service: negative purchase', 'ACCEPTED — guard failed');
} catch (\InvalidArgumentException $e) {
    ok('D4.1 service: negative purchase', 'rejected: '.substr($e->getMessage(), 0, 80));
}

// D4.2 purchase=-100 via HTTP
echo "\nD4.2: purchase=-100 via HTTP FormRequest\n";
$resp = api('POST', "http://127.0.0.1:18000/api/v1/flight/bookings/{$booking->id}/prices",
    ['purchase_price' => -100, 'selling_price' => 1000], $token);
if ($resp['status'] === 422) {
    ok('D4.2 HTTP: negative purchase', 'rejected with 422');
} else {
    bad('D4.2 HTTP: negative purchase', "got {$resp['status']}: ".substr($resp['raw'], 0, 100));
}

// D4.3 selling=-500 via service
echo "\nD4.3: selling=-500 via direct service call\n";
try {
    $svc->updatePrices($booking->fresh(), 1000, -500);
    bad('D4.3 service: negative selling', 'ACCEPTED — guard failed');
} catch (\InvalidArgumentException $e) {
    ok('D4.3 service: negative selling', 'rejected: '.substr($e->getMessage(), 0, 80));
}

// D4.4 selling=-500 via HTTP
echo "\nD4.4: selling=-500 via HTTP FormRequest\n";
$resp = api('POST', "http://127.0.0.1:18000/api/v1/flight/bookings/{$booking->id}/prices",
    ['purchase_price' => 1000, 'selling_price' => -500], $token);
if ($resp['status'] === 422) {
    ok('D4.4 HTTP: negative selling', 'rejected with 422');
} else {
    bad('D4.4 HTTP: negative selling', "got {$resp['status']}");
}

// D4.5 purchase=0 (zero is allowed per spec)
echo "\nD4.5: purchase=0 via direct service call (zero allowed per spec)\n";
try {
    $svc->updatePrices($booking->fresh(), 0, 1000);
    $booking->refresh();
    if ((float)$booking->purchase_price === 0.0) {
        ok('D4.5 service: zero purchase', 'accepted, persisted purchase_price=0');
    } else {
        bad('D4.5 service: zero purchase', 'accepted but persisted wrong value');
    }
} catch (\Throwable $e) {
    bad('D4.5 service: zero purchase', 'unexpectedly rejected: '.$e->getMessage());
}

// D4.6 verify carrier balance UNCHANGED by the REJECTED attempts (D4.1-D4.4).
// Capture a fresh baseline AFTER D4.5 since D4.5 intentionally mutates the
// balance (purchase 8000 → 0 credits the carrier by 8000 as a legitimate refund).
$balanceAfterD5 = (float) $carrier->fresh()->balance;
echo "\nD4.6: D4.1-D4.4 must NOT mutate balance (zero-purchase D4.5 already mutated, so we measure deltas around D4.5)\n";

// Reset booking purchase back to 8000 to verify subsequent rejected attempts don't move the balance.
try {
    $svc->updatePrices($booking->fresh(), 8000, 1000);
} catch (\Throwable $e) {}
$balanceBeforeReset = (float) $carrier->fresh()->balance;

// Now retry D4.1-D4.4 against the reset state
try { $svc->updatePrices($booking->fresh(), -100, 1000); } catch (\InvalidArgumentException $e) {}
api('POST', "http://127.0.0.1:18000/api/v1/flight/bookings/{$booking->id}/prices", ['purchase_price' => -100, 'selling_price' => 1000], $token);
try { $svc->updatePrices($booking->fresh(), 1000, -500); } catch (\InvalidArgumentException $e) {}
api('POST', "http://127.0.0.1:18000/api/v1/flight/bookings/{$booking->id}/prices", ['purchase_price' => 1000, 'selling_price' => -500], $token);

$balanceAfter = (float) $carrier->fresh()->balance;
if (abs($balanceAfter - $balanceBeforeReset) < 0.02) {
    ok('D4.6 carrier balance unchanged by rejected attempts', "balance={$balanceAfter} (Δ=".($balanceAfter - $balanceBeforeReset).")");
} else {
    bad('D4.6 carrier balance unchanged by rejected attempts', "balance={$balanceAfter} expected={$balanceBeforeReset}");
}

// =========================================================================
// D3 — PARTIAL PAYMENT LIFECYCLE + IDEMPOTENCY
// =========================================================================
echo "\n=== D3 — PARTIAL PAYMENT LIFECYCLE + IDEMPOTENCY ===\n";

// Reset booking to a fresh state with selling=12000
$svc->updatePrices($booking->fresh(), 8000, 12000);
$booking->refresh();

$paymentUrl = "http://127.0.0.1:18000/api/v1/flight/bookings/{$booking->id}/payments";

// D3.A single full payment (regression: existing happy path)
echo "\nD3.A: single full payment 12000 (regression)\n";
$resp = api('POST', $paymentUrl, [
    'amount' => 12000,
    'payment_method' => 'cash',
    'account_id' => $treasury->id,
    'notes' => 'D3.A full payment',
], $token);
if ($resp['status'] === 201) {
    ok('D3.A full payment', "HTTP 201");
    // Reset: refund this payment so we can test partials
    $payment = FlightPayment::where('flight_booking_id', $booking->id)->orderBy('id', 'desc')->first();
    if ($payment) $payment->delete(); // soft-delete to reset for next tests
    $booking->update(['status' => 'PENDING']);
} else {
    bad('D3.A full payment', "got {$resp['status']}");
}

// D3.B 2 partial payments without idempotency key (legacy behavior)
echo "\nD3.B: 2 sequential partial payments without key (legacy)\n";
$resp1 = api('POST', $paymentUrl, ['amount' => 4000, 'payment_method' => 'cash', 'account_id' => $treasury->id, 'notes' => 'D3.B partial 1'], $token);
$resp2 = api('POST', $paymentUrl, ['amount' => 4000, 'payment_method' => 'cash', 'account_id' => $treasury->id, 'notes' => 'D3.B partial 2'], $token);
$resp3 = api('POST', $paymentUrl, ['amount' => 4000, 'payment_method' => 'cash', 'account_id' => $treasury->id, 'notes' => 'D3.B partial 3'], $token);

$countCreated = ($resp1['status']===201?1:0) + ($resp2['status']===201?1:0) + ($resp3['status']===201?1:0);
$booking->refresh();
if ($countCreated === 3 && abs((float)$booking->payments->sum('amount') - 12000) < 0.02) {
    ok('D3.B 3 partial payments (no key)', "all 3 accepted (201), cumulative paid=12000 — partial-payment lifecycle RESTORED");
} else {
    bad('D3.B 3 partial payments (no key)', "created={$countCreated}/3, paid={$booking->payments->sum('amount')}/12000, statuses=[{$resp1['status']},{$resp2['status']},{$resp3['status']}]");
}

// D3.C replay with idempotency key (sequential)
echo "\nD3.C: replay same key → 2nd returns 200 + idempotent_replay=true\n";
// Reset the booking: soft-delete payments, reset status
foreach (FlightPayment::where('flight_booking_id', $booking->id)->get() as $p) $p->delete();
$booking->update(['status' => 'PENDING']);

$idemKey = 'TEST-KEY-'.uniqid();
$resp1 = api('POST', $paymentUrl, ['amount' => 2000, 'payment_method' => 'cash', 'account_id' => $treasury->id, 'notes' => 'D3.C', 'idempotency_key' => $idemKey], $token);
$resp2 = api('POST', $paymentUrl, ['amount' => 2000, 'payment_method' => 'cash', 'account_id' => $treasury->id, 'notes' => 'D3.C', 'idempotency_key' => $idemKey], $token);

if ($resp1['status'] === 201 && $resp2['status'] === 200
    && isset($resp2['body']['data']['idempotent_replay']) && $resp2['body']['data']['idempotent_replay'] === true) {
    $paymentCount = FlightPayment::where('flight_booking_id', $booking->id)->whereNull('deleted_at')->count();
    if ($paymentCount === 1) {
        ok('D3.C idempotent replay (sequential)', "1st=201, 2nd=200 + replay=true, exactly 1 payment row");
    } else {
        bad('D3.C idempotent replay', "expected 1 payment row, found {$paymentCount}");
    }
} else {
    $replayFlag = $resp2['body']['data']['idempotent_replay'] ?? 'n/a';
    bad('D3.C idempotent replay', "1st={$resp1['status']}, 2nd={$resp2['status']}, replay={$replayFlag}");
}

// D3.D different keys, same amount → both succeed
echo "\nD3.D: 2 requests with DIFFERENT idempotency keys, same amount\n";
foreach (FlightPayment::where('flight_booking_id', $booking->id)->get() as $p) $p->delete();
$booking->update(['status' => 'PENDING']);

$k1 = 'KEY-DIFF-1-'.uniqid();
$k2 = 'KEY-DIFF-2-'.uniqid();
$resp1 = api('POST', $paymentUrl, ['amount' => 1500, 'payment_method' => 'cash', 'account_id' => $treasury->id, 'notes' => 'D3.D', 'idempotency_key' => $k1], $token);
$resp2 = api('POST', $paymentUrl, ['amount' => 1500, 'payment_method' => 'cash', 'account_id' => $treasury->id, 'notes' => 'D3.D', 'idempotency_key' => $k2], $token);

if ($resp1['status'] === 201 && $resp2['status'] === 201) {
    $pc = FlightPayment::where('flight_booking_id', $booking->id)->whereNull('deleted_at')->count();
    if ($pc === 2) {
        ok('D3.D different keys, same amount', 'both accepted, 2 distinct payment rows');
    } else {
        bad('D3.D different keys, same amount', "expected 2 payment rows, found {$pc}");
    }
} else {
    bad('D3.D different keys, same amount', "1st={$resp1['status']}, 2nd={$resp2['status']}");
}

// D3.E concurrent same idempotency key (10 workers)
echo "\nD3.E: 10 concurrent requests with SAME idempotency key (only 1 should create)\n";
foreach (FlightPayment::where('flight_booking_id', $booking->id)->get() as $p) $p->delete();
$booking->update(['status' => 'PENDING']);

$sameKey = 'KEY-CONC-'.uniqid();
$payload = ['amount' => 500, 'payment_method' => 'cash', 'account_id' => $treasury->id, 'notes' => 'D3.E', 'idempotency_key' => $sameKey];
$results = fireConcurrent('POST', $paymentUrl, $payload, 10, $token);

$created201 = 0; $replay200 = 0; $other = 0;
foreach ($results as $r) {
    if ($r['status'] === 201) $created201++;
    elseif ($r['status'] === 200) $replay200++;
    else $other++;
}
$pc = FlightPayment::where('flight_booking_id', $booking->id)->whereNull('deleted_at')->count();
$txc = Transaction::whereIn('related_type', [FlightPayment::class])
    ->whereIn('related_id', FlightPayment::where('flight_booking_id', $booking->id)->whereNull('deleted_at')->pluck('id'))
    ->count();

if ($created201 === 1 && $pc === 1 && $txc === 1 && ($replay200 + $other) === 9) {
    ok('D3.E 10 concurrent same key', "1×201 (creator), ".$replay200."×200 (replays), ".($other)."×other; 1 payment row, 1 transaction");
} else {
    bad('D3.E 10 concurrent same key', "201={$created201} 200={$replay200} other={$other} payments={$pc} tx={$txc}");
}

// D3.F concurrent DIFFERENT keys (10 workers) → all 10 should succeed
echo "\nD3.F: 10 concurrent requests with DIFFERENT idempotency keys\n";
foreach (FlightPayment::where('flight_booking_id', $booking->id)->get() as $p) $p->delete();
$booking->update(['status' => 'PENDING']);

$payload = ['amount' => 100, 'payment_method' => 'cash', 'account_id' => $treasury->id, 'notes' => 'D3.F', 'idempotency_key' => 'KEY-F-'.uniqid()];
$results = fireConcurrent('POST', $paymentUrl, $payload, 10, $token, true);

$created201 = 0; $other = 0;
foreach ($results as $r) {
    if ($r['status'] === 201) $created201++;
    else $other++;
}
$pc = FlightPayment::where('flight_booking_id', $booking->id)->whereNull('deleted_at')->count();

if ($created201 === 10 && $pc === 10) {
    ok('D3.F 10 concurrent distinct keys', 'all 10 succeeded (201), 10 distinct payment rows');
} else {
    bad('D3.F 10 concurrent distinct keys', "201={$created201}/10, payments={$pc}, other={$other}");
}

// =========================================================================
// D5 — INACTIVE CARRIER RECHARGE REJECTION
// =========================================================================
echo "\n=== D5 — INACTIVE CARRIER RECHARGE REJECTION ===\n";

$inactiveCarrier->refresh();
$balanceBefore = (float) $inactiveCarrier->balance;
$airlineTxBefore = AirlineTransaction::where('flight_carrier_id', $inactiveCarrier->id)->count();
$txBefore = Transaction::where('related_type', FlightCarrier::class)->where('related_id', $inactiveCarrier->id)->count();
$entryBefore = DB::table('account_entries')->where('transaction_id', function($q) use ($inactiveCarrier) {
    $q->select('id')->from('transactions')->where('related_type', FlightCarrier::class)->where('related_id', $inactiveCarrier->id);
})->count();

// D5.1 service-layer call
echo "\nD5.1: recharge via direct service call against inactive carrier\n";
try {
    $rechargeSvc = app(FlightCarrierRechargeService::class);
    $rechargeSvc->rechargeFromAccount($inactiveCarrier->fresh(), $treasury, 100, 'D5.1 inactive recharge');
    bad('D5.1 service: inactive carrier', 'ACCEPTED — guard failed');
} catch (InactiveFlightCarrierException $e) {
    ok('D5.1 service: inactive carrier', 'rejected: '.substr($e->getMessage(), 0, 80));
} catch (\Throwable $e) {
    bad('D5.1 service: inactive carrier', 'wrong exception: '.get_class($e).': '.$e->getMessage());
}

// D5.2 HTTP call
echo "\nD5.2: recharge via HTTP against inactive carrier\n";
$resp = api('POST', "http://127.0.0.1:18000/api/v1/flight/carriers/{$inactiveCarrier->id}/recharge",
    ['from_account_id' => $treasury->id, 'amount' => 100, 'notes' => 'D5.2 HTTP inactive'], $token);
if ($resp['status'] === 422) {
    ok('D5.2 HTTP: inactive carrier', "rejected with 422: ".substr($resp['body']['message'] ?? '', 0, 80));
} else {
    bad('D5.2 HTTP: inactive carrier', "got {$resp['status']}: ".substr($resp['raw'], 0, 100));
}

// D5.3 verify no mutations
$inactiveCarrier->refresh();
$balanceAfter = (float) $inactiveCarrier->balance;
$airlineTxAfter = AirlineTransaction::where('flight_carrier_id', $inactiveCarrier->id)->count();
$txAfter = Transaction::where('related_type', FlightCarrier::class)->where('related_id', $inactiveCarrier->id)->count();
$entryAfter = DB::table('account_entries')->where('transaction_id', function($q) use ($inactiveCarrier) {
    $q->select('id')->from('transactions')->where('related_type', FlightCarrier::class)->where('related_id', $inactiveCarrier->id);
})->count();

if (abs($balanceAfter - $balanceBefore) < 0.02 && $airlineTxAfter === $airlineTxBefore && $txAfter === $txBefore && $entryAfter === $entryBefore) {
    ok('D5.3 no mutations on inactive carrier', "balance unchanged ({$balanceBefore}→{$balanceAfter}), AirlineTx unchanged ({$airlineTxAfter}), GL tx unchanged ({$txAfter}), AccountEntries unchanged ({$entryAfter})");
} else {
    bad('D5.3 no mutations on inactive carrier', "balance {$balanceBefore}→{$balanceAfter}, AirlineTx {$airlineTxBefore}→{$airlineTxAfter}, GL tx {$txBefore}→{$txAfter}, AccountEntries {$entryBefore}→{$entryAfter}");
}

// D5.4 25 concurrent inactive carrier requests → all rejected
echo "\nD5.4: 25 concurrent inactive carrier recharges (all should reject)\n";
$payload = ['from_account_id' => $treasury->id, 'amount' => 50, 'notes' => 'D5.4 concurrent inactive'];
$results = fireConcurrent('POST', "http://127.0.0.1:18000/api/v1/flight/carriers/{$inactiveCarrier->id}/recharge",
    $payload, 25, $token, true);

$accepted = 0; $rejected = 0;
foreach ($results as $r) {
    if ($r['status'] === 200) $accepted++;
    elseif ($r['status'] === 422) $rejected++;
}
$airlineTxFinal = AirlineTransaction::where('flight_carrier_id', $inactiveCarrier->id)->count();

if ($accepted === 0 && $rejected === 25 && $airlineTxFinal === $airlineTxBefore) {
    ok('D5.4 25 concurrent inactive recharges', "0 accepted, 25 rejected, AirlineTx count unchanged ({$airlineTxFinal})");
} else {
    bad('D5.4 25 concurrent inactive recharges', "accepted={$accepted}, rejected={$rejected}, AirlineTx={$airlineTxFinal}");
}

// D5.5 active carrier still works (regression)
echo "\nD5.5: active carrier recharge still works (regression)\n";
// Refresh carrier to get latest balance from DB
$carrier->refresh();
$balanceActiveBefore = (float) $carrier->balance;
$resp = api('POST', "http://127.0.0.1:18000/api/v1/flight/carriers/{$carrier->id}/recharge",
    ['from_account_id' => $treasury->id, 'amount' => 50, 'notes' => 'D5.5 active regression'], $token);
$carrier->refresh();
$expected = $balanceActiveBefore + 50;
$delta = (float)$carrier->balance - $expected;
if ($resp['status'] === 200 && abs($delta) < 0.02) {
    ok('D5.5 active carrier recharge', "accepted, balance {$balanceActiveBefore}→{$carrier->balance} (Δ=".($carrier->balance - $balanceActiveBefore).")");
} else {
    bad('D5.5 active carrier recharge', "status={$resp['status']}, balance={$carrier->balance} expected={$expected}");
}

// =========================================================================
// ROLLBACK — failed payment leaves no artifacts
// =========================================================================
echo "\n=== ROLLBACK TEST ===\n";

$paymentsBefore = FlightPayment::where('flight_booking_id', $booking->id)->whereNull('deleted_at')->count();
$treasuryBalanceBefore = (float) $treasury->balance;

// Submit a payment with an INVALID payment_method (will fail FormRequest validation, no mutation)
echo "\nR1: invalid payment_method → 422 + no FlightPayment row + no tx\n";
$resp = api('POST', $paymentUrl, ['amount' => 100, 'payment_method' => 'INVALID_METHOD', 'account_id' => $treasury->id], $token);
$paymentsAfter = FlightPayment::where('flight_booking_id', $booking->id)->whereNull('deleted_at')->count();
$treasuryBalanceAfter = (float) $treasury->balance;

if ($resp['status'] === 422 && $paymentsAfter === $paymentsBefore && abs($treasuryBalanceAfter - $treasuryBalanceBefore) < 0.02) {
    ok('R1 invalid payment_method', 'rejected with 422, no row created, treasury unchanged');
} else {
    $treasuryDelta = $treasuryBalanceAfter - $treasuryBalanceBefore;
    bad('R1 invalid payment_method', "status={$resp['status']}, payments {$paymentsBefore}→{$paymentsAfter}, treasury Δ={$treasuryDelta}");
}

echo "\n=== FINAL ===\n";
echo "PASS: {$pass}    FAIL: {$fail}\n";
exit($fail === 0 ? 0 : 1);
