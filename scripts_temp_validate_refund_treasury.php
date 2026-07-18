<?php
/**
 * REAL DB VALIDATION SCENARIO 1: RefundRequest — Destination: agency_treasury
 *
 * Tests the full cycle:
 *   1. Create booking + payment (real services, real DB)
 *   2. Create + process refund (real services, real DB)
 *   3. Verify refund had expected financial effect
 *   4. Call RefundController::destroy (same path as Vue UI)
 *   5. Verify balances restored to BASELINE (before booking creation)
 *
 * Run: php scripts_temp_validate_refund_treasury.php
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\RefundRequest;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Services\Flight\RefundService;
use Illuminate\Support\Facades\Log;

$result = [
    'scenario' => 'RefundRequest — Destination: agency_treasury',
    'success'  => false,
    'error'    => null,
    'steps'    => [],
    'balances' => [],
    'deltas'   => [],
    'verdict'  => [],
    'notes'    => [],
];

// Auth setup
$realUser = User::orderBy('id')->first();
if (! $realUser) {
    $realUser = User::create([
        'name' => 'Refund Treasury Validator',
        'email' => 'refund-treasury-validator@test.com',
        'password' => bcrypt('test1234'),
        'role' => 'admin',
        'is_active' => true,
    ]);
}
auth()->setUser($realUser);
echo "[setup] User #{$realUser->id} ({$realUser->role})\n";

// Test employee
$testEmployee = \App\Models\Employee::where('full_name', 'TEST_REFUND_EMPLOYEE')->first();
if (! $testEmployee) {
    $testEmployee = \App\Models\Employee::create([
        'full_name' => 'TEST_REFUND_EMPLOYEE',
        'phone' => '0000000000',
        'national_id' => 'TESTREFUND1',
        'created_by' => $realUser->id,
    ]);
}

try {
    // ─────────────────────────────────────────────────────
    // 1) Setup carrier + cashbox + treasury
    // ─────────────────────────────────────────────────────
    $cashbox = Account::where('module_type', 'flights')
        ->where('type', 'cashbox')
        ->where('is_active', true)
        ->first();
    if (! $cashbox) {
        $cashbox = Account::create([
            'name' => 'TEST_CASHBOX_REFUND_VALIDATION',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 1000000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'flights',
            'created_by' => $realUser->id,
        ]);
        echo "[setup] Created cashbox #{$cashbox->id}\n";
    }

    $carrier = FlightCarrier::where('name', 'TEST_CARRIER_REFUND')->first();
    if (! $carrier) {
        $carrier = FlightCarrier::create([
            'name' => 'TEST_CARRIER_REFUND',
            'code' => 'TCRF'.substr(md5(uniqid()), 0, 4),
            'currency' => 'EGP',
            'balance' => 0,
            'credit_limit' => 0,
            'is_active' => true,
            'created_by' => $realUser->id,
        ]);
        echo "[setup] Created carrier #{$carrier->id}\n";
    }

    // Recharge
    if ((float) $carrier->balance < 500.0) {
        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $carrier, $cashbox, 500.0, 'Refund treasury validator setup'
        );
    }

    $prepaidAccountId = app(LedgerClearingAccounts::class)->prepaidAccountId('flight_carrier');

    $treasury = Treasury::where('name', 'TEST_TREASURY_REFUND')->first();
    if (! $treasury) {
        $treasury = Treasury::create([
            'name' => 'TEST_TREASURY_REFUND',
            'currency' => 'EGP',
            'current_balance' => 0,
            'is_active' => true,
        ]);
        echo "[setup] Created treasury #{$treasury->id}\n";
    }

    $customer = Customer::where('full_name', 'TEST_REFUND_CUSTOMER')->first();
    if (! $customer) {
        $customer = Customer::create([
            'full_name' => 'TEST_REFUND_CUSTOMER',
            'phone' => '0000000000',
            'passport' => 'TEST-RF-'.uniqid(),
            'created_by' => $realUser->id,
        ]);
    }

    // ─────────────────────────────────────────────────────
    // 2) BASELINE snapshot (BEFORE booking — our zero reference)
    // ─────────────────────────────────────────────────────
    $baseline = [
        'carrier'    => (float) $carrier->fresh()->balance,
        'prepaid_gl' => (float) Account::find($prepaidAccountId)->balance,
        'cashbox'    => (float) $cashbox->fresh()->balance,
        'treasury'   => (float) $treasury->fresh()->current_balance,
        'customer'   => (float) Account::find($customer->account_id)->balance,
    ];
    $result['balances']['baseline'] = $baseline;
    echo "[baseline] " . json_encode($baseline) . "\n";

    // ─────────────────────────────────────────────────────
    // 3) Create booking + payment
    // ─────────────────────────────────────────────────────
    $sellingPrice = 100.0;
    $purchasePrice = 80.0;

    $booking = app(FlightBookingService::class)->createBooking([
        'booking_reference' => 'RF-DEL-'.uniqid(),
        'customer_id' => $customer->id,
        'employee_id' => $testEmployee->id,
        'airline' => 'TEST_AIRLINE',
        'airline_name' => 'Refund Test Airline',
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
        'passengers' => [['full_name' => 'REFUND PAX', 'passport_number' => 'RF-PP', 'type' => 'adult']],
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

    // ─────────────────────────────────────────────────────
    // Snapshot AFTER booking (before refund) — the right baseline for reversal check
    // ─────────────────────────────────────────────────────
    $afterBooking = [
        'carrier'    => (float) $carrier->fresh()->balance,
        'prepaid_gl' => (float) Account::find($prepaidAccountId)->balance,
        'cashbox'    => (float) $cashbox->fresh()->balance,
        'treasury'   => (float) $treasury->fresh()->current_balance,
        'customer'   => (float) Account::find($customer->account_id)->balance,
    ];
    $result['balances']['after_booking'] = $afterBooking;
    echo "[after_booking] " . json_encode($afterBooking) . "\n";

    // ─────────────────────────────────────────────────────
    // 4) Create + process refund to agency_treasury
    // ─────────────────────────────────────────────────────
    $cancellationFee = 10.0;
    $refundAmount = $sellingPrice - $cancellationFee; // 90.0

    $refundRequest = app(RefundService::class)->createRefundRequest([
        'flight_booking_id' => $booking->id,
        'cancellation_fee' => $cancellationFee,
        'refund_currency' => 'EGP',
        'destination' => 'agency_treasury',
        'treasury_id' => $treasury->id,
    ], $realUser->id);

    app(RefundService::class)->processRefundRequest($refundRequest->id, $realUser->id);

    $result['steps']['process_refund'] = [
        'refund_request_id' => $refundRequest->id,
        'refund_amount' => $refundAmount,
        'destination' => 'agency_treasury',
    ];

    // Snapshot AFTER booking+refund (this is the right comparison baseline for the reversal)
    // because the booking is STILL ALIVE — only the refund's impact should be reversed.
    $afterBookingAndRefund = [
        'carrier'    => (float) $carrier->fresh()->balance,
        'prepaid_gl' => (float) Account::find($prepaidAccountId)->balance,
        'cashbox'    => (float) $cashbox->fresh()->balance,
        'treasury'   => (float) $treasury->fresh()->current_balance,
        'customer'   => (float) Account::find($customer->account_id)->balance,
    ];
    $result['balances']['after_booking_and_refund'] = $afterBookingAndRefund;
    echo "[after_booking_and_refund] " . json_encode($afterBookingAndRefund) . "\n";

    // ─────────────────────────────────────────────────────
    // 5) Call RefundController::destroy (the same path Vue UI uses)
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

    echo "[controller] Status: {$controllerResult->getStatusCode()}\n";

    // ─────────────────────────────────────────────────────
    // 6) Snapshot AFTER reversal — should match after_booking_and_refund (refund's effect reversed)
    //    Booking is still alive, so its impact remains.
    // ─────────────────────────────────────────────────────
    $refundRequest->refresh();

    $afterReversal = [
        'carrier'    => (float) $carrier->fresh()->balance,
        'prepaid_gl' => (float) Account::find($prepaidAccountId)->balance,
        'cashbox'    => (float) $cashbox->fresh()->balance,
        'treasury'   => (float) $treasury->fresh()->current_balance,
        'customer'   => (float) Account::find($customer->account_id)->balance,
    ];
    $result['balances']['after_reversal'] = $afterReversal;
    echo "[after_reversal] " . json_encode($afterReversal) . "\n";

    // PRIMARY CHECK: refund reversal must restore to "after booking" state
    // (since the booking is still alive after reversal, only the refund's impact should be undone)
    $refundReversalDelta = [
        'carrier'    => round($afterReversal['carrier']    - $afterBooking['carrier'],    2),
        'prepaid_gl' => round($afterReversal['prepaid_gl'] - $afterBooking['prepaid_gl'], 2),
        'cashbox'    => round($afterReversal['cashbox']    - $afterBooking['cashbox'],    2),
        'treasury'   => round($afterReversal['treasury']   - $afterBooking['treasury'],   2),
        'customer'   => round($afterReversal['customer']   - $afterBooking['customer'],   2),
    ];
    $result['deltas']['after_reversal_vs_after_booking'] = $refundReversalDelta;
    echo "[refund_reversal_delta_vs_after_booking] " . json_encode($refundReversalDelta) . "\n";

    // SECONDARY CHECK: full cycle (booking + refund + reversal) vs baseline
    // This will NOT be zero because the booking is still alive — this is expected design.
    $fullCycleDelta = [
        'carrier'    => round($afterReversal['carrier']    - $baseline['carrier'],    2),
        'prepaid_gl' => round($afterReversal['prepaid_gl'] - $baseline['prepaid_gl'], 2),
        'cashbox'    => round($afterReversal['cashbox']    - $baseline['cashbox'],    2),
        'treasury'   => round($afterReversal['treasury']   - $baseline['treasury'],   2),
        'customer'   => round($afterReversal['customer']   - $baseline['customer'],   2),
    ];
    $result['deltas']['full_cycle_vs_baseline'] = $fullCycleDelta;
    echo "[full_cycle_delta_vs_baseline] " . json_encode($fullCycleDelta) . "\n";

    // Also capture the refund-only effect (after_booking_and_refund vs after_booking)
    // The reversal should be the EXACT NEGATIVE of this
    $refundOnlyEffect = [
        'carrier'    => round($afterBookingAndRefund['carrier']    - $afterBooking['carrier'],    2),
        'prepaid_gl' => round($afterBookingAndRefund['prepaid_gl'] - $afterBooking['prepaid_gl'], 2),
        'cashbox'    => round($afterBookingAndRefund['cashbox']    - $afterBooking['cashbox'],    2),
        'treasury'   => round($afterBookingAndRefund['treasury']   - $afterBooking['treasury'],   2),
        'customer'   => round($afterBookingAndRefund['customer']   - $afterBooking['customer'],   2),
    ];
    $result['deltas']['refund_effect_only'] = $refundOnlyEffect;
    echo "[refund_effect_only] " . json_encode($refundOnlyEffect) . "\n";

    // ─────────────────────────────────────────────────────
    // 7) Idempotency test FIRST (before setting verdict)
    //    The controller catches RuntimeException and returns 422 with the Arabic error,
    //    so we check the response status (not whether an exception was thrown).
    // ─────────────────────────────────────────────────────
    $secondCall = $controller->destroy($refundRequest->id);
    $result['steps']['idempotency_check'] = [
        'second_call_status' => $secondCall->getStatusCode(),
        'second_call_body' => json_decode($secondCall->getContent(), true),
    ];
    // Check via the decoded body to avoid encoding edge cases
    $decodedBody = json_decode($secondCall->getContent(), true);
    $idempotencyOk = ($secondCall->getStatusCode() === 422 && isset($decodedBody['message']) && str_contains($decodedBody['message'], 'محذوف بالفعل'));

    // ─────────────────────────────────────────────────────
    // 8) Verdict
    // ─────────────────────────────────────────────────────
    $result['verdict'] = [
        'refund_effect_reversed'      => ($refundReversalDelta['carrier'] == 0 && $refundReversalDelta['prepaid_gl'] == 0 && $refundReversalDelta['cashbox'] == 0 && $refundReversalDelta['treasury'] == 0 && $refundReversalDelta['customer'] == 0),
        'booking_still_alive'         => ! $booking->fresh()->trashed(),
        'refund_request_trashed'      => $refundRequest->trashed(),
        'booking_status'              => $booking->fresh()->status->value ?? $booking->fresh()->status,
        'full_cycle_matches_baseline' => ($fullCycleDelta['carrier'] == 0 && $fullCycleDelta['prepaid_gl'] == 0 && $fullCycleDelta['cashbox'] == 0 && $fullCycleDelta['treasury'] == 0 && $fullCycleDelta['customer'] == 0),
        'idempotency_throws'          => $idempotencyOk,
    ];

    // Document the gap
    $result['notes'][] = 'Booking is still alive after refund reversal (only refund is soft-deleted). Booking\'s impact on accounts remains. To get full zero, the booking itself must also be deleted (use deleteBookingWithReversal).';
    $result['notes'][] = 'Booking status is NOT auto-reverted from REFUNDED → original status (documented gap in RefundService::reverseRefundRequest)';

    // Success is true if refund effect was correctly reversed (the primary check)
    $result['success'] = $result['verdict']['refund_effect_reversed']
        && $result['verdict']['booking_still_alive']
        && $result['verdict']['refund_request_trashed']
        && $result['verdict']['idempotency_throws'];

} catch (\Throwable $e) {
    $result['error'] = $e->getMessage() . ' (file: ' . $e->getFile() . ' line: ' . $e->getLine() . ')';
    $result['trace_excerpt'] = array_slice(explode("\n", $e->getTraceAsString()), 0, 8);
}

file_put_contents(
    storage_path('logs/real_db_refund_treasury.json'),
    json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

echo "\n=== SCENARIO 1 RESULT ===\n";
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
echo "\n=== END ===\n";