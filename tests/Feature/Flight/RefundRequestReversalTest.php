<?php

namespace Tests\Feature\Flight;

use App\Enums\FlightBookingStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\AirlineCredit;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\RefundRequest;
use App\Models\Transaction;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Services\Flight\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Feature tests for RefundRequest reversal — Priority 3.3 + 3.4.
 *
 * Branches tested:
 *   - `agency_treasury`: reverses (a) GL prepaid→cashbox, (b) carrier/system debit, (c) treasury receipt.
 *   - `airline_credit`: cancels the linked AirlineCredit voucher (no GL posted).
 *
 * Invariants:
 *   1. Net balance delta == 0 for every financial account touched.
 *   2. Original RefundRequest + AirlineCredit/TreasuryTransaction rows preserved.
 *   3. New reversal transaction created (related_type=RefundRequest).
 *   4. RefundRequest is soft-deleted after reversal.
 */
class RefundRequestReversalTest extends TestCase
{
    use RefreshDatabase;

    protected FlightBookingService $bookingService;

    protected RefundService $refundService;

    protected User $admin;

    protected Customer $customer;

    protected FlightSystem $flightSystem;

    protected FlightCarrier $carrier;

    protected Account $cashbox;

    protected Treasury $treasury;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bookingService = app(FlightBookingService::class);
        $this->refundService = app(RefundService::class);

        $this->admin = User::factory()->create([
            'name' => 'Refund Test Admin',
            'email' => 'refund-admin@test.com',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->actingAs($this->admin);

        $this->customer = Customer::create([
            'full_name' => 'Refund Test Customer',
            'phone' => '0123456789',
            'email' => 'refund-customer@test.com',
            'national_id' => '11122233344455',
            'city' => 'Cairo',
        ]);

        $this->flightSystem = FlightSystem::create([
            'name' => 'Refund Test System',
            'code' => 'RFS'.substr(md5((string) microtime(true)), 0, 6),
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'EGP',
            'balance' => 0,
            'credit_limit' => 0,
            'created_by' => $this->admin->id,
        ]);

        $this->carrier = FlightCarrier::create([
            'name' => 'Refund Test Airline',
            'code' => 'RFA',
            'flight_system_id' => $this->flightSystem->id,
            'currency' => 'EGP',
            'balance' => 0,
            'credit_limit' => 50000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->cashbox = Account::create([
            'name' => 'Refund Test Cashbox',
            'type' => 'cashbox',
            'balance' => 100000,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->admin->id,
        ]);

        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $this->carrier,
            $this->cashbox,
            100000.00,
            'Refund test setup'
        );

        $this->cashbox->refresh();

        $this->treasury = Treasury::create([
            'name' => 'Refund Test Treasury',
            'currency' => 'EGP',
            'current_balance' => 0,
            'is_active' => true,
        ]);

        Log::info('RefundRequestReversalTest setUp complete', [
            'cashbox_id' => $this->cashbox->id,
            'treasury_id' => $this->treasury->id,
        ]);
    }

    /**
     * Helper: create a paid booking ready for refund.
     */
    protected function createPaidBooking(int $sellingPrice = 18000, int $purchasePrice = 15000): FlightBooking
    {
        $booking = $this->bookingService->createBooking([
            'customer_id'      => $this->customer->id,
            'airline_name'     => 'Refund Test Airline',
            'from_airport'     => 'CAI',
            'to_airport'       => 'JED',
            'departure_date'   => now()->addDays(7)->toDateString(),
            'trip_type'        => 'one_way',
            'currency'         => 'EGP',
            'purchase_price'   => $purchasePrice,
            'selling_price'    => $sellingPrice,
            'flight_carrier_id'=> $this->carrier->id,
            'account_id'       => $this->cashbox->id,
            'passengers'       => [
                ['name' => 'Refund Pax', 'type' => 'adult'],
            ],
        ]);

        $this->bookingService->addPayment($booking, [
            'amount'         => $sellingPrice,
            'payment_method' => 'cash',
            'account_id'     => $this->cashbox->id,
            'notes'          => 'Paid in full',
        ]);

        return $booking;
    }

    public function test_refund_to_agency_treasury_reversal_restores_all_balances(): void
    {
        Log::info('Starting: test_refund_to_agency_treasury_reversal_restores_all_balances');

        $sellingPrice = 18000.0;
        $cancellationFee = 1000.0;
        $refundAmount = $sellingPrice - $cancellationFee; // 17000

        $booking = $this->createPaidBooking((int) $sellingPrice, 15000);
        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);

        // Snapshot BEFORE refund
        $before = [
            'carrier'   => (float) $this->carrier->fresh()->balance,
            'cashbox'   => (float) $this->cashbox->fresh()->balance,
            'treasury'  => (float) $this->treasury->fresh()->current_balance,
        ];

        // Create + process refund to agency_treasury
        $refundRequest = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'cancellation_fee'  => $cancellationFee,
            'refund_currency'   => 'EGP',
            'destination'       => 'agency_treasury',
            'treasury_id'       => $this->treasury->id,
        ], $this->admin->id);

        $this->refundService->processRefundRequest($refundRequest->id, $this->admin->id);

        // Verify the refund had an effect
        // (Refund DEBITS the carrier — opposite of cancellation which credits back.)
        $this->assertEquals(
            $before['carrier'] - $refundAmount,
            (float) $this->carrier->fresh()->balance,
            'After refund: carrier should be debited by refund_amount (ticket returned to airline)'
        );
        $this->assertEquals(
            $before['treasury'] + $refundAmount,
            (float) $this->treasury->fresh()->current_balance,
            'Treasury should have received the refund amount'
        );

        $txCountBeforeReverse = Transaction::query()
            ->where('related_type', RefundRequest::class)
            ->where('related_id', $refundRequest->id)
            ->count();
        // Note: the original refund's GL transaction is related_type=FlightBooking
        // (see RefundService::processRefundRequest line 243). The REVERSAL creates
        // a new tx with related_type=RefundRequest. So the count goes 0 → 1.

        // ── ACT: reverse the refund ──────────────────────────────
        $this->refundService->reverseRefundRequest($refundRequest->id, $this->admin->id);

        // ── ASSERT 1: RefundRequest soft-deleted ──────────────────
        $refundRequest->refresh();
        $this->assertTrue($refundRequest->trashed(), 'RefundRequest must be soft-deleted after reversal');

        // ── ASSERT 2: all balances back to BEFORE-refund state ────
        $after = [
            'carrier'   => (float) $this->carrier->fresh()->balance,
            'cashbox'   => (float) $this->cashbox->fresh()->balance,
            'treasury'  => (float) $this->treasury->fresh()->current_balance,
        ];

        $this->assertEquals(
            round($before['carrier'] - $after['carrier'], 2),
            0.0,
            'Carrier balance delta must be zero after refund reversal'
        );
        $this->assertEquals(
            round($before['cashbox'] - $after['cashbox'], 2),
            0.0,
            'Cashbox balance delta must be zero after refund reversal'
        );
        $this->assertEquals(
            round($before['treasury'] - $after['treasury'], 2),
            0.0,
            'Treasury balance delta must be zero after refund reversal'
        );

        // ── ASSERT 3: reversal transaction exists with RefundRequest related_type ─
        $txCountAfterReverse = Transaction::query()
            ->where('related_type', RefundRequest::class)
            ->where('related_id', $refundRequest->id)
            ->count();

        $this->assertGreaterThan(
            $txCountBeforeReverse,
            $txCountAfterReverse,
            'Reversal must create a new transaction with related_type=RefundRequest'
        );

        Log::info('PASSED: test_refund_to_agency_treasury_reversal_restores_all_balances', [
            'before' => $before,
            'after' => $after,
        ]);
    }

    public function test_refund_to_airline_credit_reversal_cancels_credit_voucher(): void
    {
        Log:: info('Starting: test_refund_to_airline_credit_reversal_cancels_credit_voucher');

        $sellingPrice = 18000.0;

        $booking = $this->createPaidBooking((int) $sellingPrice, 15000);
        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);

        // Create + process refund to airline_credit
        $refundRequest = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'cancellation_fee'  => 0,
            'refund_currency'   => 'EGP',
            'destination'       => 'airline_credit',
        ], $this->admin->id);

        $this->refundService->processRefundRequest($refundRequest->id, $this->admin->id);

        // AirlineCredit voucher should exist + be active
        $credit = AirlineCredit::query()->where('refund_request_id', $refundRequest->id)->first();
        $this->assertNotNull($credit, 'AirlineCredit voucher must be created on refund processing');
        $this->assertEquals('active', $credit->status, 'AirlineCredit must be active before reversal');

        // Carrier balance unchanged (no debit on airline_credit destination)
        $carrierBalanceAfterRefund = (float) $this->carrier->fresh()->balance;

        // ── ACT: reverse the refund ──────────────────────────────
        $this->refundService->reverseRefundRequest($refundRequest->id, $this->admin->id);

        // ── ASSERT 1: RefundRequest soft-deleted ──────────────────
        $refundRequest->refresh();
        $this->assertTrue($refundRequest->trashed(), 'RefundRequest must be soft-deleted after reversal');

        // ── ASSERT 2: AirlineCredit voucher cancelled AND soft-deleted ─
        $credit->refresh();
        $this->assertEquals('cancelled', $credit->status, 'AirlineCredit must be cancelled after reversal');
        $this->assertTrue($credit->trashed(), 'AirlineCredit voucher must be soft-deleted after cancelCredit()');

        // ── ASSERT 3: carrier balance unchanged ───────────────────
        $this->assertEquals(
            $carrierBalanceAfterRefund,
            (float) $this->carrier->fresh()->balance,
            'Carrier balance must not change on airline_credit reversal (no GL posted originally)'
        );

        Log::info('PASSED: test_refund_to_airline_credit_reversal_cancels_credit_voucher', [
            'credit_status' => $credit->status,
            'carrier_balance' => (float) $this->carrier->fresh()->balance,
        ]);
    }

    public function test_double_reversal_of_refund_request_throws(): void
    {
        $booking = $this->createPaidBooking(18000, 15000);
        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);

        $refundRequest = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'cancellation_fee'  => 0,
            'destination'       => 'airline_credit',
        ], $this->admin->id);

        $this->refundService->processRefundRequest($refundRequest->id, $this->admin->id);

        // First reversal succeeds
        $this->refundService->reverseRefundRequest($refundRequest->id, $this->admin->id);

        // Second reversal must throw (idempotency)
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/محذوف بالفعل/');

        $this->refundService->reverseRefundRequest($refundRequest->id, $this->admin->id);
    }

    public function test_reversing_unprocessed_refund_request_soft_deletes_without_gl_change(): void
    {
        $booking = $this->createPaidBooking(18000, 15000);

        // Create refund request but DO NOT process it
        $refundRequest = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'cancellation_fee'  => 0,
            'destination'       => 'airline_credit',
        ], $this->admin->id);

        $carrierBefore = (float) $this->carrier->fresh()->balance;

        // Reverse the pending refund
        $this->refundService->reverseRefundRequest($refundRequest->id, $this->admin->id);

        // No GL impact (was never processed)
        $this->assertEquals(
            $carrierBefore,
            (float) $this->carrier->fresh()->balance,
            'Carrier balance must not change when reversing unprocessed refund'
        );

        $refundRequest->refresh();
        $this->assertTrue($refundRequest->trashed(), 'Pending refund must be soft-deleted');

        Log::info('PASSED: test_reversing_unprocessed_refund_request_soft_deletes_without_gl_change');
    }
}