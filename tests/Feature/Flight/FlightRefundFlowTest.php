<?php

namespace Tests\Feature\Flight;

use App\Enums\FlightBookingStatus;
use App\Enums\TransactionModule;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\RefundRequest;
use App\Models\Transaction;
use Tests\Feature\Flight\Support\FlightTestCase;

/**
 * Refund flow tests — covers customer-initiated refund requests and admin
 * booking deletion paths that the original 31 tests did NOT exercise.
 *
 * Scenarios:
 *   1. RefundRequest: full refund via agency treasury → reverses revenue + COGS
 *   2. RefundRequest: cumulative refunds capped at original_amount (C5 fix)
 *   3. deleteBookingWithReversal: full reversal including payDebt income
 *
 * Local-only test suite — NOT pushed to git per user preference (2026-08-29).
 */
class FlightRefundFlowTest extends FlightTestCase
{
    /**
     * SCENARIO 1 — Customer-initiated refund via agency treasury.
     *
     * Booking must be CONFIRMED before refund request can be created (Bug C4 fix).
     * processRefundRequest posts:
     *   - Reversal journal: customer → clearing (reverses sale)
     *   - Credit back to carrier/system prepaid GL (reverses COGS)
     *   - Cash out: cashbox → customer (refund disbursement)
     */
    public function test_full_refund_via_agency_treasury_reverses_revenue_and_cogs(): void
    {
        $booking = $this->makeBooking(['selling_price' => 1000.0, 'purchase_price' => 600.0]);
        $this->addPayment($booking, 1000.0);

        // Confirm booking (required for refund request — C4 fix)
        $this->bookingService->confirmBooking($booking);

        $transactionCountBefore = Transaction::query()->count();

        // Create + process the refund request
        $refundRequest = \App\Models\Flight\RefundRequest::create([
            'flight_booking_id' => $booking->id,
            'customer_id' => $this->customer->id,
            'destination' => 'agency_treasury',
            'refund_type' => 'cash', // required by schema
            'original_currency' => 'EGP',
            'original_amount' => 1000.0,
            'cancellation_fee' => 0.0,
            'refund_amount' => 1000.0,
            'refund_currency' => 'EGP',
            'treasury_id' => $this->treasuryEgp->id,
            'status' => 'pending',
            'reason' => 'Test refund',
            'created_by' => $this->admin->id,
        ]);

        \App\Support\Finance\LedgerBalanceMutationGuard::run(function () use ($refundRequest) {
            app(\App\Services\Flight\RefundService::class)->processRefundRequest($refundRequest->id, $this->admin->id);
        });

        // Booking status should reflect refund processed
        $booking->refresh();
        $this->assertContains($booking->status->value, ['REFUNDED', 'PARTIALLY_REFUNDED']);

        // New reversal transactions posted
        $transactionCountAfter = Transaction::query()->count();
        $this->assertGreaterThan($transactionCountBefore, $transactionCountAfter);

        // Ledger invariants hold
        $this->assertLedgerIntact();
    }

    /**
     * SCENARIO 2 — Cumulative refund cap (Bug C5 fix).
     *
     * Two refund requests that would exceed original_amount together
     * must be REJECTED at the validation layer (line 274).
     */
    public function test_cumulative_refunds_capped_at_original_amount(): void
    {
        $booking = $this->makeBooking(['selling_price' => 1000.0, 'purchase_price' => 600.0]);
        $this->addPayment($booking, 1000.0);
        $this->bookingService->confirmBooking($booking);

        // First refund: 600 OK
        \App\Models\Flight\RefundRequest::create([
            'flight_booking_id' => $booking->id,
            'customer_id' => $this->customer->id,
            'destination' => 'agency_treasury',
            'refund_type' => 'cash',
            'original_currency' => 'EGP',
            'original_amount' => 1000.0,
            'cancellation_fee' => 400.0,
            'refund_amount' => 600.0,
            'refund_currency' => 'EGP',
            'treasury_id' => $this->treasuryEgp->id,
            'status' => 'processed',
            'reason' => 'First partial',
            'created_by' => $this->admin->id,
        ]);

        // Second refund: would exceed 1000 → must throw
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/لا يمكن أن يتجاوز|exceed|تجاوز/');

        app(\App\Services\Flight\RefundService::class)->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'customer_id' => $this->customer->id,
            'destination' => 'agency_treasury',
            'refund_type' => 'cash',
            'cancellation_fee' => 0.0,
            'refund_amount' => 500.0, // 600 + 500 = 1100 > 1000
            'refund_currency' => 'EGP',
            'treasury_id' => $this->treasuryEgp->id,
            'reason' => 'Second refund',
        ], $this->admin->id);
    }

    /**
     * SCENARIO 3 — deleteBookingWithReversal on a payDebt-paid booking.
     *
     * The delete flow must:
     *   - Reverse each flight_payment (creates reversal journals)
     *   - Reverse Customer-keyed payDebt income (mirror of BUG-2 logic)
     *   - Reverse the GL sale journal
     *   - Soft-delete the booking
     */
    public function test_delete_booking_with_pay_debt_reverses_all_income(): void
    {
        $booking = $this->makeBooking(['selling_price' => 1000.0, 'purchase_price' => 600.0]);
        $this->addPayment($booking, 400.0);
        $this->payDebt(600.0); // Total 1000 paid

        $bookingId = $booking->id;

        // Soft-delete via the service
        FlightBooking::run(function () use ($bookingId) {
            $this->bookingService->deleteBookingWithReversal($bookingId, $this->admin->id);
        });

        // Booking is soft-deleted
        $deleted = FlightBooking::withTrashed()->find($bookingId);
        $this->assertNotNull($deleted->deleted_at, 'Booking must be soft-deleted');

        // The payDebt income should be reversed (Customer-keyed)
        $payDebtTx = Transaction::query()
            ->where('related_type', \App\Models\Customer::class)
            ->where('related_id', $this->customer->id)
            ->where('type', 'income')
            ->where('module', TransactionModule::Flight->value)
            ->whereNull('notes') // active (not reversed)
            ->first();

        // Note: payDebt income may already be reversed by the delete flow.
        // We just verify that there are NO active (non-prefixed) income
        // transactions for this customer at the flight module level.
        $activeIncomeCount = Transaction::query()
            ->where('related_type', \App\Models\Customer::class)
            ->where('related_id', $this->customer->id)
            ->where('type', 'income')
            ->where('module', TransactionModule::Flight->value)
            ->whereNull('notes')
            ->count();
        $this->assertEquals(0, $activeIncomeCount, 'All payDebt income must be reversed after delete');

        $this->assertLedgerIntact();
    }
}
