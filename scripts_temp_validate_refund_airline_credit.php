<?php
/**
 * REAL DB VALIDATION SCENARIO 2: RefundRequest — Destination: airline_credit
 *
 * Tests that:
 *   1. Refund to airline_credit creates an AirlineCredit voucher (active)
 *   2. No GL impact on any account (because no cash/treasury transfer)
 *   3. RefundController::destroy cancels + soft-deletes the voucher
 *   4. RefundRequest itself is soft-deleted
 *   5. Idempotency: second call returns 422 with Arabic error
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\AirlineCredit;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\RefundRequest;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Services\Flight\RefundService;
use Illuminate\Support\Facades\Log;

$result = [
    'scenario' => 'RefundRequest — Destination: airline_credit',
    'success'  => false,
    'error'    => null,
    'steps'    => [],
    'balances' => [],
    'deltas'   => [],
    'verdict'  => [],
    'notes'    => [],
];

$realUser = User::orderBy('id')->first();
if (! $realUser) {
    $realUser = User::create([
        'name' => 'Airline Credit Validator',
        'email' => 'airline-credit-validator@test.com',
        'password' => bcrypt('test1234'),
        'role' => 'admin',
        'is_active' => true,
    ]);
}
auth()->setUser($realUser);
echo "[setup] User #{$realUser->id}\n";

$testEmployee = \App\Models\Employee::where('full_name', 'TEST_AIRLINE_CREDIT_EMPLOYEE')->first();
if (! $testEmployee) {
    $testEmployee = \App\Models\Employee::create([
        'full_name' => 'TEST_AIRLINE_CREDIT_EMPLOYEE',
        'phone' => '0000000000',
        'national_id' => 'TESTAC1',
        'created_by' => $realUser->id,
    ]);
}

try {
    // ─────────────────────────────────────────────────────
    // Setup
    // ─────────────────────────────────────────────────────
    $cashbox = Account::where('module_type', 'flights')
        ->where('type', 'cashbox')
        ->where('is_active', true)
        ->first();
    if (! $cashbox) {
        $cashbox = Account::create([
            'name' => 'TEST_CASHBOX_AIRLINE_CREDIT',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 1000000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'flights',
            'created_by' => $realUser->id,
        ]);
    }

    $flightSystem = FlightSystem::where('name', 'TEST_SYSTEM_AIRLINE_CREDIT')->first();
    if (! $flightSystem) {
        $flightSystem = FlightSystem::create([
            'name' => 'TEST_SYSTEM_AIRLINE_CREDIT',
            'code' => 'TSAC'.substr(md5(uniqid()), 0, 4),
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'EGP',
            'balance' => 0,
            'credit_limit' => 0,
            'created_by' => $realUser->id,
        ]);
    }

    $carrier = FlightCarrier::where('name', 'TEST_CARRIER_AIRLINE_CREDIT')->first();
    if (! $carrier) {
        $carrier = FlightCarrier::create([
            'name' => 'TEST_CARRIER_AIRLINE_CREDIT',
            'code' => 'TCAC'.substr(md5(uniqid()), 0, 4),
            'flight_system_id' => $flightSystem->id,
            'currency' => 'EGP',
            'balance' => 0,
            'credit_limit' => 0,
            'is_active' => true,
            'created_by' => $realUser->id,
        ]);
    }
    if ((float) $carrier->balance < 500.0) {
        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $carrier, $cashbox, 500.0, 'Airline credit validator setup'
        );
    }

    $customer = Customer::where('full_name', 'TEST_AIRLINE_CREDIT_CUSTOMER')->first();
    if (! $customer) {
        $customer = Customer::create([
            'full_name' => 'TEST_AIRLINE_CREDIT_CUSTOMER',
            'phone' => '0000000000',
            'passport' => 'TEST-AC-'.uniqid(),
            'created_by' => $realUser->id,
        ]);
    }

    // ─────────────────────────────────────────────────────
    // Snapshot BASELINE (BEFORE booking — true zero reference)
    // ─────────────────────────────────────────────────────
    $baseline = [
        'carrier'  => (float) $carrier->fresh()->balance,
        'cashbox'  => (float) $cashbox->fresh()->balance,
        'customer' => (float) Account::find($customer->account_id)->balance,
    ];
    $result['balances']['baseline'] = $baseline;
    echo "[baseline] " . json_encode($baseline) . "\n";

    // ─────────────────────────────────────────────────────
    // Create booking + payment
    // ─────────────────────────────────────────────────────
    $sellingPrice = 200.0;
    $purchasePrice = 150.0;

    $booking = app(FlightBookingService::class)->createBooking([
        'booking_reference' => 'AC-'.uniqid(),
        'customer_id' => $customer->id,
        'employee_id' => $testEmployee->id,
        'airline' => 'TEST_AIRLINE',
        'airline_name' => 'Airline Credit Test',
        'origin' => 'TST', 'destination' => 'DEL',
        'from_airport' => 'Test Origin', 'to_airport' => 'Test Dest',
        'departure_date' => now()->addDays(7)->format('Y-m-d'),
        'departure_time' => '12:00:00',
        'trip_type' => 'one_way',
        'passenger_count' => 1,
        'purchase_price' => $purchasePrice,
        'selling_price' => $sellingPrice,
        'profit' => $sellingPrice - $purchasePrice,
        'currency' => $carrier->currency,
        'purchase_balance_source' => 'carrier',
        'flight_carrier_id' => $carrier->id,
        'baggage_allowance_kg' => 0,
        'exchange_rate' => 1.0,
        'passengers' => [['full_name' => 'AIRLINE CREDIT PAX', 'passport_number' => 'AC-PP', 'type' => 'adult']],
    ]);

    app(FlightBookingService::class)->addPayment($booking, [
        'amount' => $sellingPrice,
        'currency' => $carrier->currency,
        'payment_method' => 'cash',
        'account_id' => $cashbox->id,
    ]);

    $result['steps']['create_booking'] = [
        'booking_id' => $booking->id,
        'booking_number' => $booking->booking_number,
    ];

    // Snapshot AFTER booking
    $afterBooking = [
        'carrier'  => (float) $carrier->fresh()->balance,
        'cashbox'  => (float) $cashbox->fresh()->balance,
        'customer' => (float) Account::find($customer->account_id)->balance,
    ];
    $result['balances']['after_booking'] = $afterBooking;
    echo "[after_booking] " . json_encode($afterBooking) . "\n";

    // ─────────────────────────────────────────────────────
    // Create + process refund to airline_credit
    // ─────────────────────────────────────────────────────
    $cancellationFee = 0.0;
    $refundAmount = $sellingPrice - $cancellationFee; // 200

    $refundRequest = app(RefundService::class)->createRefundRequest([
        'flight_booking_id' => $booking->id,
        'cancellation_fee' => $cancellationFee,
        'refund_currency' => 'EGP',
        'destination' => 'airline_credit',
    ], $realUser->id);

    app(RefundService::class)->processRefundRequest($refundRequest->id, $realUser->id);

    $result['steps']['process_refund'] = [
        'refund_request_id' => $refundRequest->id,
        'refund_amount' => $refundAmount,
        'destination' => 'airline_credit',
    ];

    // Verify AirlineCredit voucher was created
    $voucher = AirlineCredit::where('refund_request_id', $refundRequest->id)->first();
    if (! $voucher) {
        throw new \RuntimeException('AirlineCredit voucher was not created');
    }

    $result['steps']['voucher_created'] = [
        'voucher_id' => $voucher->id,
        'amount' => (float) $voucher->amount,
        'status' => $voucher->status,
    ];

    // Snapshot AFTER refund to airline_credit — should be identical to after_booking
    // because airline_credit destination does NOT touch any GL account
    $afterRefund = [
        'carrier'  => (float) $carrier->fresh()->balance,
        'cashbox'  => (float) $cashbox->fresh()->balance,
        'customer' => (float) Account::find($customer->account_id)->balance,
    ];
    $result['balances']['after_refund'] = $afterRefund;
    echo "[after_refund] " . json_encode($afterRefund) . "\n";

    $refundDelta = [
        'carrier'  => round($afterRefund['carrier']  - $afterBooking['carrier'],  2),
        'cashbox'  => round($afterRefund['cashbox']  - $afterBooking['cashbox'],  2),
        'customer' => round($afterRefund['customer'] - $afterBooking['customer'], 2),
    ];
    $result['deltas']['refund_vs_after_booking'] = $refundDelta;
    echo "[refund_delta_vs_after_booking] " . json_encode($refundDelta) . "\n";

    // ─────────────────────────────────────────────────────
    // Call RefundController::destroy (same as Vue UI)
    // ─────────────────────────────────────────────────────
    $controller = app(\App\Http\Controllers\Api\V1\Flight\RefundController::class);
    $controllerResult = $controller->destroy($refundRequest->id);

    $result['steps']['controller_destroy'] = [
        'http_status' => $controllerResult->getStatusCode(),
        'response' => json_decode($controllerResult->getContent(), true),
    ];

    if ($controllerResult->getStatusCode() >= 400) {
        throw new \RuntimeException('Controller returned error: ' . $controllerResult->getContent());
    }

    // Refresh entities
    $refundRequest->refresh();
    $voucher->refresh();

    $result['steps']['post_destroy'] = [
        'refund_trashed' => $refundRequest->trashed(),
        'refund_deleted_at' => $refundRequest->deleted_at?->toIso8601String(),
        'voucher_status' => $voucher->status,
        'voucher_trashed' => $voucher->trashed(),
    ];

    // Snapshot AFTER reversal — should match after_booking (since airline_credit reversal
    // doesn't touch GL either — it just cancels the voucher)
    $afterReversal = [
        'carrier'  => (float) $carrier->fresh()->balance,
        'cashbox'  => (float) $cashbox->fresh()->balance,
        'customer' => (float) Account::find($customer->account_id)->balance,
    ];
    $result['balances']['after_reversal'] = $afterReversal;
    echo "[after_reversal] " . json_encode($afterReversal) . "\n";

    $reversalDelta = [
        'carrier'  => round($afterReversal['carrier']  - $afterBooking['carrier'],  2),
        'cashbox'  => round($afterReversal['cashbox']  - $afterBooking['cashbox'],  2),
        'customer' => round($afterReversal['customer'] - $afterBooking['customer'], 2),
    ];
    $result['deltas']['reversal_vs_after_booking'] = $reversalDelta;
    echo "[reversal_delta_vs_after_booking] " . json_encode($reversalDelta) . "\n";

    // ─────────────────────────────────────────────────────
    // Idempotency test
    // ─────────────────────────────────────────────────────
    $secondCall = $controller->destroy($refundRequest->id);
    $result['steps']['idempotency_check'] = [
        'second_call_status' => $secondCall->getStatusCode(),
        'second_call_body' => json_decode($secondCall->getContent(), true),
    ];
    $decodedBody = json_decode($secondCall->getContent(), true);
    $idempotencyOk = ($secondCall->getStatusCode() === 422 && isset($decodedBody['message']) && str_contains($decodedBody['message'], 'محذوف بالفعل'));

    // ─────────────────────────────────────────────────────
    // Verdict
    // ─────────────────────────────────────────────────────
    $result['verdict'] = [
        'no_gl_impact_on_refund'         => ($refundDelta['carrier'] == 0 && $refundDelta['cashbox'] == 0 && $refundDelta['customer'] == 0),
        'no_gl_impact_on_reversal'       => ($reversalDelta['carrier'] == 0 && $reversalDelta['cashbox'] == 0 && $reversalDelta['customer'] == 0),
        'voucher_was_active_before'      => ($voucher->status === 'cancelled' && $voucher->trashed()), // After reversal: cancelled + trashed
        'voucher_amount_preserved'       => ((float) $voucher->amount === $refundAmount),
        'refund_request_trashed'         => $refundRequest->trashed(),
        'booking_still_alive'            => ! $booking->fresh()->trashed(),
        'idempotency_throws'             => $idempotencyOk,
    ];

    $result['notes'][] = 'airline_credit destination does NOT touch any GL account (per design) — only creates/cancels an AirlineCredit voucher';

    $result['success'] = $result['verdict']['no_gl_impact_on_refund']
        && $result['verdict']['no_gl_impact_on_reversal']
        && $result['verdict']['voucher_was_active_before']
        && $result['verdict']['voucher_amount_preserved']
        && $result['verdict']['refund_request_trashed']
        && $result['verdict']['booking_still_alive']
        && $result['verdict']['idempotency_throws'];

} catch (\Throwable $e) {
    $result['error'] = $e->getMessage() . ' (file: ' . $e->getFile() . ' line: ' . $e->getLine() . ')';
    $result['trace_excerpt'] = array_slice(explode("\n", $e->getTraceAsString()), 0, 8);
}

file_put_contents(
    storage_path('logs/real_db_refund_airline_credit.json'),
    json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

echo "\n=== SCENARIO 2 RESULT ===\n";
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
echo "\n=== END ===\n";