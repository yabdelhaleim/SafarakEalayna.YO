<?php
/**
 * ════════════════════════════════════════════════════════════════════════════
 * Comprehensive Ticket Refund Cycle Test Runner
 * ════════════════════════════════════════════════════════════════════════════
 */

define('LARAVEL_START', microtime(true));
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Configure in-memory SQLite for complete isolation and zero side effects
config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => ':memory:',
    'telescope.enabled' => false,
    'telescope.storage.database.connection' => 'sqlite',
]);

use App\Enums\FlightBookingStatus;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\RefundRequest;
use App\Models\Flight\CustomerCreditDisbursement;
use App\Models\Transaction;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\RefundService;
use App\Services\Finance\LedgerClearingAccounts;
use App\Support\UserPermissions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// Run migrations
echo "==> Running migrations in SQLite memory...\n";
Artisan::call('migrate', ['--force' => true]);

$testsPassed = 0;
$testsFailed = 0;
$testResults = [];

function assertTest(string $name, bool $condition, string $details = '') {
    global $testsPassed, $testsFailed, $testResults;
    if ($condition) {
        $testsPassed++;
        echo "  [PASS] {$name}\n";
        $testResults[] = ['name' => $name, 'status' => 'PASS', 'details' => $details];
    } else {
        $testsFailed++;
        echo "  [FAIL] {$name} - {$details}\n";
        $testResults[] = ['name' => $name, 'status' => 'FAIL', 'details' => $details];
    }
}

function createTestBooking($bookingService, $customer, $carrier, $cashbox, $sellingPrice = 7500, $cost = 7350, $paid = true): FlightBooking {
    $booking = $bookingService->createBooking([
        'customer_id'       => $customer->id,
        'airline_name'      => 'EgyptAir',
        'from_airport'      => 'CAI',
        'to_airport'        => 'JED',
        'departure_date'    => now()->addDays(7)->toDateString(),
        'trip_type'         => 'one_way',
        'currency'          => 'EGP',
        'purchase_price'    => $cost,
        'selling_price'     => $sellingPrice,
        'flight_carrier_id' => $carrier->id,
        'account_id'        => $cashbox->id,
        'passengers'        => [
            ['name' => 'Test Pax', 'type' => 'adult'],
        ],
    ]);

    if ($paid) {
        $bookingService->addPayment($booking, [
            'amount'         => $sellingPrice,
            'payment_method' => 'cash',
            'account_id'     => $cashbox->id,
            'notes'          => 'Full payment',
        ]);
    }

    return $booking->fresh();
}

// ─────────────────────────────────────────────────────────────────────────────
// Setup Base Entities
// ─────────────────────────────────────────────────────────────────────────────
$adminUser = User::create([
    'name' => 'Refund Admin',
    'email' => 'admin@safarak.com',
    'password' => bcrypt('password'),
    'role' => 'admin',
    'is_active' => true,
]);
Auth::login($adminUser);

$treasury = Treasury::create([
    'name' => 'Main Treasury EGP',
    'currency' => 'EGP',
    'current_balance' => 50000.00,
    'is_active' => true,
]);

$treasuryAccount = Account::create([
    'name' => 'Main Treasury EGP',
    'type' => 'cashbox',
    'balance' => 500000.00,
    'currency' => 'EGP',
    'is_active' => true,
    'owner_type' => 'office',
    'module_type' => 'office',
    'created_by' => $adminUser->id,
]);

$bankAccount = Account::create([
    'name' => 'CIB Bank Account',
    'type' => 'bank',
    'balance' => 500000.00,
    'currency' => 'EGP',
    'is_active' => true,
    'owner_type' => 'office',
    'module_type' => 'office',
    'created_by' => $adminUser->id,
]);

$flightSystem = FlightSystem::create([
    'name' => 'Amadeus GDS',
    'code' => '1A',
    'type' => 'gds',
    'is_active' => true,
    'currency' => 'EGP',
    'balance' => 0,
    'credit_limit' => 0,
    'created_by' => $adminUser->id,
]);

$carrier = FlightCarrier::create([
    'name' => 'EgyptAir',
    'code' => 'MS',
    'flight_system_id' => $flightSystem->id,
    'currency' => 'EGP',
    'balance' => 0.00,
    'credit_limit' => 100000.00,
    'is_active' => true,
    'created_by' => $adminUser->id,
]);

app(\App\Services\Flight\FlightCarrierRechargeService::class)->rechargeFromAccount(
    $carrier,
    $treasuryAccount,
    200000.00,
    'Initial carrier recharge for test suite'
);

$refundService = app(RefundService::class);
$bookingService = app(FlightBookingService::class);
$clearing = app(LedgerClearingAccounts::class);

echo "\n─────────────────────────────────────────────────────────────────────────────\n";
echo "SUITE 1: Reference Scenario (Literal 7,350 / 7,500 / 200 / 7,300)\n";
echo "─────────────────────────────────────────────────────────────────────────────\n";

$customer1 = Customer::create([
    'full_name' => 'Ahmed Ali',
    'phone' => '01000000001',
    'email' => 'ahmed@test.com',
]);

// Helper to create customer AR account if not auto created
$customerAccount1 = Account::firstOrCreate([
    'name' => 'Customer AR - ' . $customer1->id,
    'owner_type' => 'customer',
    'owner_id' => $customer1->id,
], [
    'type' => 'customer',
    'balance' => 0,
    'currency' => 'EGP',
    'is_active' => true,
    'module_type' => 'flights',
    'created_by' => $adminUser->id,
]);

// Initial booking: Cost = 7350, Selling Price = 7500
$booking1 = createTestBooking($bookingService, $customer1, $carrier, $treasuryAccount, 7500.00, 7350.00, true);

// Carrier balance initially 0
$carrierBalanceBefore = (float)$carrier->fresh()->balance;

// Create refund request for booking1
$refundReq1 = $refundService->createRefundRequest([
    'flight_booking_id' => $booking1->id,
    'cancellation_fee' => 200.00,
    'supplier_refund_amount' => 7350.00,
    'supplier_cost' => 7350.00,
    'destination' => 'airline_credit',
    'refund_currency' => 'EGP',
    'refund_exchange_rate' => 1.0,
], $adminUser->id);

assertTest("Ref Scenario: Refund request created with net 7300", 
    (float)$refundReq1->refund_amount === 7300.00 && (float)$refundReq1->cancellation_fee === 200.00,
    "Refund amount: {$refundReq1->refund_amount}, fee: {$refundReq1->cancellation_fee}"
);

// Process the refund
$processed1 = $refundService->processRefundRequest($refundReq1->id, $adminUser->id);

assertTest("Ref Scenario: Status is processed", $processed1->status === 'processed');
assertTest("Ref Scenario: Customer credit is exactly 7300", (float)$processed1->customer_credit_amount === 7300.00);
assertTest("Ref Scenario: Customer debt settled is 0 (customer had no unpaid bookings)", (float)$processed1->customer_debt_settled_amount === 0.00);
assertTest("Ref Scenario: Supplier refund loss is 0", (float)$processed1->supplier_refund_loss === 0.00);
assertTest("Ref Scenario: Booking status changed to refunded", $booking1->fresh()->status === FlightBookingStatus::REFUNDED);

// Check carrier balance change: Carrier was credited 7350
$carrierBalanceAfter = (float)$carrier->fresh()->balance;
assertTest("Ref Scenario: Carrier balance increased by 7350 (supplier credit)", 
    round($carrierBalanceAfter - $carrierBalanceBefore, 2) === 7350.00,
    "Before: {$carrierBalanceBefore}, After: {$carrierBalanceAfter}"
);

// Check Cancellation Fee Revenue account
$feeAccountId = $clearing->cancellationFeeRevenueAccountId();
$feeAccount = Account::find($feeAccountId);
assertTest("Ref Scenario: Cancellation fee revenue account exists", $feeAccount !== null);

// Check all transactions created for this refund have SUM(debit) == SUM(credit)
$txIds = AccountEntry::where('flight_booking_id', $booking1->id)
    ->pluck('transaction_id')->unique();
$balanced = true;
foreach ($txIds as $tId) {
    if (!$tId) continue;
    $d = (float)AccountEntry::where('transaction_id', $tId)->sum('debit');
    $c = (float)AccountEntry::where('transaction_id', $tId)->sum('credit');
    if (round($d, 2) !== round($c, 2)) {
        $balanced = false;
        break;
    }
}
assertTest("Ref Scenario: All GL journal entries balanced (Debit == Credit)", $balanced);


echo "\n─────────────────────────────────────────────────────────────────────────────\n";
echo "SUITE 2: Supplier Variations (Debt Settled FIFO, Supplier Credit, Airline Penalty)\n";
echo "─────────────────────────────────────────────────────────────────────────────\n";

// Case 2.1: Airline Penalty / Reduced Refund (Cost 7350, Airline refunds 7000, 350 penalty)
$booking2 = createTestBooking($bookingService, $customer1, $carrier, $treasuryAccount, 7500.00, 7350.00, true);

$refundReq2 = $refundService->createRefundRequest([
    'flight_booking_id' => $booking2->id,
    'cancellation_fee' => 200.00,
    'supplier_refund_amount' => 7000.00, // Reduced by 350 penalty
    'supplier_cost' => 7350.00,
    'destination' => 'airline_credit',
], $adminUser->id);

$carrierBalBefore2 = (float)$carrier->fresh()->balance;
$processed2 = $refundService->processRefundRequest($refundReq2->id, $adminUser->id);

assertTest("Supplier Penalty: Supplier refund loss calculated as 350", (float)$processed2->supplier_refund_loss === 350.00);
assertTest("Supplier Penalty: Carrier balance increased only by 7000", round((float)$carrier->fresh()->balance - $carrierBalBefore2, 2) === 7000.00);
assertTest("Supplier Penalty: Customer still gets full 7300 credit (not penalized)", (float)$processed2->customer_credit_amount === 7300.00);

$lossAccountId = $clearing->refundLossAccountId();
$lossAccount = Account::find($lossAccountId);
assertTest("Supplier Penalty: Refund loss account exists", $lossAccount !== null);


echo "\n─────────────────────────────────────────────────────────────────────────────\n";
echo "SUITE 3: Customer Debt Settlements (FIFO Allocation)\n";
echo "─────────────────────────────────────────────────────────────────────────────\n";

$customer2 = Customer::create([
    'full_name' => 'Sara Ibrahim',
    'phone' => '01000000002',
    'email' => 'sara@test.com',
]);

// Create two unpaid bookings for Sara:
// Unpaid Booking A: 3000 EGP (created first)
$unpaidBookingA = createTestBooking($bookingService, $customer2, $carrier, $treasuryAccount, 3000.00, 2500.00, false);

// Unpaid Booking B: 2000 EGP (created second)
$unpaidBookingB = createTestBooking($bookingService, $customer2, $carrier, $treasuryAccount, 2000.00, 1500.00, false);

// Paid booking to refund: Net refund 7300
$bookingToRefund = createTestBooking($bookingService, $customer2, $carrier, $treasuryAccount, 7500.00, 7350.00, true);

$refundReq3 = $refundService->createRefundRequest([
    'flight_booking_id' => $bookingToRefund->id,
    'cancellation_fee' => 200.00,
    'supplier_refund_amount' => 7350.00,
    'destination' => 'airline_credit',
], $adminUser->id);

$processed3 = $refundService->processRefundRequest($refundReq3->id, $adminUser->id);

// Total debt was 3000 + 2000 = 5000.
// Net refund is 7300.
// FIFO should settle UNPAID-A completely (3000), UNPAID-B completely (2000), total settled = 5000.
// Remaining 2300 becomes customer credit.
assertTest("FIFO Debt: Customer debt settled is 5000", (float)$processed3->customer_debt_settled_amount === 5000.00, "Settled: {$processed3->customer_debt_settled_amount}");
assertTest("FIFO Debt: Customer credit is 2300 (7300 - 5000)", (float)$processed3->customer_credit_amount === 2300.00, "Credit: {$processed3->customer_credit_amount}");
assertTest("FIFO Debt: Oldest booking UNPAID-A fully paid", $unpaidBookingA->fresh()->computePaymentStatus() === 'paid' && (float)$unpaidBookingA->fresh()->remaining_amount === 0.00);
assertTest("FIFO Debt: Second booking UNPAID-B fully paid", $unpaidBookingB->fresh()->computePaymentStatus() === 'paid' && (float)$unpaidBookingB->fresh()->remaining_amount === 0.00);


echo "\n─────────────────────────────────────────────────────────────────────────────\n";
echo "SUITE 4: Cancellation Fee Edge Cases & Validations\n";
echo "─────────────────────────────────────────────────────────────────────────────\n";

// Fee = 0
$bookingFee0 = createTestBooking($bookingService, $customer1, $carrier, $treasuryAccount, 6000.00, 5000.00, true);

$reqFee0 = $refundService->createRefundRequest([
    'flight_booking_id' => $bookingFee0->id,
    'cancellation_fee' => 0.00,
    'destination' => 'airline_credit',
], $adminUser->id);
$procFee0 = $refundService->processRefundRequest($reqFee0->id, $adminUser->id);
assertTest("Fee 0: Net customer refund equals full selling price (6000)", (float)$procFee0->refund_amount === 6000.00);

// Fee == Selling Price (Customer receives 0)
$bookingFeeFull = createTestBooking($bookingService, $customer1, $carrier, $treasuryAccount, 6000.00, 5000.00, true);

$reqFeeFull = $refundService->createRefundRequest([
    'flight_booking_id' => $bookingFeeFull->id,
    'cancellation_fee' => 6000.00,
    'destination' => 'airline_credit',
], $adminUser->id);
$procFeeFull = $refundService->processRefundRequest($reqFeeFull->id, $adminUser->id);
assertTest("Fee == Selling Price: Customer refund is 0", (float)$procFeeFull->refund_amount === 0.00 && (float)$procFeeFull->customer_credit_amount === 0.00);

// Fee > Selling Price: Must be rejected
$feeRejected = false;
try {
    $bookingFeeOver = createTestBooking($bookingService, $customer1, $carrier, $treasuryAccount, 6000.00, 5000.00, true);
    $refundService->createRefundRequest([
        'flight_booking_id' => $bookingFeeOver->id,
        'cancellation_fee' => 7000.00, // > 6000
        'destination' => 'airline_credit',
    ], $adminUser->id);
} catch (\InvalidArgumentException $e) {
    $feeRejected = true;
}
assertTest("Validation: Fee > Selling price is rejected", $feeRejected);


echo "\n─────────────────────────────────────────────────────────────────────────────\n";
echo "SUITE 5: Idempotency & Duplicate Prevention\n";
echo "─────────────────────────────────────────────────────────────────────────────\n";

// Attempt to create second refund request for an already refunded booking
$dupRejected = false;
try {
    $refundService->createRefundRequest([
        'flight_booking_id' => $booking1->id, // Already refunded in Suite 1
        'destination' => 'airline_credit',
    ], $adminUser->id);
} catch (\Throwable $e) {
    $dupRejected = true;
}
assertTest("Idempotency: Re-refunding an already refunded booking is rejected", $dupRejected);

// Attempt to double-process an already processed refund request
$carrierBalBeforeDup = (float)$carrier->fresh()->balance;
$doubleProc = $refundService->processRefundRequest($processed1->id, $adminUser->id);
$carrierBalAfterDup = (float)$carrier->fresh()->balance;
assertTest("Idempotency: Double process call is idempotent and does not duplicate GL", 
    $doubleProc->id === $processed1->id && $carrierBalBeforeDup === $carrierBalAfterDup
);


echo "\n─────────────────────────────────────────────────────────────────────────────\n";
echo "SUITE 6: Atomic Rollback on Failure\n";
echo "─────────────────────────────────────────────────────────────────────────────\n";

$bookingFail = createTestBooking($bookingService, $customer1, $carrier, $treasuryAccount, 4500.00, 4000.00, true);

$reqFail = $refundService->createRefundRequest([
    'flight_booking_id' => $bookingFail->id,
    'cancellation_fee' => 100.00,
    'destination' => 'airline_credit',
], $adminUser->id);

$carrierBalBeforeFail = (float)$carrier->fresh()->balance;
$rolledBack = false;

// We simulate a mid-flight failure inside a test transaction wrapper
try {
    DB::transaction(function () use ($refundService, $reqFail, $adminUser) {
        $refundService->processRefundRequest($reqFail->id, $adminUser->id);
        throw new \RuntimeException("Simulated crash mid-process!");
    });
} catch (\RuntimeException $e) {
    $rolledBack = true;
}

assertTest("Atomicity: Simulated crash triggers exception", $rolledBack);
assertTest("Atomicity: Carrier balance completely unchanged after rollback", (float)$carrier->fresh()->balance === $carrierBalBeforeFail);
assertTest("Atomicity: Booking remains in original confirmed state", $bookingFail->fresh()->status === FlightBookingStatus::CONFIRMED);


echo "\n─────────────────────────────────────────────────────────────────────────────\n";
echo "SUITE 7: Customer Credit Disbursement & Safety Gate\n";
echo "─────────────────────────────────────────────────────────────────────────────\n";

// We have $processed1 with customer_credit_amount = 7300, unconfirmed supplier refund.
assertTest("Safety Gate: Supplier refund initially unconfirmed", (bool)$processed1->supplier_refund_confirmed === false);

$agentUser = User::create([
    'name' => 'Booking Agent',
    'email' => 'agent@test.com',
    'password' => bcrypt('password'),
    'role' => 'agent',
    'is_active' => true,
]);

// Attempt cash payout without confirmation or force permission (as agentUser)
$gateBlocked = false;
try {
    $refundService->disburseCustomerCredit($processed1->id, [
        'type' => 'cash_treasury',
        'amount' => 2000.00,
        'treasury_id' => $treasury->id,
    ], $agentUser);
} catch (\Throwable $e) {
    $gateBlocked = str_contains($e->getMessage(), 'تأكيد استرداد المورد');
}
assertTest("Safety Gate: Cash disbursement blocked without supplier confirmation", $gateBlocked);

// Confirm supplier refund
$confirmedReq = $refundService->confirmSupplierRefund($processed1->id, $adminUser->id);
assertTest("Safety Gate: Supplier refund confirmed successfully", (bool)$confirmedReq->supplier_refund_confirmed === true);

// Attempt payout exceeding available credit (e.g. 8000 when credit is 7300)
$overDisburseBlocked = false;
try {
    $refundService->disburseCustomerCredit($processed1->id, [
        'type' => 'cash_treasury',
        'amount' => 8000.00,
        'treasury_id' => $treasury->id,
    ], $adminUser);
} catch (\Throwable $e) {
    $overDisburseBlocked = true;
}
assertTest("Disbursement: Payout exceeding available credit (8000 > 7300) rejected", $overDisburseBlocked);

// Partial disbursement: Cash 3000 EGP from Treasury
$treasuryBalBefore = (float)$treasury->fresh()->current_balance;
$disbursement1 = $refundService->disburseCustomerCredit($processed1->id, [
    'type' => 'cash_treasury',
    'amount' => 3000.00,
    'treasury_id' => $treasury->id,
    'notes' => 'Partial cash refund test',
], $adminUser);

$processed1->refresh();
assertTest("Disbursement 1: Cash 3000 disbursed", (float)$disbursement1->amount === 3000.00);
assertTest("Disbursement 1: Treasury balance reduced by 3000", round($treasuryBalBefore - (float)$treasury->fresh()->current_balance, 2) === 3000.00);
assertTest("Disbursement 1: Status is partially_disbursed", $processed1->disbursement_status === 'partially_disbursed');
assertTest("Disbursement 1: Total disbursed is 3000, remaining credit is 4300", 
    (float)$processed1->disbursed_amount === 3000.00 && (float)$processed1->available_customer_credit === 4300.00
);

// Full disbursement: Bank transfer for remaining 4300 EGP
$bankBalBefore = (float)$bankAccount->fresh()->balance;
$disbursement2 = $refundService->disburseCustomerCredit($processed1->id, [
    'type' => 'bank_transfer',
    'amount' => 4300.00,
    'bank_account_id' => $bankAccount->id,
    'notes' => 'Remaining refund via CIB bank',
], $adminUser);

$processed1->refresh();
assertTest("Disbursement 2: Bank transfer 4300 disbursed", (float)$disbursement2->amount === 4300.00);
assertTest("Disbursement 2: Status changed to fully_disbursed", $processed1->disbursement_status === 'fully_disbursed');
assertTest("Disbursement 2: Remaining customer credit is 0", (float)$processed1->available_customer_credit === 0.00);

// Settle another booking via manual credit disbursement
$customer3 = Customer::create([
    'full_name' => 'Kareem Tarek',
    'phone' => '01000000003',
    'email' => 'kareem@test.com',
]);

// 1. Create paid booking for customer3 and refund it (customer has NO other unpaid bookings)
$bookingCreditForTarget = createTestBooking($bookingService, $customer3, $carrier, $treasuryAccount, 1500.00, 1500.00, true);
$reqForTarget = $refundService->createRefundRequest([
    'flight_booking_id' => $bookingCreditForTarget->id,
    'destination' => 'airline_credit',
], $adminUser->id);
$procForTarget = $refundService->processRefundRequest($reqForTarget->id, $adminUser->id);
assertTest("Disbursement 3: Full 1500 recorded as customer credit (no debt)", (float)$procForTarget->customer_credit_amount === 1500.00);

// 2. Now create a new unpaid booking for customer3 (1200 EGP)
$targetBooking = createTestBooking($bookingService, $customer3, $carrier, $treasuryAccount, 1200.00, 1000.00, false);

// 3. Manually disburse 1200 of the 1500 credit to settle this booking
$disbTarget = $refundService->disburseCustomerCredit($procForTarget->id, [
    'type' => 'booking_settlement',
    'amount' => 1200.00,
    'target_flight_booking_id' => $targetBooking->id,
], $adminUser);

assertTest("Disbursement 3: Settle target booking via manual credit disbursement", 
    $targetBooking->fresh()->computePaymentStatus() === 'paid' && (float)$targetBooking->fresh()->remaining_amount === 0.00
);
assertTest("Disbursement 3: Remaining credit is 300 (1500 - 1200)", (float)$procForTarget->fresh()->available_customer_credit === 300.00);


echo "\n─────────────────────────────────────────────────────────────────────────────\n";
echo "SUITE 9: Case 1 — Customer Voluntarily Cancels (Airline Penalty + Agency Fee)\n";
echo "─────────────────────────────────────────────────────────────────────────────\n";

// Scenario: Selling Price 7500, Supplier Cost 7350
// Airline Penalty: 1000 (imposed by airline, borne entirely by customer)
// Agency Commission: 200 (our fee)
// Expected from airline to supplier: 7350 - 1000 = 6350
// Expected refund to customer: 7500 - 1000 - 200 = 6300

$customerCase1 = Customer::create([
    'full_name' => 'Case 1 Customer',
    'phone' => '01011111111',
    'email' => 'case1@test.com',
]);

$bookingCase1 = createTestBooking($bookingService, $customerCase1, $carrier, $treasuryAccount, 7500.00, 7350.00, true);

// 1. Preview step
$previewCase1 = $refundService->previewRefund([
    'flight_booking_id' => $bookingCase1->id,
    'refund_case' => 'customer_voluntary',
    'airline_penalty' => 1000.00,
    'agency_commission' => 200.00,
]);

assertTest("Case 1 Preview: Net customer refund is 6300 (7500 - 1000 - 200)", (float)$previewCase1['net_customer_refund'] === 6300.00);
assertTest("Case 1 Preview: Expected supplier refund is 6350 (7350 - 1000)", (float)$previewCase1['expected_supplier_refund'] === 6350.00);
assertTest("Case 1 Preview: Supplier refund loss is 0 (borne by customer)", (float)$previewCase1['supplier_refund_loss'] === 0.00);
assertTest("Case 1 Preview: Expected customer credit is 6300 (no prior debt)", (float)$previewCase1['expected_customer_credit'] === 6300.00);

// 2. Execution step with same numbers
$reqCase1 = $refundService->createRefundRequest([
    'flight_booking_id' => $bookingCase1->id,
    'refund_case' => 'customer_voluntary',
    'airline_penalty' => 1000.00,
    'agency_commission' => 200.00,
    'destination' => 'customer_credit',
], $adminUser->id);

assertTest("Case 1 Request: Created with refund_case customer_voluntary", $reqCase1->refund_case === 'customer_voluntary');
assertTest("Case 1 Request: Cancellation fee total stored as 1200", (float)$reqCase1->cancellation_fee === 1200.00);
assertTest("Case 1 Request: Refund amount is 6300", (float)$reqCase1->refund_amount === 6300.00);

$procCase1 = $refundService->processRefundRequest($reqCase1->id, $adminUser->id);

assertTest("Case 1 Process: Net customer refund is 6300", (float)$procCase1->refund_amount === 6300.00);
assertTest("Case 1 Process: Customer credit amount is 6300", (float)$procCase1->customer_credit_amount === 6300.00);
assertTest("Case 1 Process: Linked to CustomerCreditLedger entry", $procCase1->credit_ledger_entry_id !== null);

$creditEntry1 = \App\Models\CustomerCreditLedger::find($procCase1->credit_ledger_entry_id);
assertTest("Case 1 Ledger: Entry has amount +6300", $creditEntry1 && (float)$creditEntry1->amount === 6300.00);
assertTest("Case 1 Ledger: Customer available credit is 6300", (float)\App\Models\CustomerCreditLedger::getAvailableCredit($customerCase1->id) === 6300.00);


echo "\n─────────────────────────────────────────────────────────────────────────────\n";
echo "SUITE 10: Case 1 Variance — Airline Confirms Less Than Expected\n";
echo "─────────────────────────────────────────────────────────────────────────────\n";

// Expected was 6350. Airline returns only 6100 (variance 250).
// Difference must go to 'refund_variance' account, customer unaffected!
$carrierBalanceBefore = (float)$carrier->fresh()->balance;

$confirmedReq1 = $refundService->confirmSupplierRefund($procCase1->id, $adminUser->id, 6100.00);

assertTest("Variance: supplier_refund_confirmed is true", $confirmedReq1->supplier_refund_confirmed === true);
assertTest("Variance: actual_airline_refund_amount is 6100", (float)$confirmedReq1->actual_airline_refund_amount === 6100.00);
assertTest("Variance: refund_variance_amount is 250", (float)$confirmedReq1->refund_variance_amount === 250.00);
assertTest("Variance: Carrier balance adjusted down by 250", round($carrierBalanceBefore - (float)$carrier->fresh()->balance, 2) === 250.00);
assertTest("Variance: Customer credit unchanged at 6300", (float)$confirmedReq1->customer_credit_amount === 6300.00);
assertTest("Variance: Customer ledger available credit remains 6300", (float)\App\Models\CustomerCreditLedger::getAvailableCredit($customerCase1->id) === 6300.00);

$clearingAccounts = app(LedgerClearingAccounts::class);
$varianceAccountId = $clearingAccounts->refundVarianceAccountId();
$varianceTx = Transaction::where('to_account_id', $varianceAccountId)->latest('id')->first();
assertTest("Variance GL: Journal transfer posted 250 to refund_variance account", $varianceTx && (float)$varianceTx->amount === 250.00);


echo "\n─────────────────────────────────────────────────────────────────────────────\n";
echo "SUITE 11: Case 2 — Airline Cancels Flight\n";
echo "─────────────────────────────────────────────────────────────────────────────\n";

// Variation 2.1: Full refund with commission
// Selling 7500, Cost 7350, apply_commission true (200) -> Customer gets 7300
$bookingC21 = createTestBooking($bookingService, $customerCase1, $carrier, $treasuryAccount, 7500.00, 7350.00, true);
$reqC21 = $refundService->createRefundRequest([
    'flight_booking_id' => $bookingC21->id,
    'refund_case' => 'airline_cancelled',
    'airline_refund_amount' => 7350.00,
    'apply_commission' => true,
    'agency_commission' => 200.00,
    'destination' => 'customer_credit',
], $adminUser->id);
$procC21 = $refundService->processRefundRequest($reqC21->id, $adminUser->id);
assertTest("Case 2.1: Full refund with commission -> customer gets 7300", (float)$procC21->refund_amount === 7300.00);

// Variation 2.2: Full refund without commission
// Selling 7500, Cost 7350, apply_commission false -> Customer gets 7500
$bookingC22 = createTestBooking($bookingService, $customerCase1, $carrier, $treasuryAccount, 7500.00, 7350.00, true);
$reqC22 = $refundService->createRefundRequest([
    'flight_booking_id' => $bookingC22->id,
    'refund_case' => 'airline_cancelled',
    'airline_refund_amount' => 7350.00,
    'apply_commission' => false,
    'destination' => 'customer_credit',
], $adminUser->id);
$procC22 = $refundService->processRefundRequest($reqC22->id, $adminUser->id);
assertTest("Case 2.2: Full refund without commission -> customer gets 7500", (float)$procC22->refund_amount === 7500.00);

// Variation 2.3: Non-refundable taxes (250) on customer (selling 7500, cost 7350, airline refund 7100)
// Customer gets 7500 - 250 = 7250
$bookingC23 = createTestBooking($bookingService, $customerCase1, $carrier, $treasuryAccount, 7500.00, 7350.00, true);
$reqC23 = $refundService->createRefundRequest([
    'flight_booking_id' => $bookingC23->id,
    'refund_case' => 'airline_cancelled',
    'airline_refund_amount' => 7100.00,
    'non_refundable_taxes_amount' => 250.00,
    'non_refundable_taxes_on_customer' => true,
    'apply_commission' => false,
    'destination' => 'customer_credit',
], $adminUser->id);
$procC23 = $refundService->processRefundRequest($reqC23->id, $adminUser->id);
assertTest("Case 2.3: Non-refundable taxes on customer -> customer gets 7250", (float)$procC23->refund_amount === 7250.00);
assertTest("Case 2.3: Supplier refund loss is 0 (customer bears taxes)", (float)$procC23->supplier_refund_loss === 0.00);

// Variation 2.4: Non-refundable taxes (250) on agency (selling 7500, cost 7350, airline refund 7100)
// Customer gets full 7500, agency absorbs 250 in refund_loss
$bookingC24 = createTestBooking($bookingService, $customerCase1, $carrier, $treasuryAccount, 7500.00, 7350.00, true);
$reqC24 = $refundService->createRefundRequest([
    'flight_booking_id' => $bookingC24->id,
    'refund_case' => 'airline_cancelled',
    'airline_refund_amount' => 7100.00,
    'non_refundable_taxes_amount' => 250.00,
    'non_refundable_taxes_on_customer' => false,
    'apply_commission' => false,
    'destination' => 'customer_credit',
], $adminUser->id);
$procC24 = $refundService->processRefundRequest($reqC24->id, $adminUser->id);
assertTest("Case 2.4: Taxes on agency -> customer gets full 7500", (float)$procC24->refund_amount === 7500.00);
assertTest("Case 2.4: Taxes booked as supplier_refund_loss = 250", (float)$procC24->supplier_refund_loss === 250.00);

$lossAccountId = $clearingAccounts->refundLossAccountId();
$lossTx = Transaction::where('to_account_id', $lossAccountId)->latest('id')->first();
assertTest("Case 2.4 GL: Journal transfer posted 250 to refund_loss account", $lossTx && (float)$lossTx->amount === 250.00);


echo "\n─────────────────────────────────────────────────────────────────────────────\n";
echo "SUITE 12: Case 3 — Overpayment (دفعة زيادة) Turned to Customer Credit\n";
echo "─────────────────────────────────────────────────────────────────────────────\n";

$customerCase3 = Customer::create([
    'full_name' => 'Overpaying Customer',
    'phone' => '01033333333',
    'email' => 'overpay@test.com',
]);

// Booking selling price 7500, customer pays 8000 -> 500 excess
$bookingCase3 = createTestBooking($bookingService, $customerCase3, $carrier, $treasuryAccount, 7500.00, 7000.00, false);
assertTest("Case 3: Unpaid booking has remaining 7500", (float)$bookingCase3->remaining_amount === 7500.00);

$overpayPayment = $bookingService->addPayment($bookingCase3, [
    'amount' => 8000.00,
    'payment_method' => 'cash',
    'account_id' => $treasuryAccount->id,
    'notes' => 'Customer sent 8000 EGP for 7500 booking',
]);

assertTest("Case 3: Booking paid amount capped at selling price 7500", (float)$bookingCase3->fresh()->paid_amount === 7500.00);
assertTest("Case 3: Booking remaining amount is 0", (float)$bookingCase3->fresh()->remaining_amount === 0.00);
assertTest("Case 3: Booking status confirmed", $bookingCase3->fresh()->status === FlightBookingStatus::CONFIRMED);

$overpayLedger = \App\Models\CustomerCreditLedger::where('customer_id', $customerCase3->id)
    ->where('source_type', 'overpayment')
    ->first();

assertTest("Case 3: Overpayment entry created in CustomerCreditLedger with +500", $overpayLedger && (float)$overpayLedger->amount === 500.00);
assertTest("Case 3: Customer available credit in ledger is 500", (float)\App\Models\CustomerCreditLedger::getAvailableCredit($customerCase3->id) === 500.00);


echo "\n─────────────────────────────────────────────────────────────────────────────\n";
echo "SUITE 13: Unified Customer Credit Consumption\n";
echo "─────────────────────────────────────────────────────────────────────────────\n";

// Use the 500 available credit from Case 3 to settle part of a new booking (selling price 1200)
$newBooking = createTestBooking($bookingService, $customerCase3, $carrier, $treasuryAccount, 1200.00, 1000.00, false);
assertTest("Credit Consumption: New booking remaining is 1200", (float)$newBooking->remaining_amount === 1200.00);

$consumedAllocation = $bookingService->consumeCustomerCredit($newBooking, 500.00, $adminUser->id);

assertTest("Credit Consumption: Ledger debit created for -500", (float)$consumedAllocation->amount === -500.00);
assertTest("Credit Consumption: Booking paid is now 500", (float)$newBooking->fresh()->paid_amount === 500.00);
assertTest("Credit Consumption: Booking remaining is now 700", (float)$newBooking->fresh()->remaining_amount === 700.00);
assertTest("Credit Consumption: Customer available credit in ledger is now 0", (float)\App\Models\CustomerCreditLedger::getAvailableCredit($customerCase3->id) === 0.00);


echo "\n─────────────────────────────────────────────────────────────────────────────\n";
echo "SUITE 14: Corporate Company Credit & Debt Resolution\n";
echo "─────────────────────────────────────────────────────────────────────────────\n";

$companyAccount = Customer::create([
    'full_name' => 'El Safwa Tourism Co.',
    'phone' => '01044444444',
    'email' => 'corp@elsafwa.com',
    'type' => \App\Enums\CustomerType::Company,
]);

$employeeCustomer = Customer::create([
    'full_name' => 'Ahmed Employee',
    'phone' => '01055555555',
    'email' => 'ahmed@elsafwa.com',
    'type' => \App\Enums\CustomerType::Individual,
    'company_id' => $companyAccount->id,
]);

// 1. Company has an unpaid booking (debt 2000)
$companyDebtBooking = createTestBooking($bookingService, $companyAccount, $carrier, $treasuryAccount, 2000.00, 1800.00, false);
assertTest("Corporate: Company has unpaid debt of 2000", (float)$companyDebtBooking->remaining_amount === 2000.00);

// 2. Employee has a paid booking (3500) that gets refunded
$empBooking = createTestBooking($bookingService, $employeeCustomer, $carrier, $treasuryAccount, 3500.00, 3300.00, true);
$empRefund = $refundService->createRefundRequest([
    'flight_booking_id' => $empBooking->id,
    'refund_case' => 'airline_cancelled',
    'apply_commission' => false,
    'destination' => 'customer_credit',
], $adminUser->id);

assertTest("Corporate: Refund request auto-resolved company_id", (int)$empRefund->company_id === (int)$companyAccount->id);

$empProc = $refundService->processRefundRequest($empRefund->id, $adminUser->id);

assertTest("Corporate: 2000 of the 3500 refund settled company debt FIFO", (float)$empProc->customer_debt_settled_amount === 2000.00);
assertTest("Corporate: Company debt booking is now fully paid", (float)$companyDebtBooking->fresh()->remaining_amount === 0.00);
assertTest("Corporate: Remaining 1500 credited to company account", (float)$empProc->customer_credit_amount === 1500.00);
assertTest("Corporate: Company available credit in ledger is 1500", (float)\App\Models\CustomerCreditLedger::getAvailableCredit(null, $companyAccount->id) === 1500.00);


echo "\n─────────────────────────────────────────────────────────────────────────────\n";
echo "SUITE 15: Credit Ledger Immutability & Reversal\n";
echo "─────────────────────────────────────────────────────────────────────────────\n";

$testCustRev = Customer::create([
    'full_name' => 'Reversal Test Customer',
    'phone' => '01066666666',
    'email' => 'rev@test.com',
]);

$entryToRev = \App\Models\CustomerCreditLedger::recordEntry([
    'customer_id' => $testCustRev->id,
    'source_type' => 'deposit',
    'source_id' => 999,
    'amount' => 1000.00,
    'currency' => 'EGP',
    'notes' => 'Test deposit',
    'created_by' => $adminUser->id,
]);

assertTest("Ledger Reversal: Initial credit is 1000", (float)\App\Models\CustomerCreditLedger::getAvailableCredit($testCustRev->id) === 1000.00);

$revResult = $entryToRev->reverse($adminUser->id, 'Mistaken deposit');

assertTest("Ledger Reversal: Counter entry created with -1000", (float)$revResult->amount === -1000.00);
assertTest("Ledger Reversal: Original entry marked reversed", $entryToRev->fresh()->reversed_at !== null);
assertTest("Ledger Reversal: Available credit is now 0.00", (float)\App\Models\CustomerCreditLedger::getAvailableCredit($testCustRev->id) === 0.00);


echo "\n─────────────────────────────────────────────────────────────────────────────\n";
echo "SUITE 8: Comprehensive GL Balance Equality Across All Created Transactions\n";
echo "─────────────────────────────────────────────────────────────────────────────\n";

$allTransactions = Transaction::all();
$allBalanced = true;
$checkedCount = 0;
foreach ($allTransactions as $t) {
    $debits = (float)AccountEntry::where('transaction_id', $t->id)->sum('debit');
    $credits = (float)AccountEntry::where('transaction_id', $t->id)->sum('credit');
    $checkedCount++;
    if (round($debits, 2) !== round($credits, 2)) {
        $allBalanced = false;
        echo "Transaction #{$t->id} unbalanced! Debits: {$debits}, Credits: {$credits}\n";
    }
}
assertTest("GL Equality: All {$checkedCount} transactions have SUM(Debit) == SUM(Credit)", $allBalanced && $checkedCount > 0);


echo "\n═════════════════════════════════════════════════════════════════════════════\n";
echo "SUMMARY: Passed: {$testsPassed} | Failed: {$testsFailed}\n";
echo "═════════════════════════════════════════════════════════════════════════════\n";

if ($testsFailed > 0) {
    exit(1);
}
exit(0);
