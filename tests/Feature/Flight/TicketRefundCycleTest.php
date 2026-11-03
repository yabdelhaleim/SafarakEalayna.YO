<?php

namespace Tests\Feature\Flight;

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
use App\Services\Flight\FlightCarrierRechargeService;
use App\Services\Flight\RefundService;
use App\Services\Finance\LedgerClearingAccounts;
use App\Support\UserPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TicketRefundCycleTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $agentUser;
    protected Treasury $treasury;
    protected Account $treasuryAccount;
    protected Account $bankAccount;
    protected FlightSystem $flightSystem;
    protected FlightCarrier $carrier;
    protected RefundService $refundService;
    protected FlightBookingService $bookingService;
    protected LedgerClearingAccounts $clearing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refundService = app(RefundService::class);
        $this->bookingService = app(FlightBookingService::class);
        $this->clearing = app(LedgerClearingAccounts::class);

        $this->adminUser = User::create([
            'name' => 'Refund Admin',
            'email' => 'admin@safarak.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->actingAs($this->adminUser);

        $this->agentUser = User::create([
            'name' => 'Booking Agent',
            'email' => 'agent@test.com',
            'password' => bcrypt('password'),
            'role' => 'agent',
            'is_active' => true,
        ]);

        $this->treasury = Treasury::create([
            'name' => 'Main Treasury EGP',
            'currency' => 'EGP',
            'current_balance' => 500000.00,
            'is_active' => true,
        ]);

        $this->treasuryAccount = Account::create([
            'name' => 'Main Treasury EGP',
            'type' => 'cashbox',
            'balance' => 500000.00,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->adminUser->id,
        ]);

        $this->bankAccount = Account::create([
            'name' => 'CIB Bank Account',
            'type' => 'bank',
            'balance' => 500000.00,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->adminUser->id,
        ]);

        $this->flightSystem = FlightSystem::create([
            'name' => 'Amadeus GDS',
            'code' => '1A',
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'EGP',
            'balance' => 0,
            'credit_limit' => 0,
            'created_by' => $this->adminUser->id,
        ]);

        $this->carrier = FlightCarrier::create([
            'name' => 'EgyptAir',
            'code' => 'MS',
            'flight_system_id' => $this->flightSystem->id,
            'currency' => 'EGP',
            'balance' => 0.00,
            'credit_limit' => 100000.00,
            'is_active' => true,
            'created_by' => $this->adminUser->id,
        ]);

        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $this->carrier,
            $this->treasuryAccount,
            200000.00,
            'Initial recharge'
        );
    }

    protected function createTestBooking(Customer $customer, float $sellingPrice = 7500.0, float $cost = 7350.0, bool $paid = true): FlightBooking
    {
        $booking = $this->bookingService->createBooking([
            'customer_id'       => $customer->id,
            'airline_name'      => 'EgyptAir',
            'from_airport'      => 'CAI',
            'to_airport'        => 'JED',
            'departure_date'    => now()->addDays(7)->toDateString(),
            'trip_type'         => 'one_way',
            'currency'          => 'EGP',
            'purchase_price'    => $cost,
            'selling_price'     => $sellingPrice,
            'flight_carrier_id' => $this->carrier->id,
            'account_id'        => $this->treasuryAccount->id,
            'passengers'        => [
                ['name' => 'Test Pax', 'type' => 'adult'],
            ],
        ]);

        if ($paid) {
            $this->bookingService->addPayment($booking, [
                'amount'         => $sellingPrice,
                'payment_method' => 'cash',
                'account_id'     => $this->treasuryAccount->id,
                'notes'          => 'Full payment',
            ]);
        }

        return $booking->fresh();
    }

    /**
     * Test Reference Scenario (Literal: 7,350 cost, 7,500 selling price, 200 cancellation fee, 7,300 net customer refund).
     */
    public function test_reference_scenario_literal_numbers(): void
    {
        $customer = Customer::create([
            'full_name' => 'Ahmed Ali',
            'phone' => '01000000001',
            'email' => 'ahmed@test.com',
        ]);

        $booking = $this->createTestBooking($customer, 7500.00, 7350.00, true);
        $carrierBalBefore = (float)$this->carrier->fresh()->balance;

        $refundReq = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'cancellation_fee' => 200.00,
            'supplier_refund_amount' => 7350.00,
            'supplier_cost' => 7350.00,
            'destination' => 'airline_credit',
            'refund_currency' => 'EGP',
            'refund_exchange_rate' => 1.0,
        ], $this->adminUser->id);

        $this->assertEquals(7300.00, (float)$refundReq->refund_amount);
        $this->assertEquals(200.00, (float)$refundReq->cancellation_fee);

        $processed = $this->refundService->processRefundRequest($refundReq->id, $this->adminUser->id);

        $this->assertEquals('processed', $processed->status);
        $this->assertEquals(7300.00, (float)$processed->customer_credit_amount);
        $this->assertEquals(0.00, (float)$processed->customer_debt_settled_amount);
        $this->assertEquals(0.00, (float)$processed->supplier_refund_loss);
        $this->assertEquals(FlightBookingStatus::REFUNDED, $booking->fresh()->status);

        $this->assertEquals(7350.00, round((float)$this->carrier->fresh()->balance - $carrierBalBefore, 2));

        // Verify GL equality
        $txIds = AccountEntry::where('flight_booking_id', $booking->id)->pluck('transaction_id')->unique();
        foreach ($txIds as $tId) {
            if (!$tId) continue;
            $d = (float)AccountEntry::where('transaction_id', $tId)->sum('debit');
            $c = (float)AccountEntry::where('transaction_id', $tId)->sum('credit');
            $this->assertEquals(round($d, 2), round($c, 2));
        }
    }

    /**
     * Test airline penalty / reduced refund amount.
     */
    public function test_supplier_reduced_refund_records_loss(): void
    {
        $customer = Customer::create([
            'full_name' => 'Supplier Test Customer',
            'phone' => '01000000002',
            'email' => 'sup@test.com',
        ]);

        $booking = $this->createTestBooking($customer, 7500.00, 7350.00, true);
        $carrierBalBefore = (float)$this->carrier->fresh()->balance;

        $refundReq = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'cancellation_fee' => 200.00,
            'supplier_refund_amount' => 7000.00, // 350 penalty
            'supplier_cost' => 7350.00,
            'destination' => 'airline_credit',
        ], $this->adminUser->id);

        $processed = $this->refundService->processRefundRequest($refundReq->id, $this->adminUser->id);

        $this->assertEquals(350.00, (float)$processed->supplier_refund_loss);
        $this->assertEquals(7000.00, round((float)$this->carrier->fresh()->balance - $carrierBalBefore, 2));
        $this->assertEquals(7300.00, (float)$processed->customer_credit_amount);
    }

    /**
     * Test FIFO customer debt settlement across multiple unpaid bookings.
     */
    public function test_customer_debt_settled_fifo(): void
    {
        $customer = Customer::create([
            'full_name' => 'Sara Ibrahim',
            'phone' => '01000000003',
            'email' => 'sara@test.com',
        ]);

        $unpaidA = $this->createTestBooking($customer, 3000.00, 2500.00, false);
        $unpaidB = $this->createTestBooking($customer, 2000.00, 1500.00, false);
        $bookingToRefund = $this->createTestBooking($customer, 7500.00, 7350.00, true);

        $refundReq = $this->refundService->createRefundRequest([
            'flight_booking_id' => $bookingToRefund->id,
            'cancellation_fee' => 200.00,
            'supplier_refund_amount' => 7350.00,
            'destination' => 'airline_credit',
        ], $this->adminUser->id);

        $processed = $this->refundService->processRefundRequest($refundReq->id, $this->adminUser->id);

        $this->assertEquals(5000.00, (float)$processed->customer_debt_settled_amount);
        $this->assertEquals(2300.00, (float)$processed->customer_credit_amount);
        $this->assertEquals('paid', $unpaidA->fresh()->computePaymentStatus());
        $this->assertEquals('paid', $unpaidB->fresh()->computePaymentStatus());
    }

    /**
     * Test safety gate and disbursement lifecycle.
     */
    public function test_disbursement_safety_gate_and_channels(): void
    {
        $customer = Customer::create([
            'full_name' => 'Disburse Test',
            'phone' => '01000000004',
            'email' => 'disburse@test.com',
        ]);

        $booking = $this->createTestBooking($customer, 7500.00, 7350.00, true);
        $refundReq = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'cancellation_fee' => 200.00,
            'destination' => 'airline_credit',
        ], $this->adminUser->id);

        $processed = $this->refundService->processRefundRequest($refundReq->id, $this->adminUser->id);
        $this->assertFalse((bool)$processed->supplier_refund_confirmed);

        // Safety gate: agent user without force_refund_payout is blocked
        $blocked = false;
        try {
            $this->refundService->disburseCustomerCredit($processed->id, [
                'type' => 'cash_treasury',
                'amount' => 1000.00,
                'treasury_id' => $this->treasury->id,
            ], $this->agentUser);
        } catch (\Throwable $e) {
            $blocked = str_contains($e->getMessage(), 'تأكيد استرداد المورد');
        }
        $this->assertTrue($blocked);

        // Confirm supplier refund
        $this->refundService->confirmSupplierRefund($processed->id, $this->adminUser->id);
        $this->assertTrue((bool)$processed->fresh()->supplier_refund_confirmed);

        // Disburse cash
        $treasuryBalBefore = (float)$this->treasury->fresh()->current_balance;
        $this->refundService->disburseCustomerCredit($processed->id, [
            'type' => 'cash_treasury',
            'amount' => 3000.00,
            'treasury_id' => $this->treasury->id,
        ], $this->adminUser);

        $this->assertEquals(3000.00, round($treasuryBalBefore - (float)$this->treasury->fresh()->current_balance, 2));
        $this->assertEquals('partially_disbursed', $processed->fresh()->disbursement_status);
        $this->assertEquals(4300.00, (float)$processed->fresh()->available_customer_credit);

        // Disburse bank transfer for remaining
        $this->refundService->disburseCustomerCredit($processed->id, [
            'type' => 'bank_transfer',
            'amount' => 4300.00,
            'bank_account_id' => $this->bankAccount->id,
        ], $this->adminUser);

        $this->assertEquals('fully_disbursed', $processed->fresh()->disbursement_status);
        $this->assertEquals(0.00, (float)$processed->fresh()->available_customer_credit);
    }

    /**
     * Test Case 1: Customer voluntary cancellation with airline penalty and agency fee,
     * plus variance handling when airline confirms with less.
     */
    public function test_case_1_customer_voluntary_penalty_and_variance(): void
    {
        $customer = Customer::create([
            'full_name' => 'Case 1 Feature Customer',
            'phone' => '01011112222',
            'email' => 'case1feat@test.com',
        ]);

        $booking = $this->createTestBooking($customer, 7500.00, 7350.00, true);

        // Preview
        $preview = $this->refundService->previewRefund([
            'flight_booking_id' => $booking->id,
            'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000.00,
            'agency_commission' => 200.00,
        ]);

        $this->assertEquals(6300.00, (float)$preview['net_customer_refund']);
        $this->assertEquals(6350.00, (float)$preview['expected_supplier_refund']);
        $this->assertEquals(0.00, (float)$preview['supplier_refund_loss']);

        // Request & Process
        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000.00,
            'agency_commission' => 200.00,
            'destination' => 'customer_credit',
        ], $this->adminUser->id);

        $processed = $this->refundService->processRefundRequest($req->id, $this->adminUser->id);
        $this->assertEquals(6300.00, (float)$processed->refund_amount);
        $this->assertEquals(6300.00, (float)$processed->customer_credit_amount);
        $this->assertEquals(6300.00, (float)\App\Models\CustomerCreditLedger::getAvailableCredit($customer->id));

        // Variance: Airline confirms 6100 (less by 250)
        $confirmed = $this->refundService->confirmSupplierRefund($processed->id, $this->adminUser->id, 6100.00);
        $this->assertEquals(250.00, (float)$confirmed->refund_variance_amount);
        $this->assertEquals(6300.00, (float)$confirmed->customer_credit_amount);
        $this->assertEquals(6300.00, (float)\App\Models\CustomerCreditLedger::getAvailableCredit($customer->id));
    }

    /**
     * Test Case 2: Airline cancelled flight variations.
     */
    public function test_case_2_airline_cancelled_variations(): void
    {
        $customer = Customer::create([
            'full_name' => 'Case 2 Customer',
            'phone' => '01022223333',
            'email' => 'case2@test.com',
        ]);

        // 2.1: Full refund with commission
        $b1 = $this->createTestBooking($customer, 7500.00, 7350.00, true);
        $r1 = $this->refundService->createRefundRequest([
            'flight_booking_id' => $b1->id,
            'refund_case' => 'airline_cancelled',
            'airline_refund_amount' => 7350.00,
            'apply_commission' => true,
            'agency_commission' => 200.00,
            'destination' => 'customer_credit',
        ], $this->adminUser->id);
        $p1 = $this->refundService->processRefundRequest($r1->id, $this->adminUser->id);
        $this->assertEquals(7300.00, (float)$p1->refund_amount);

        // 2.2: Full refund without commission
        $b2 = $this->createTestBooking($customer, 7500.00, 7350.00, true);
        $r2 = $this->refundService->createRefundRequest([
            'flight_booking_id' => $b2->id,
            'refund_case' => 'airline_cancelled',
            'airline_refund_amount' => 7350.00,
            'apply_commission' => false,
            'destination' => 'customer_credit',
        ], $this->adminUser->id);
        $p2 = $this->refundService->processRefundRequest($r2->id, $this->adminUser->id);
        $this->assertEquals(7500.00, (float)$p2->refund_amount);

        // 2.3: Non-refundable taxes on customer
        $b3 = $this->createTestBooking($customer, 7500.00, 7350.00, true);
        $r3 = $this->refundService->createRefundRequest([
            'flight_booking_id' => $b3->id,
            'refund_case' => 'airline_cancelled',
            'airline_refund_amount' => 7100.00,
            'non_refundable_taxes_amount' => 250.00,
            'non_refundable_taxes_on_customer' => true,
            'apply_commission' => false,
            'destination' => 'customer_credit',
        ], $this->adminUser->id);
        $p3 = $this->refundService->processRefundRequest($r3->id, $this->adminUser->id);
        $this->assertEquals(7250.00, (float)$p3->refund_amount);
        $this->assertEquals(0.00, (float)$p3->supplier_refund_loss);

        // 2.4: Non-refundable taxes on agency
        $b4 = $this->createTestBooking($customer, 7500.00, 7350.00, true);
        $r4 = $this->refundService->createRefundRequest([
            'flight_booking_id' => $b4->id,
            'refund_case' => 'airline_cancelled',
            'airline_refund_amount' => 7100.00,
            'non_refundable_taxes_amount' => 250.00,
            'non_refundable_taxes_on_customer' => false,
            'apply_commission' => false,
            'destination' => 'customer_credit',
        ], $this->adminUser->id);
        $p4 = $this->refundService->processRefundRequest($r4->id, $this->adminUser->id);
        $this->assertEquals(7500.00, (float)$p4->refund_amount);
        $this->assertEquals(250.00, (float)$p4->supplier_refund_loss);
    }

    /**
     * Test Case 3: Overpayment (دفعة زيادة) automatically credited in CustomerCreditLedger.
     */
    public function test_case_3_overpayment_creates_unified_customer_credit(): void
    {
        $customer = Customer::create([
            'full_name' => 'Case 3 Customer',
            'phone' => '01033334444',
            'email' => 'case3@test.com',
        ]);

        $booking = $this->createTestBooking($customer, 7500.00, 7000.00, false);
        $this->assertEquals(7500.00, (float)$booking->remaining_amount);

        $this->bookingService->addPayment($booking, [
            'amount' => 8000.00,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryAccount->id,
            // QA FIX (2026-11-03): an overpayment is rejected by default; it is only
            // accepted when the caller explicitly allows it (the Vue client sends
            // allow_overpayment=true after the user confirms).
            'allow_overpayment' => true,
        ]);

        $this->assertEquals(7500.00, (float)$booking->fresh()->paid_amount);
        $this->assertEquals(0.00, (float)$booking->fresh()->remaining_amount);
        $this->assertEquals(500.00, (float)\App\Models\CustomerCreditLedger::getAvailableCredit($customer->id));

        // Consume credit for a new booking
        $newBooking = $this->createTestBooking($customer, 1000.00, 800.00, false);
        $this->bookingService->consumeCustomerCredit($newBooking, 500.00, $this->adminUser->id);

        $this->assertEquals(500.00, (float)$newBooking->fresh()->paid_amount);
        $this->assertEquals(500.00, (float)$newBooking->fresh()->remaining_amount);
        $this->assertEquals(0.00, (float)\App\Models\CustomerCreditLedger::getAvailableCredit($customer->id));
    }

    /**
     * Test Corporate customer / Company credit routing and FIFO debt settlement.
     */
    public function test_corporate_company_credit_and_fifo_settlement(): void
    {
        $company = Customer::create([
            'full_name' => 'Mega Corp Tourism',
            'phone' => '01088889999',
            'email' => 'corp@mega.com',
            'type' => \App\Enums\CustomerType::Company,
        ]);

        $employee = Customer::create([
            'full_name' => 'Tamer Employee',
            'phone' => '01077778888',
            'email' => 'tamer@mega.com',
            'type' => \App\Enums\CustomerType::Individual,
            'company_id' => $company->id,
        ]);

        $compDebt = $this->createTestBooking($company, 2000.00, 1800.00, false);

        $empBooking = $this->createTestBooking($employee, 3500.00, 3300.00, true);
        $req = $this->refundService->createRefundRequest([
            'flight_booking_id' => $empBooking->id,
            'refund_case' => 'airline_cancelled',
            'destination' => 'customer_credit',
        ], $this->adminUser->id);

        $this->assertEquals($company->id, $req->company_id);

        $proc = $this->refundService->processRefundRequest($req->id, $this->adminUser->id);

        $this->assertEquals(2000.00, (float)$proc->customer_debt_settled_amount);
        $this->assertEquals(0.00, (float)$compDebt->fresh()->remaining_amount);
        $this->assertEquals(1500.00, (float)$proc->customer_credit_amount);
        $this->assertEquals(1500.00, (float)\App\Models\CustomerCreditLedger::getAvailableCredit(null, $company->id));
    }

    /**
     * Deliverable 5 — Regression test for addPayment legacy behavior.
     * Confirms that payments equal-to or less-than the remaining amount
     * do NOT create any CustomerCreditLedger entry (zero blast radius).
     */
    public function test_addpayment_legacy_behavior_unchanged_no_credit_ledger_entry(): void
    {
        $customer = Customer::create([
            'full_name' => 'Legacy Payment Customer',
            'phone'     => '01055556666',
            'email'     => 'legacy@pay.com',
        ]);

        // ─── Scenario A: partial payment (3,000 on 7,500 booking) ───────────
        $booking = $this->createTestBooking($customer, 7500.00, 7000.00, false);
        $this->assertEquals(7500.00, (float) $booking->fresh()->remaining_amount,
            'Scenario A: Unpaid booking must have remaining = selling_price');

        $this->bookingService->addPayment($booking, [
            'amount'         => 3000.00,
            'payment_method' => 'cash',
            'account_id'     => $this->treasuryAccount->id,
            'notes'          => 'Partial payment',
        ]);

        $bookingA = $booking->fresh();
        $this->assertEquals(3000.00, (float) $bookingA->paid_amount,
            'Scenario A: paid_amount must equal partial payment');
        $this->assertEquals(4500.00, (float) $bookingA->remaining_amount,
            'Scenario A: remaining_amount = 7500 - 3000');
        $this->assertEquals(0.00,
            (float) \App\Models\CustomerCreditLedger::getAvailableCredit($customer->id),
            'Scenario A: NO credit ledger entry on partial payment (≤ remaining)');

        // ─── Scenario B: exact-match payment (7,500 on 7,500 booking) ───────
        $customer2 = Customer::create([
            'full_name' => 'Exact Payment Customer',
            'phone'     => '01066667777',
            'email'     => 'exact@pay.com',
        ]);
        $booking2 = $this->createTestBooking($customer2, 7500.00, 7000.00, false);

        $this->bookingService->addPayment($booking2, [
            'amount'         => 7500.00,
            'payment_method' => 'cash',
            'account_id'     => $this->treasuryAccount->id,
            'notes'          => 'Exact payment',
        ]);

        $bookingB = $booking2->fresh();
        $this->assertEquals(7500.00, (float) $bookingB->paid_amount,
            'Scenario B: paid_amount = selling_price (exact match)');
        $this->assertEquals(0.00, (float) $bookingB->remaining_amount,
            'Scenario B: remaining_amount = 0 after exact payment');
        $this->assertEquals(0.00,
            (float) \App\Models\CustomerCreditLedger::getAvailableCredit($customer2->id),
            'Scenario B: NO credit ledger entry on exact payment');

        // ─── Scenario C: two installments totalling selling price ────────────
        $customer3 = Customer::create([
            'full_name' => 'Installment Customer',
            'phone'     => '01077778888',
            'email'     => 'install@pay.com',
        ]);
        $booking3 = $this->createTestBooking($customer3, 5000.00, 4500.00, false);

        $this->bookingService->addPayment($booking3, [
            'amount'         => 2000.00,
            'payment_method' => 'cash',
            'account_id'     => $this->treasuryAccount->id,
        ]);
        $this->bookingService->addPayment($booking3, [
            'amount'         => 3000.00,
            'payment_method' => 'cash',
            'account_id'     => $this->treasuryAccount->id,
        ]);

        $bookingC = $booking3->fresh();
        $this->assertEquals(5000.00, (float) $bookingC->paid_amount,
            'Scenario C: two installments sum to selling_price');
        $this->assertEquals(0.00, (float) $bookingC->remaining_amount,
            'Scenario C: remaining_amount = 0 after two installments');
        $this->assertEquals(0.00,
            (float) \App\Models\CustomerCreditLedger::getAvailableCredit($customer3->id),
            'Scenario C: NO credit ledger entry for installments that total exactly selling_price');
    }

    /**
     * Test immutable credit ledger and reversal.
     */
    public function test_credit_ledger_immutability_and_reversal(): void
    {
        $customer = Customer::create([
            'full_name' => 'Rev Customer',
            'phone' => '01099990000',
            'email' => 'rev@cust.com',
        ]);

        $entry = \App\Models\CustomerCreditLedger::recordEntry([
            'customer_id' => $customer->id,
            'source_type' => 'deposit',
            'amount' => 1000.00,
            'currency' => 'EGP',
            'created_by' => $this->adminUser->id,
        ]);

        $this->assertEquals(1000.00, (float)\App\Models\CustomerCreditLedger::getAvailableCredit($customer->id));

        $rev = $entry->reverse($this->adminUser->id, 'Mistake');
        $this->assertEquals(-1000.00, (float)$rev->amount);
        $this->assertEquals(0.00, (float)\App\Models\CustomerCreditLedger::getAvailableCredit($customer->id));
    }
}
