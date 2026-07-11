<?php
/**
 * REAL DB VALIDATION: Treasury soft-delete + GL linking + boot guard
 *
 * Tests:
 *   1. Boot guard: direct \$treasury->current_balance = X; ->save() throws RuntimeException
 *   2. Refund process: Treasury.current_balance += amount, AND the new
 *      TreasuryTransaction row has ledger_transaction_id + account_id linked
 *      to the actual GL Transaction (not null).
 *   3. Refund reversal: Treasury.current_balance -= amount, AND the new
 *      TreasuryTransaction row is linked to the reversal GL Transaction.
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\RefundRequest;
use App\Models\Treasury;
use App\Models\TreasuryTransaction;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Services\Flight\RefundService;
use Illuminate\Support\Facades\Log;

$result = [
    'scenario' => 'Treasury — protection + GL linking + reverse cycle',
    'success'  => false,
    'error'    => null,
    'steps'    => [],
    'balances' => [],
    'verdict'  => [],
    'notes'    => [],
];

$realUser = User::orderBy('id')->first();
if (! $realUser) {
    $realUser = User::create([
        'name' => 'Treasury Validator',
        'email' => 'treasury-validator@test.com',
        'password' => bcrypt('test1234'),
        'role' => 'admin',
        'is_active' => true,
    ]);
}
auth()->setUser($realUser);
echo "[setup] User #{$realUser->id}\n";

$testEmployee = \App\Models\Employee::where('full_name', 'TEST_TREAS_VALIDATOR')->first();
if (! $testEmployee) {
    $testEmployee = \App\Models\Employee::create([
        'full_name' => 'TEST_TREAS_VALIDATOR',
        'phone' => '0000000000',
        'national_id' => 'TESTREAS1',
        'created_by' => $realUser->id,
    ]);
}

try {
    // ─────────────────────────────────────────────────────
    // Test 1: BOOT GUARD — direct current_balance mutation must throw
    // ─────────────────────────────────────────────────────
    $treasury = Treasury::firstOrCreate(
        ['name' => 'TEST_TREASURY_GUARD'],
        ['currency' => 'EGP', 'current_balance' => 0, 'is_active' => true]
    );

    $guardBlocked = false;
    $guardMessage = '';
    try {
        // Force-enable strict_test_guards temporarily so the guard actually throws
        // (in real production, runningUnitTests() is false → guard throws automatically).
        config(['accounting.strict_test_guards' => true]);
        $treasury->current_balance = 9999;
        $treasury->save();
        config(['accounting.strict_test_guards' => false]);
    } catch (\RuntimeException $e) {
        $guardBlocked = true;
        $guardMessage = $e->getMessage();
        config(['accounting.strict_test_guards' => false]);
    }

    $result['steps']['boot_guard_test'] = [
        'attempted_to_mutate' => true,
        'blocked' => $guardBlocked,
        'message_contains_arabic' => str_contains($guardMessage, 'لا يمكن تعديل رصيد الخزينة'),
        'message' => $guardMessage,
    ];
    echo "[boot_guard] blocked=" . ($guardBlocked ? 'YES' : 'NO') . "\n";

    // ─────────────────────────────────────────────────────
    // Setup booking + payment + treasury for the refund cycle
    // ─────────────────────────────────────────────────────
    $cashbox = Account::where('module_type', 'flights')
        ->where('type', 'cashbox')
        ->where('is_active', true)
        ->first();
    if (! $cashbox) {
        $cashbox = Account::create([
            'name' => 'TEST_CASHBOX_TREAS_VALIDATION',
            'type' => 'cashbox', 'currency' => 'EGP', 'balance' => 1000000,
            'is_active' => true, 'owner_type' => 'office', 'module_type' => 'flights',
            'created_by' => $realUser->id,
        ]);
    }

    $flightSystem = FlightSystem::where('name', 'TEST_SYSTEM_TREAS')->first();
    if (! $flightSystem) {
        $flightSystem = FlightSystem::create([
            'name' => 'TEST_SYSTEM_TREAS',
            'code' => 'TST'.substr(md5(uniqid()), 0, 4),
            'type' => 'gds', 'is_active' => true,
            'currency' => 'EGP', 'balance' => 0, 'credit_limit' => 0,
            'created_by' => $realUser->id,
        ]);
    }

    $carrier = FlightCarrier::where('name', 'TEST_CARRIER_TREAS')->first();
    if (! $carrier) {
        $carrier = FlightCarrier::create([
            'name' => 'TEST_CARRIER_TREAS',
            'code' => 'TCT'.substr(md5(uniqid()), 0, 4),
            'flight_system_id' => $flightSystem->id,
            'currency' => 'EGP', 'balance' => 0, 'credit_limit' => 0,
            'is_active' => true, 'created_by' => $realUser->id,
        ]);
        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $carrier, $cashbox, 500.0, 'Treasury validator setup'
        );
    }

    $customer = Customer::where('full_name', 'TEST_TREAS_CUSTOMER')->first();
    if (! $customer) {
        $customer = Customer::create([
            'full_name' => 'TEST_TREAS_CUSTOMER',
            'phone' => '0000000000',
            'passport' => 'TEST-TREAS-'.uniqid(),
            'created_by' => $realUser->id,
        ]);
    }

    // Create a fresh treasury for the validation (TEST_TREASURY_GUARD was used in guard test)
    $workingTreasury = Treasury::firstOrCreate(
        ['name' => 'TEST_TREASURY_GL_LINK_'.uniqid()],
        ['currency' => 'EGP', 'current_balance' => 0, 'is_active' => true]
    );
    $workingTreasury->current_balance = 1000.00;
    \App\Support\Finance\LedgerBalanceMutationGuard::run(function () use ($workingTreasury) {
        $workingTreasury->save();
    });
    $workingTreasury->refresh();
    $treasuryBaselineBalance = (float) $workingTreasury->current_balance;

    // Create booking + payment
    $booking = app(FlightBookingService::class)->createBooking([
        'booking_reference' => 'TREAS-'.uniqid(),
        'customer_id' => $customer->id,
        'employee_id' => $testEmployee->id,
        'airline' => 'TEST_AIRLINE', 'airline_name' => 'Treasury Validation',
        'origin' => 'TST', 'destination' => 'DEL',
        'from_airport' => 'Origin', 'to_airport' => 'Dest',
        'departure_date' => now()->addDays(7)->format('Y-m-d'),
        'departure_time' => '12:00:00',
        'trip_type' => 'one_way', 'passenger_count' => 1,
        'purchase_price' => 50, 'selling_price' => 100,
        'profit' => 50, 'currency' => $carrier->currency,
        'purchase_balance_source' => 'carrier',
        'flight_carrier_id' => $carrier->id,
        'account_id' => $cashbox->id,
        'passengers' => [['name' => 'TREAS PAX', 'type' => 'adult']],
    ]);
    app(FlightBookingService::class)->addPayment($booking, [
        'amount' => 100, 'payment_method' => 'cash', 'account_id' => $cashbox->id,
    ]);
    $booking->update(['status' => \App\Enums\FlightBookingStatus::CONFIRMED]);

    $result['steps']['booking_created'] = [
        'booking_id' => $booking->id,
        'treasury_id' => $workingTreasury->id,
        'treasury_baseline_balance' => $treasuryBaselineBalance,
    ];

    // ─────────────────────────────────────────────────────
    // Test 2: REFUND PROCESS — Treasury.credit() + linked TreasuryTransaction
    // ─────────────────────────────────────────────────────
    $refundAmount = 90.0;
    $cancellationFee = 10.0;

    $refundRequest = app(RefundService::class)->createRefundRequest([
        'flight_booking_id' => $booking->id,
        'cancellation_fee'  => $cancellationFee,
        'refund_currency'   => 'EGP',
        'destination'       => 'agency_treasury',
        'treasury_id'       => $workingTreasury->id,
    ], $realUser->id);

    app(RefundService::class)->processRefundRequest($refundRequest->id, $realUser->id);

    $workingTreasury->refresh();
    $balanceAfterRefund = (float) $workingTreasury->current_balance;

    // Find the TreasuryTransaction created by the refund
    $treasuryTxAfterRefund = TreasuryTransaction::where('treasury_id', $workingTreasury->id)
        ->where('refund_request_id', $refundRequest->id)
        ->orderBy('id', 'desc')
        ->first();

    $result['steps']['refund_process'] = [
        'treasury_balance_after' => $balanceAfterRefund,
        'expected_balance_after' => $treasuryBaselineBalance + $refundAmount,
        'balance_delta' => round($balanceAfterRefund - $treasuryBaselineBalance, 2),
        'treasury_tx_id' => $treasuryTxAfterRefund?->id,
        'ledger_transaction_id' => $treasuryTxAfterRefund?->ledger_transaction_id,
        'account_id' => $treasuryTxAfterRefund?->account_id,
        'linked_to_gl' => $treasuryTxAfterRefund?->ledger_transaction_id !== null
                          && $treasuryTxAfterRefund?->account_id !== null,
    ];

    echo "[refund_process] balance={$balanceAfterRefund} (expected=" . ($treasuryBaselineBalance + $refundAmount) . ") | "
        . "tt.linked_to_gl=" . ($result['steps']['refund_process']['linked_to_gl'] ? 'YES' : 'NO') . "\n";

    // ─────────────────────────────────────────────────────
    // Test 3: REFUND REVERSAL — Treasury.debit() + linked TreasuryTransaction
    // ─────────────────────────────────────────────────────
    $controller = app(\App\Http\Controllers\Api\V1\Flight\RefundController::class);
    $controllerResult = $controller->destroy($refundRequest->id);

    $result['steps']['refund_reversal'] = [
        'http_status' => $controllerResult->getStatusCode(),
        'controller_class' => get_class($controller),
    ];

    if ($controllerResult->getStatusCode() >= 400) {
        throw new \RuntimeException('Reversal controller returned error: ' . $controllerResult->getContent());
    }

    $workingTreasury->refresh();
    $balanceAfterReversal = (float) $workingTreasury->current_balance;

    // Find the TreasuryTransaction created by the reversal
    $treasuryTxAfterReversal = TreasuryTransaction::where('treasury_id', $workingTreasury->id)
        ->where('refund_request_id', $refundRequest->id)
        ->where('transaction_type', 'debit')
        ->orderBy('id', 'desc')
        ->first();

    $result['steps']['reversal_treasury_tx'] = [
        'treasury_balance_after' => $balanceAfterReversal,
        'expected_balance_after' => $treasuryBaselineBalance, // back to original
        'balance_delta' => round($balanceAfterReversal - $treasuryBaselineBalance, 2),
        'treasury_tx_id' => $treasuryTxAfterReversal?->id,
        'ledger_transaction_id' => $treasuryTxAfterReversal?->ledger_transaction_id,
        'account_id' => $treasuryTxAfterReversal?->account_id,
        'linked_to_gl' => $treasuryTxAfterReversal?->ledger_transaction_id !== null
                          && $treasuryTxAfterReversal?->account_id !== null,
    ];

    echo "[refund_reversal] balance={$balanceAfterReversal} (expected={$treasuryBaselineBalance}) | "
        . "tt.linked_to_gl=" . ($result['steps']['reversal_treasury_tx']['linked_to_gl'] ? 'YES' : 'NO') . "\n";

    // ─────────────────────────────────────────────────────
    // Verdict
    // ─────────────────────────────────────────────────────
    $result['verdict'] = [
        'boot_guard_blocks_direct_mutation' => $result['steps']['boot_guard_test']['blocked'],
        'treasury_credited_on_refund'        => round($balanceAfterRefund - $treasuryBaselineBalance, 2) === $refundAmount,
        'process_treasury_tx_linked_to_gl'   => $result['steps']['refund_process']['linked_to_gl'],
        'treasury_debited_on_reversal'      => round($balanceAfterReversal - $treasuryBaselineBalance, 2) === 0.0,
        'reversal_treasury_tx_linked_to_gl' => $result['steps']['reversal_treasury_tx']['linked_to_gl'],
        'reversal_http_ok'                   => $controllerResult->getStatusCode() === 200,
    ];

    $result['notes'][] = 'The 14 existing orphan rows in treasury_transactions are NOT cleaned up (per user direction). They remain as historical data from before this fix.';
    $result['notes'][] = 'BusRefundService has the same fix but links to the supplier-side GL Transaction (Bus flow has no dedicated treasury GL tx — out of scope for this task).';

    $result['success'] = $result['verdict']['boot_guard_blocks_direct_mutation']
        && $result['verdict']['treasury_credited_on_refund']
        && $result['verdict']['process_treasury_tx_linked_to_gl']
        && $result['verdict']['treasury_debited_on_reversal']
        && $result['verdict']['reversal_treasury_tx_linked_to_gl']
        && $result['verdict']['reversal_http_ok'];

} catch (\Throwable $e) {
    $result['error'] = $e->getMessage() . ' (file: ' . $e->getFile() . ' line: ' . $e->getLine() . ')';
    $result['trace_excerpt'] = array_slice(explode("\n", $e->getTraceAsString()), 0, 8);
}

file_put_contents(
    storage_path('logs/real_db_treasury_gl.json'),
    json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

echo "\n=== TREASURY VALIDATION RESULT ===\n";
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
echo "\n=== END ===\n";