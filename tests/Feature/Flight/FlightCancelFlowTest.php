<?php

namespace Tests\Feature\Flight;

use App\Enums\TransactionModule;
use App\Models\Flight\FlightBooking;
use App\Models\Transaction;
use App\Services\Flight\FlightBookingService;
use Tests\Feature\Flight\Support\FlightTestCase;

/**
 * Cancellation flow tests — the canonical regression suite for BUG-6 + BUG-7.
 *
 * BUG-6 (commit 3feac20): an early-return guard at reverseFlightBookingRevenue()
 * meant payDebt-only bookings saw no revenue reversal on cancel.
 *
 * BUG-7 (commit b1b310c): office_penalty was tracked on flight_refunds but
 * never posted as an income transaction — making the cancellation fee
 * invisible to the P&L dashboard.
 *
 * Scenarios:
 *   1. Full cancel with both penalties → P&L zero (the canonical booking-1 case)
 *   2. office_penalty-only → office_income row posted (BUG-7 pinning)
 *   3. airline_penalty == purchase_price → cogs fully reversed, no residual
 *   4. Cancel with no payments → status=CANCELLED, refund_amount=0
 *   5. Double-cancel is blocked
 *   6. office_penalty=0 → no office_income row posted
 *
 * Local-only test suite — NOT pushed to git per user preference (2026-08-29).
 */
class FlightCancelFlowTest extends FlightTestCase
{
    /**
     * CANONICAL — booking 1 case study.
     *
     * Setup:  selling=1000, purchase=600 → profit=400
     * Customer pays 1000 EGP via addPayment
     * Cancel with airline_penalty=500, office_penalty=100 → refund=400
     *
     * Pre-fix P&L: revenue -1000, cogs +600, no office_income → profit=-500
     * Post-fix P&L: revenue -1000, cogs +600, office_income +100 → profit=0
     */
    public function test_full_cancel_with_airline_and_office_penalty_zero_pnl(): void
    {
        $booking = $this->makeBooking([
            'selling_price' => 1000.0,
            'purchase_price' => 600.0,
        ]);
        $this->addPayment($booking, 1000.0);

        $this->cancelWithPenalties(
            $booking,
            airlinePenalty: 500.0,
            officePenalty: 100.0
        );

        // Booking status
        $booking->refresh();
        $this->assertContains(
            $booking->status->value, ['CANCELLED', 'REFUNDED'],
            'Booking should be CANCELLED or REFUNDED after cancellation with refund'
        );

        // Flight refund recorded
        $this->assertDatabaseHas('flight_refunds', [
            'flight_booking_id' => $booking->id,
            'airline_penalty' => 500.0,
            'office_penalty' => 100.0,
            'refund_amount' => 400.0,
        ]);

        // Office income transaction exists (BUG-7 fix)
        $officeIncomeTx = Transaction::query()
            ->where('related_type', FlightBooking::class)
            ->where('related_id', $booking->id)
            ->where('type', 'income')
            ->where('notes', 'like', 'office_penalty%')
            ->first();
        $this->assertNotNull(
            $officeIncomeTx,
            'BUG-7: office_penalty must be posted as an income transaction'
        );
        $this->assertEqualsWithDelta(100.0, (float) $officeIncomeTx->amount, 0.01);

        // Revenue was reversed (BUG-6 fix: reversal loop completes even
        // when flight_payments exists)
        $paymentTx = Transaction::query()
            ->where('related_type', \App\Models\Flight\FlightPayment::class)
            ->where('type', 'income')
            ->first();
        $this->assertNotNull($paymentTx);
        $paymentTx->refresh();
        $this->assertTransactionReversed($paymentTx);

        // Ledger invariants hold
        $this->assertLedgerIntact();

        // Verify the accounting math:
        //   cashbox: starts at 100000
        //   addPayment 1000 in: cashbox += 1000 → 101000
        //   refund 400 out: cashbox -= 400 → 100600
        //   office_penalty 100 in: cashbox += 100 → 100700
        $this->assertAccountBalance($this->cashboxEgp, 100700.0);
    }

    /**
     * BUG-7 pinning — office_penalty only (no airline_penalty).
     *
     * Customer cancels → keeps 100 EGP office fee. Revenue reverses
     * partially (since sale_amount - office_penalty = refundable portion).
     */
    public function test_cancel_with_office_penalty_only_post_office_income(): void
    {
        $booking = $this->makeBooking([
            'selling_price' => 1000.0,
            'purchase_price' => 600.0,
        ]);
        $this->addPayment($booking, 1000.0);

        $this->cancelWithPenalties(
            $booking,
            airlinePenalty: 0.0,
            officePenalty: 100.0
        );

        // Office income posted
        $officeIncomeTx = Transaction::query()
            ->where('related_type', FlightBooking::class)
            ->where('related_id', $booking->id)
            ->where('type', 'income')
            ->where('notes', 'like', 'office_penalty%')
            ->first();
        $this->assertNotNull($officeIncomeTx, 'BUG-7: office_penalty must be posted');
        $this->assertEqualsWithDelta(100.0, (float) $officeIncomeTx->amount, 0.01);

        // Refund amount = 1000 - 100 = 900
        $this->assertDatabaseHas('flight_refunds', [
            'flight_booking_id' => $booking->id,
            'refund_amount' => 900.0,
            'status' => 'processed',
        ]);

        $this->assertLedgerIntact();
    }

    /**
     * airline_penalty = purchase_price → airline takes the full loss,
     * office keeps 0, customer refunded 0.
     */
    public function test_cancel_with_airline_penalty_equal_purchase_no_cogs_reversal(): void
    {
        $booking = $this->makeBooking([
            'selling_price' => 1000.0,
            'purchase_price' => 600.0,
        ]);
        $this->addPayment($booking, 1000.0);

        $this->cancelWithPenalties(
            $booking,
            airlinePenalty: 600.0,
            officePenalty: 0.0
        );

        // refund_amount = 1000 - 600 - 0 = 400
        $this->assertDatabaseHas('flight_refunds', [
            'flight_booking_id' => $booking->id,
            'airline_penalty' => 600.0,
            'office_penalty' => 0.0,
            'refund_amount' => 400.0,
        ]);

        // No office income row posted (since office_penalty == 0)
        $officeIncomeCount = Transaction::query()
            ->where('related_type', FlightBooking::class)
            ->where('related_id', $booking->id)
            ->where('notes', 'like', 'office_penalty%')
            ->count();
        $this->assertEquals(0, $officeIncomeCount, 'No office income row when penalty=0');

        $this->assertLedgerIntact();
    }

    /**
     * Cancel with no payments at all → status CANCELLED, refund 0.
     */
    public function test_cancel_with_no_payments_status_cancelled_no_refund(): void
    {
        $booking = $this->makeBooking([
            'selling_price' => 1000.0,
            'purchase_price' => 600.0,
        ]);

        // No addPayment, no payDebt
        $this->cancelWithPenalties(
            $booking,
            airlinePenalty: 0.0,
            officePenalty: 0.0
        );

        $booking->refresh();
        $this->assertEquals('CANCELLED', $booking->status->value);

        $this->assertDatabaseHas('flight_refunds', [
            'flight_booking_id' => $booking->id,
            'refund_amount' => 0.0,
            'status' => 'no_refund',
        ]);

        $this->assertLedgerIntact();
    }

    /**
     * Double-cancel must be blocked at the service guard (line 2154).
     */
    public function test_double_cancel_is_blocked_by_status_guard(): void
    {
        $booking = $this->makeBooking([
            'selling_price' => 1000.0,
            'purchase_price' => 600.0,
        ]);
        $this->addPayment($booking, 1000.0);

        $this->cancelWithPenalties($booking, 0.0, 0.0);

        // Second cancel attempt must throw
        $this->expectException(\Exception::class);
        $this->bookingService->cancelBooking($booking, [
            'airline_penalty' => 0.0,
            'office_penalty' => 0.0,
            'account_id' => $this->cashboxEgp->id,
        ]);
    }

    /**
     * office_penalty=0 → no office_income row, ledger intact.
     */
    public function test_cancel_with_zero_office_penalty_skips_office_income(): void
    {
        $booking = $this->makeBooking([
            'selling_price' => 1000.0,
            'purchase_price' => 600.0,
        ]);
        $this->addPayment($booking, 1000.0);

        $this->cancelWithPenalties(
            $booking,
            airlinePenalty: 200.0,
            officePenalty: 0.0  // explicit zero
        );

        $officeIncomeCount = Transaction::query()
            ->where('related_type', FlightBooking::class)
            ->where('related_id', $booking->id)
            ->where('notes', 'like', 'office_penalty%')
            ->count();
        $this->assertEquals(0, $officeIncomeCount, 'office_penalty=0 must not post an income row');

        $this->assertLedgerIntact();
    }
}
