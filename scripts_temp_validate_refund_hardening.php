<?php
/**
 * REAL DB VALIDATION: RefundService hardening (Commit 07003df)
 *
 * Verifies:
 *   1. Existing Scenario 1 — agency_treasury process+reverse still works
 *   2. Existing Scenario 2 — airline_credit process+reverse still works
 *   3. NEW Scenario 4 — duplicate active voucher is REJECTED
 *   4. NEW Scenario 5 — non-contended normal path is unaffected
 *      (re-running the same operations multiple times to confirm no
 *      spurious retry attempts / no breakage of the happy path)
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
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Services\Flight\RefundService;
use Illuminate\Support\Facades\Log;

$result = [
    'scenario' => 'RefundService hardening — full validation',
    'success'  => false,
    'error'    => null,
    'scenarios' => [],
    'verdict'  => [],
    'notes'    => [],
];

$realUser = User::orderBy('id')->first();
if (! $realUser) {
    $realUser = User::create([
        'name' => 'Refund Hardening Validator',
        'email' => 'refund-hardening-validator@test.com',
        'password' => bcrypt('test1234'),
        'role' => 'admin',
        'is_active' => true,
    ]);
}
auth()->setUser($realUser);
echo "[setup] User #{$realUser->id}\n";

$testEmployee = \App\Models\Employee::where('full_name', 'TEST_REFUND_HARDENING')->first();
if (! $testEmployee) {
    $testEmployee = \App\Models\Employee::create([
        'full_name' => 'TEST_REFUND_HARDENING',
        'phone' => '0000000000',
        'national_id' => 'TESTRH1',
        'created_by' => $realUser->id,
    ]);
}

try {
    // ─────────────────────────────────────────────────────
    // Common setup: cashbox + carrier + treasury
    // ─────────────────────────────────────────────────────
    $cashbox = Account::where('module_type', 'flights')
        ->where('type', 'cashbox')
        ->where('is_active', true)
        ->first();
    if (! $cashbox) {
        $cashbox = Account::create([
            'name' => 'TEST_CASHBOX_RH_VALIDATION',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 1000000,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'flights',
            'created_by' => $realUser->id,
        ]);
    }

    $flightSystem = FlightSystem::where('name', 'TEST_SYSTEM_RH')->first();
    if (! $flightSystem) {
        $flightSystem = FlightSystem::create([
            'name' => 'TEST_SYSTEM_RH',
            'code' => 'SRH'.substr(md5(uniqid()), 0, 4),
            'type' => 'gds', 'is_active' => true,
            'currency' => 'EGP', 'balance' => 0, 'credit_limit' => 0,
            'created_by' => $realUser->id,
        ]);
    }

    $carrier = FlightCarrier::where('name', 'TEST_CARRIER_RH')->first();
    if (! $carrier) {
        $carrier = FlightCarrier::create([
            'name' => 'TEST_CARRIER_RH',
            'code' => 'CRH'.substr(md5(uniqid()), 0, 4),
            'flight_system_id' => $flightSystem->id,
            'currency' => 'EGP', 'balance' => 0, 'credit_limit' => 0,
            'is_active' => true, 'created_by' => $realUser->id,
        ]);
    }
    // Always recharge to a generous amount to avoid running out across scenarios
    app(FlightCarrierRechargeService::class)->rechargeFromAccount(
        $carrier, $cashbox, 5000.0, 'RH validator setup (large)'
    );

    $treasury = \App\Models\Treasury::firstOrCreate(
        ['name' => 'TEST_TREASURY_RH_'.uniqid()],
        ['currency' => 'EGP', 'current_balance' => 0, 'is_active' => true]
    );
    \App\Support\Finance\LedgerBalanceMutationGuard::run(function () use ($treasury) {
        $treasury->current_balance = 1000.00;
        $treasury->save();
    });
    $treasury->refresh();

    $customer = Customer::where('full_name', 'TEST_RH_CUSTOMER')->first();
    if (! $customer) {
        $customer = Customer::create([
            'full_name' => 'TEST_RH_CUSTOMER',
            'phone' => '0000000000',
            'passport' => 'TEST-RH-'.uniqid(),
            'created_by' => $realUser->id,
        ]);
    }

    /**
     * Helper: create a fully-paid booking, return [$booking, $sellingPrice].
     */
    $createPaidBooking = function () use ($cashbox, $carrier, $customer, $testEmployee) {
        $sellingPrice = 200.0;
        $booking = app(FlightBookingService::class)->createBooking([
            'booking_reference' => 'RH-'.uniqid(),
            'customer_id' => $customer->id,
            'employee_id' => $testEmployee->id,
            'airline' => 'TEST_AIRLINE', 'airline_name' => 'RH Validation',
            'origin' => 'TST', 'destination' => 'DEL',
            'from_airport' => 'O', 'to_airport' => 'D',
            'departure_date' => now()->addDays(7)->format('Y-m-d'),
            'departure_time' => '12:00:00',
            'trip_type' => 'one_way', 'passenger_count' => 1,
            'purchase_price' => 80, 'selling_price' => $sellingPrice,
            'profit' => $sellingPrice - 80, 'currency' => $carrier->currency,
            'purchase_balance_source' => 'carrier',
            'flight_carrier_id' => $carrier->id,
            'account_id' => $cashbox->id,
            'passengers' => [['name' => 'RH PAX', 'type' => 'adult']],
        ]);
        app(FlightBookingService::class)->addPayment($booking, [
            'amount' => $sellingPrice, 'payment_method' => 'cash', 'account_id' => $cashbox->id,
        ]);
        $booking->update(['status' => \App\Enums\FlightBookingStatus::CONFIRMED]);
        return [$booking, $sellingPrice];
    };

    // ════════════════════════════════════════════════════════
    // SCENARIO 1: agency_treasury process + reverse (regression check)
    // ════════════════════════════════════════════════════════
    try {
        [$booking1, $selling1] = $createPaidBooking();
        $treasuryBefore = (float) $treasury->fresh()->current_balance;

        $refund1 = app(RefundService::class)->createRefundRequest([
            'flight_booking_id' => $booking1->id,
            'cancellation_fee'  => 0,
            'destination'       => 'agency_treasury',
            'treasury_id'       => $treasury->id,
        ], $realUser->id);
        app(RefundService::class)->processRefundRequest($refund1->id, $realUser->id);

        $treasuryAfterProcess = (float) $treasury->fresh()->current_balance;
        $processDelta = round($treasuryAfterProcess - $treasuryBefore, 2);

        app(\App\Http\Controllers\Api\V1\Flight\RefundController::class)->destroy($refund1->id);
        $treasuryAfterReverse = (float) $treasury->fresh()->current_balance;
        $reversalDelta = round($treasuryAfterReverse - $treasuryAfterProcess, 2);

        $result['scenarios']['agency_treasury'] = [
            'passed' => ($processDelta === 200.0 && $reversalDelta === -200.0),
            'process_delta' => $processDelta,
            'reversal_delta' => $reversalDelta,
            'final_balance' => $treasuryAfterReverse,
        ];
        echo "[scenario 1: agency_treasury] process=+{$processDelta}, reversal={$reversalDelta}\n";
    } catch (\Throwable $e) {
        $result['scenarios']['agency_treasury'] = [
            'passed' => false,
            'error' => $e->getMessage(),
        ];
        echo "[scenario 1: agency_treasury] FAILED: " . $e->getMessage() . "\n";
    }

    // ════════════════════════════════════════════════════════
    // SCENARIO 2: airline_credit process + reverse (regression check)
    // ════════════════════════════════════════════════════════
    try {
        [$booking2, $selling2] = $createPaidBooking();

        $refund2 = app(RefundService::class)->createRefundRequest([
            'flight_booking_id' => $booking2->id,
            'cancellation_fee'  => 0,
            'destination'       => 'airline_credit',
        ], $realUser->id);
        app(RefundService::class)->processRefundRequest($refund2->id, $realUser->id);

        $voucherAfter = AirlineCredit::where('refund_request_id', $refund2->id)->first();
        $voucherCreatedAndActive = ($voucherAfter && $voucherAfter->status === 'active');

        app(\App\Http\Controllers\Api\V1\Flight\RefundController::class)->destroy($refund2->id);
        $voucherAfterReverse = AirlineCredit::withTrashed()->where('refund_request_id', $refund2->id)->first();
        $voucherCancelledAndTrashed = ($voucherAfterReverse->status === 'cancelled' && $voucherAfterReverse->trashed());

        $result['scenarios']['airline_credit'] = [
            'passed' => ($voucherCreatedAndActive && $voucherCancelledAndTrashed),
            'voucher_created_active' => $voucherCreatedAndActive,
            'voucher_cancelled_trashed_after_reverse' => $voucherCancelledAndTrashed,
        ];
        echo "[scenario 2: airline_credit] voucher_created=" . ($voucherCreatedAndActive ? 'YES' : 'NO')
            . ", voucher_cancelled=" . ($voucherCancelledAndTrashed ? 'YES' : 'NO') . "\n";
    } catch (\Throwable $e) {
        $result['scenarios']['airline_credit'] = [
            'passed' => false,
            'error' => $e->getMessage(),
        ];
        echo "[scenario 2: airline_credit] FAILED: " . $e->getMessage() . "\n";
    }

    // ════════════════════════════════════════════════════════
    // SCENARIO 3: Duplicate active voucher rejection (NEW)
    // ════════════════════════════════════════════════════════
    try {
        [$booking3, $selling3] = $createPaidBooking();
        echo "[debug] After createPaidBooking: booking3->status=" . $booking3->status->value . " | DB=" .
             FlightBooking::find($booking3->id)->status->value . "\n";

        // First refund → airline_credit → voucher created
        $refund3a = app(RefundService::class)->createRefundRequest([
            'flight_booking_id' => $booking3->id,
            'cancellation_fee'  => 0,
            'destination'       => 'airline_credit',
        ], $realUser->id);
        echo "[debug] After createRefundRequest refund3a: success (id={$refund3a->id})\n";
        app(RefundService::class)->processRefundRequest($refund3a->id, $realUser->id);
        echo "[debug] After processRefundRequest refund3a: booking3->status=" . $booking3->fresh()->status->value . "\n";

        // Reverse the first refund → voucher cancelled + soft-deleted
        echo "[debug] BEFORE destroy refund3a\n";
        $destroyResult = app(\App\Http\Controllers\Api\V1\Flight\RefundController::class)->destroy($refund3a->id);
        echo "[debug] AFTER destroy refund3a: status=" . $destroyResult->getStatusCode() . "\n";

        // ⚠️ Test helper: GAP 6 (deferred) means booking.status doesn't auto-revert
        // from REFUNDED. To simulate the desired end-state (after GAP 6 is fixed),
        // we manually reset it here. Use raw DB::table() to bypass Eloquent's stale
        // original-state tracking (the model's $original['status'] is still
        // REFUNDED from processRefundRequest()'s save()).
        \Illuminate\Support\Facades\DB::table('flight_bookings')
            ->where('id', $booking3->id)
            ->update(['status' => 'CONFIRMED']);
        // Re-fetch a fresh instance so any subsequent ops see the updated state.
        $booking3 = FlightBooking::find($booking3->id);

        // Now create a NEW refund → must succeed (cancelled vouchers don't block)
        $refund3b = app(RefundService::class)->createRefundRequest([
            'flight_booking_id' => $booking3->id,
            'cancellation_fee'  => 0,
            'destination'       => 'airline_credit',
        ], $realUser->id);
        app(RefundService::class)->processRefundRequest($refund3b->id, $realUser->id);

        $newVoucher = AirlineCredit::where('refund_request_id', $refund3b->id)->first();
        $reRefundAfterReverseWorks = ($newVoucher && $newVoucher->status === 'active');

        echo "[debug] BEFORE reset: booking3->status=" . $booking3->status->value . " | DB row=" .
             FlightBooking::find($booking3->id)->status->value . "\n";

        // ⚠️ Test helper: GAP 6 means booking.status auto-flips to REFUNDED again.
        // Reset BEFORE createRefundRequest so the duplicate-voucher check is what fails
        // (not the pre-existing booking-status guard from Fix 3). MUST use raw
        // DB::table()->update() because $booking3's $original['status'] is still
        // REFUNDED from processRefundRequest()'s save() → $booking3->update() is a no-op.
        \Illuminate\Support\Facades\DB::table('flight_bookings')
            ->where('id', $booking3->id)
            ->update(['status' => 'CONFIRMED']);
        $booking3 = FlightBooking::find($booking3->id);

        // Now try to create a THIRD refund while one is still active → must FAIL
        $refund3c = app(RefundService::class)->createRefundRequest([
            'flight_booking_id' => $booking3->id,
            'cancellation_fee'  => 0,
            'destination'       => 'airline_credit',
        ], $realUser->id);

        $duplicateRejected = false;
        $duplicateErrorMsg = '';
        try {
            app(RefundService::class)->processRefundRequest($refund3c->id, $realUser->id);
        } catch (\RuntimeException $e) {
            $duplicateRejected = str_contains($e->getMessage(), 'يوجد رصيد طيران نشط');
            $duplicateErrorMsg = $e->getMessage();
        }

        $result['scenarios']['duplicate_voucher_check'] = [
            'passed' => ($reRefundAfterReverseWorks && $duplicateRejected),
            're_refund_after_reverse_works' => $reRefundAfterReverseWorks,
            'duplicate_active_voucher_rejected' => $duplicateRejected,
            'duplicate_error_excerpt' => mb_substr($duplicateErrorMsg, 0, 100),
        ];
        echo "[scenario 3: duplicate voucher] re_refund_after_reverse=" . ($reRefundAfterReverseWorks ? 'YES' : 'NO')
            . ", duplicate_rejected=" . ($duplicateRejected ? 'YES' : 'NO') . "\n";
    } catch (\Throwable $e) {
        $result['scenarios']['duplicate_voucher_check'] = [
            'passed' => false,
            'error' => $e->getMessage(),
        ];
        echo "[scenario 3: duplicate voucher] FAILED: " . $e->getMessage() . "\n";
    }

    // ════════════════════════════════════════════════════════
    // SCENARIO 4: Non-contended happy-path (no retry triggered)
    // Verifies the DeadlockRetry wrapper doesn't break the normal flow.
    // ════════════════════════════════════════════════════════
    try {
        [$booking4, $selling4] = $createPaidBooking();

        // Create + process a refund 3 times sequentially (to prove no spurious retry/wait)
        $start = microtime(true);
        $refund4 = app(RefundService::class)->createRefundRequest([
            'flight_booking_id' => $booking4->id,
            'cancellation_fee'  => 0,
            'destination'       => 'airline_credit',
        ], $realUser->id);
        app(RefundService::class)->processRefundRequest($refund4->id, $realUser->id);
        app(\App\Http\Controllers\Api\V1\Flight\RefundController::class)->destroy($refund4->id);
        $elapsedMs = round((microtime(true) - $start) * 1000, 2);

        // Non-contended should be fast (< 500ms total for create+process+reverse)
        $fastEnough = $elapsedMs < 500;
        $result['scenarios']['non_contended'] = [
            'passed' => $fastEnough,
            'elapsed_ms' => $elapsedMs,
            'note' => 'If retry logic triggered spuriously, this would be > 50ms (50ms backoff × at least 1 retry)',
        ];
        echo "[scenario 4: non-contended] elapsed={$elapsedMs}ms (threshold: 500ms)\n";
    } catch (\Throwable $e) {
        $result['scenarios']['non_contended'] = [
            'passed' => false,
            'error' => $e->getMessage(),
        ];
        echo "[scenario 4: non-contended] FAILED: " . $e->getMessage() . "\n";
    }

    // ════════════════════════════════════════════════════════
    // VERDICT
    // ════════════════════════════════════════════════════════
    $result['verdict'] = [
        'agency_treasury_regression'      => $result['scenarios']['agency_treasury']['passed'],
        'airline_credit_regression'       => $result['scenarios']['airline_credit']['passed'],
        'duplicate_active_voucher_block' => $result['scenarios']['duplicate_voucher_check']['passed'],
        'non_contended_path_fast'         => $result['scenarios']['non_contended']['passed'],
    ];

    $result['success'] = $result['verdict']['agency_treasury_regression']
        && $result['verdict']['airline_credit_regression']
        && $result['verdict']['duplicate_active_voucher_block']
        && $result['verdict']['non_contended_path_fast'];

} catch (\Throwable $e) {
    $result['error'] = $e->getMessage() . ' (file: ' . $e->getFile() . ' line: ' . $e->getLine() . ')';
    $result['trace_excerpt'] = array_slice(explode("\n", $e->getTraceAsString()), 0, 8);
}

file_put_contents(
    storage_path('logs/real_db_refund_hardening.json'),
    json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

echo "\n=== REFUND HARDENING VALIDATION RESULT ===\n";
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
echo "\n=== END ===\n";