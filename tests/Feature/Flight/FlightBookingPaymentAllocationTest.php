<?php

namespace Tests\Feature\Flight;

use App\Enums\FlightBookingStatus;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightPaymentAllocation;
use App\Models\Flight\FlightSystem;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\TransactionService;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Services\Flight\FlightPaymentAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlightBookingPaymentAllocationTest extends TestCase
{
    use RefreshDatabase;

    protected FlightBookingService $bookingService;
    protected FlightPaymentAllocationService $allocationService;
    protected TransactionService $txService;

    protected User $admin;
    protected Customer $customer;
    protected Customer $otherCustomer;
    protected FlightCarrier $carrier;
    protected Account $cashbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bookingService = app(FlightBookingService::class);
        $this->allocationService = app(FlightPaymentAllocationService::class);
        $this->txService = app(TransactionService::class);

        $this->admin = User::factory()->create([
            'name' => 'Allocation Test Admin',
            'email' => 'allocation-admin@test.com',
            'role' => 'admin',
            'is_active' => true,
        ]);
        \Laravel\Sanctum\Sanctum::actingAs($this->admin, ['*']);

        $this->customer = Customer::create([
            'full_name' => 'Company A - Customer',
            'phone' => '01000000001',
            'email' => 'comp-a@test.com',
            'national_id' => '12345678901234',
            'city' => 'Cairo',
            'type' => 'company',
        ]);

        $this->otherCustomer = Customer::create([
            'full_name' => 'Company B - Other Customer',
            'phone' => '01000000002',
            'email' => 'comp-b@test.com',
            'national_id' => '12345678901235',
            'city' => 'Alexandria',
            'type' => 'company',
        ]);

        $system = FlightSystem::create([
            'name' => 'GDS System',
            'code' => 'GDS'.substr(md5((string) microtime(true)), 0, 4),
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'EGP',
            'balance' => 0,
            'credit_limit' => 0,
            'created_by' => $this->admin->id,
        ]);

        $this->carrier = FlightCarrier::create([
            'name' => 'EgyptAir Test',
            'code' => 'MS'.substr(md5((string) microtime(true)), 0, 3),
            'flight_system_id' => $system->id,
            'currency' => 'EGP',
            'balance' => 0,
            'credit_limit' => 500000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->cashbox = Account::create([
            'name' => 'Main Treasury Cashbox',
            'type' => 'cashbox',
            'balance' => 500000,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->admin->id,
        ]);

        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $this->carrier,
            $this->cashbox,
            300000.00,
            'Carrier recharge for allocation test'
        );

        $this->carrier->refresh();
        $this->cashbox->refresh();
    }

    protected function createBooking(Customer $customer, float $sellingPrice = 10000.0, float $purchasePrice = 8000.0): FlightBooking
    {
        return $this->bookingService->createBooking([
            'customer_id' => $customer->id,
            'flight_carrier_id' => $this->carrier->id,
            'purchase_price' => $purchasePrice,
            'selling_price' => $sellingPrice,
            'currency' => 'EGP',
            'trip_type' => 'one_way',
            'departure_date' => now()->addDays(5)->toDateString(),
            'from_airport' => 'CAI',
            'to_airport' => 'JED',
            'pnr' => 'PNR'.substr(md5((string) microtime(true)), 0, 6),
            'passengers' => [
                ['first_name' => 'Ahmed', 'last_name' => 'Ali', 'type' => 'adult'],
            ],
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * Test 1: Payment for Booking A is not counted in Booking B.
     */
    public function test_payment_for_booking_a_is_not_counted_in_booking_b(): void
    {
        $bookingA = $this->createBooking($this->customer, 10000.0, 7000.0);
        $bookingB = $this->createBooking($this->customer, 8000.0, 6000.0);

        // Record payDebt receipt allocated strictly to Booking A
        $response = $this->postJson("/api/v1/customers/{$this->customer->id}/pay-debt", [
            'amount' => 10000.0,
            'account_id' => $this->cashbox->id,
            'module' => 'flight',
            'type' => 'receipt',
            'flight_booking_id' => $bookingA->id,
            'notes' => 'Settlement for Booking A',
        ]);
        $response->assertOk();

        $bookingA->refresh();
        $bookingB->refresh();

        $this->assertEquals(10000.0, (float) $bookingA->paid_amount, 'Booking A must be fully paid');
        $this->assertEquals(0.0, (float) $bookingA->remaining_amount, 'Booking A remaining must be 0');
        $this->assertEquals('paid', $bookingA->computePaymentStatus());

        $this->assertEquals(0.0, (float) $bookingB->paid_amount, 'Booking B must have 0 paid amount');
        $this->assertEquals(8000.0, (float) $bookingB->remaining_amount, 'Booking B must remain fully unpaid');
        $this->assertEquals('unpaid', $bookingB->computePaymentStatus());
    }

    /**
     * Test 2: Cancelling an unpaid booking does not refund money from another booking's payments.
     */
    public function test_cancelling_unpaid_booking_does_not_refund_money_from_another_booking(): void
    {
        $bookingA = $this->createBooking($this->customer, 10000.0, 7000.0);
        $bookingB = $this->createBooking($this->customer, 7500.0, 5000.0);

        // Pay Booking A in full via payDebt
        $this->postJson("/api/v1/customers/{$this->customer->id}/pay-debt", [
            'amount' => 10000.0,
            'account_id' => $this->cashbox->id,
            'module' => 'flight',
            'type' => 'receipt',
            'flight_booking_id' => $bookingA->id,
        ])->assertOk();

        $cashboxBeforeCancel = (float) $this->cashbox->fresh()->balance;

        // Now cancel Booking B (which is completely unpaid)
        $refund = $this->bookingService->cancelBooking($bookingB, [
            'airline_penalty' => 0.0,
            'office_penalty' => 200.0,
            'account_id' => $this->cashbox->id,
            'notes' => 'Cancel unpaid booking B',
        ]);

        $this->assertEquals(0.0, (float) $refund->refund_amount, 'Unpaid booking refund amount must be 0');
        $this->assertEquals('no_refund', $refund->status);

        $cashboxAfterCancel = (float) $this->cashbox->fresh()->balance;
        $this->assertEquals(
            $cashboxBeforeCancel,
            $cashboxAfterCancel,
            'Treasury cashbox must NOT be debited when cancelling an unpaid booking'
        );

        // Booking A must remain fully paid
        $bookingA->refresh();
        $this->assertEquals(10000.0, (float) $bookingA->paid_amount, 'Booking A payments must remain untouched');
    }

    /**
     * Test 3: Reversing a booking does not change another booking's receipt allocation.
     */
    public function test_reversing_booking_does_not_change_another_bookings_receipt(): void
    {
        $bookingA = $this->createBooking($this->customer, 6000.0, 4000.0);
        $bookingB = $this->createBooking($this->customer, 4000.0, 3000.0);

        // Record a single 10,000 EGP receipt split across Booking A (6,000) and Booking B (4,000)
        $resp = $this->postJson("/api/v1/customers/{$this->customer->id}/pay-debt", [
            'amount' => 10000.0,
            'account_id' => $this->cashbox->id,
            'module' => 'flight',
            'type' => 'receipt',
            'allocations' => [
                ['flight_booking_id' => $bookingA->id, 'amount' => 6000.0],
                ['flight_booking_id' => $bookingB->id, 'amount' => 4000.0],
            ],
        ]);
        $resp->assertOk();

        $bookingA->refresh();
        $bookingB->refresh();
        $this->assertEquals(6000.0, (float) $bookingA->paid_amount);
        $this->assertEquals(4000.0, (float) $bookingB->paid_amount);

        // Cancel Booking A
        $this->bookingService->cancelBooking($bookingA, [
            'airline_penalty' => 0.0,
            'office_penalty' => 0.0,
            'account_id' => $this->cashbox->id,
            'notes' => 'Cancel booking A',
        ]);

        $bookingA->refresh();
        $bookingB->refresh();

        // Booking A's allocation is reversed
        $allocA = FlightPaymentAllocation::where('flight_booking_id', $bookingA->id)->first();
        $this->assertEquals('reversed', $allocA->status);

        // Booking B's allocation remains active and paid
        $allocB = FlightPaymentAllocation::where('flight_booking_id', $bookingB->id)->first();
        $this->assertEquals('active', $allocB->status);
        $this->assertEquals(4000.0, (float) $bookingB->paid_amount, 'Booking B must remain paid with 4000 EGP');

        // Parent receipt transaction must NOT have been marked with 'عكس:'
        $parentTx = Transaction::find($allocB->transaction_id);
        $this->assertFalse(str_starts_with((string) $parentTx->notes, 'عكس:'), 'Shared receipt must not be marked fully reversed');

        // Instead, a companion reversal transfer exists specifically for Booking A's 6,000
        $this->assertNotNull($allocA->reversal_transaction_id, 'Companion reversal transaction must be created for partial allocation');
        $companionTx = Transaction::find($allocA->reversal_transaction_id);
        $this->assertEquals(6000.0, (float) $companionTx->amount);
    }

    /**
     * Test 4: A receipt distributed across bookings cannot exceed its total value.
     */
    public function test_receipt_distributed_across_bookings_cannot_exceed_total_value(): void
    {
        $bookingA = $this->createBooking($this->customer, 10000.0, 7000.0);
        $bookingB = $this->createBooking($this->customer, 10000.0, 7000.0);

        // Receipt of 10,000 but allocations request 6,000 + 5,000 = 11,000
        $response = $this->postJson("/api/v1/customers/{$this->customer->id}/pay-debt", [
            'amount' => 10000.0,
            'account_id' => $this->cashbox->id,
            'module' => 'flight',
            'type' => 'receipt',
            'allocations' => [
                ['flight_booking_id' => $bookingA->id, 'amount' => 6000.0],
                ['flight_booking_id' => $bookingB->id, 'amount' => 5000.0],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('flight_payment_allocations', [
            'customer_id' => $this->customer->id,
        ]);
    }

    /**
     * Test 5: Cannot allocate the same amount or exceed available receipt amount.
     */
    public function test_cannot_allocate_same_amount_twice(): void
    {
        $bookingA = $this->createBooking($this->customer, 5000.0, 3000.0);
        $bookingB = $this->createBooking($this->customer, 5000.0, 3000.0);

        // Record a receipt of 5,000 with allocation of 5,000 to Booking A
        $this->postJson("/api/v1/customers/{$this->customer->id}/pay-debt", [
            'amount' => 5000.0,
            'account_id' => $this->cashbox->id,
            'module' => 'flight',
            'type' => 'receipt',
            'flight_booking_id' => $bookingA->id,
        ])->assertOk();

        $tx = Transaction::where('related_type', Customer::class)
            ->where('related_id', $this->customer->id)
            ->latest('id')
            ->first();

        // Try to allocate another 1,000 from the same receipt to Booking B
        $this->expectException(\InvalidArgumentException::class);
        $this->allocationService->allocate($tx, $bookingB, 1000.0, $this->admin->id);
    }

    /**
     * Test 6: Direct payments and allocated receipts are not double counted.
     */
    public function test_direct_payments_and_allocated_receipts_are_not_double_counted(): void
    {
        $booking = $this->createBooking($this->customer, 10000.0, 7000.0);

        // 1. Direct payment of 4,000 EGP via addPayment
        $this->bookingService->addPayment($booking, [
            'amount' => 4000.0,
            'account_id' => $this->cashbox->id,
            'method' => 'cash',
        ]);

        $booking->refresh();
        $this->assertEquals(4000.0, (float) $booking->paid_amount);
        $this->assertEquals(6000.0, (float) $booking->remaining_amount);

        // 2. Receipt allocation of 6,000 EGP via payDebt
        $this->postJson("/api/v1/customers/{$this->customer->id}/pay-debt", [
            'amount' => 6000.0,
            'account_id' => $this->cashbox->id,
            'module' => 'flight',
            'type' => 'receipt',
            'flight_booking_id' => $booking->id,
        ])->assertOk();

        $booking->refresh();
        $this->assertEquals(10000.0, (float) $booking->paid_amount, 'Total paid must equal 4000 + 6000 = 10000');
        $this->assertEquals(0.0, (float) $booking->remaining_amount, 'Remaining amount must be 0');
        $this->assertEquals('paid', $booking->computePaymentStatus());

        // 3. Attempting to allocate more to this fully paid booking is rejected
        $overReceiptResp = $this->postJson("/api/v1/customers/{$this->customer->id}/pay-debt", [
            'amount' => 1000.0,
            'account_id' => $this->cashbox->id,
            'module' => 'flight',
            'type' => 'receipt',
            'flight_booking_id' => $booking->id,
        ]);
        $overReceiptResp->assertStatus(422);
    }

    /**
     * Test 7: Historical unallocated receipts are not automatically counted.
     */
    public function test_historical_unallocated_receipts_are_not_automatically_counted(): void
    {
        // Customer has a general unallocated payDebt receipt of 20,000 EGP
        $this->postJson("/api/v1/customers/{$this->customer->id}/pay-debt", [
            'amount' => 20000.0,
            'account_id' => $this->cashbox->id,
            'module' => 'flight',
            'type' => 'receipt',
            'notes' => 'Old unallocated general debt payment',
        ])->assertOk();

        // Customer creates a new flight booking for 12,000 EGP
        $booking = $this->createBooking($this->customer, 12000.0, 9000.0);

        // The booking MUST remain completely unpaid (0 paid amount)
        $this->assertEquals(0.0, (float) $booking->paid_amount, 'Historical unallocated receipt must NOT be counted');
        $this->assertEquals(12000.0, (float) $booking->remaining_amount);
        $this->assertEquals('unpaid', $booking->computePaymentStatus());

        // Cancelling this booking must result in 0 refund
        $refund = $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => 0.0,
            'office_penalty' => 200.0,
            'account_id' => $this->cashbox->id,
        ]);
        $this->assertEquals(0.0, (float) $refund->refund_amount, 'Unpaid booking cannot refund unallocated historical cash');
    }

    /**
     * Test 8: Allocation rejects a booking belonging to a different customer.
     */
    public function test_allocation_rejects_booking_belonging_to_different_customer(): void
    {
        $otherBooking = $this->createBooking($this->otherCustomer, 10000.0, 7000.0);

        // Customer A tries to allocate a payment to Customer B's booking
        $response = $this->postJson("/api/v1/customers/{$this->customer->id}/pay-debt", [
            'amount' => 5000.0,
            'account_id' => $this->cashbox->id,
            'module' => 'flight',
            'type' => 'receipt',
            'flight_booking_id' => $otherBooking->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('flight_payment_allocations', [
            'flight_booking_id' => $otherBooking->id,
        ]);
    }
}
