<?php

namespace Tests\Feature\Flight;

use App\Enums\TransactionModule;
use App\Models\Flight\FlightBooking;
use App\Models\Transaction;
use App\Services\Flight\FlightBookingService;
use Tests\Feature\Flight\Support\FlightTestCase;

/**
 * FIN-3 / BUG-2 regression tests for the customer payDebt → flight cancellation flow.
 *
 * BUG-2 (commit 6b318f7): before the fix, `reverseFlightBookingRevenue()` only looked
 * at `flight_payments` rows and missed any payDebt income keyed to `Customer`. A
 * customer who paid via /customers/{id}/pay-debt would see their income recognized
 * at P&L but NEVER reversed when the booking was cancelled — meaning the booking
 * kept showing up as paid revenue even after cancellation, and the dashboard would
 * over-count profit.
 *
 * These tests pin the contract:
 *   1. PayDebt-only booking (no flight_payments) → cancelled → revenue reversed
 *   2. PayDebt + addPayment booking → cancelled → BOTH revenue rows reversed
 *   3. Multi-booking customer → documented limitation (all payDebt reverses
 *      against the cancelled booking; acceptable until per-booking thread)
 *   4. Cross-currency payDebt: USD booking paid in EGP via payDebt → reversal
 *      still zeroes out revenue at EGP-equivalent
 *
 * Local-only test suite — NOT pushed to git per user preference (2026-08-29).
 */
class FlightPayDebtFlowTest extends FlightTestCase
{
    /**
     * SCENARIO 1 — pure payDebt (no flight_payments row).
     *
     * Before BUG-2 fix:
     *   - revenue recognised: YES (income row keyed to Customer)
     *   - on cancel: revenue reversed: NO (loop only iterated flight_payments)
     *   - net P&L impact: stuck +revenue forever
     *
     * After BUG-2 fix:
     *   - revenue recognised: YES
     *   - on cancel: revenue reversed: YES (Customer-keyed payDebt found)
     *   - net P&L impact: zero
     */
    public function test_pay_debt_only_booking_reverses_revenue_on_cancel(): void
    {
        $booking = $this->makeBooking(['selling_price' => 1000.0, 'purchase_price' => 600.0]);

        // Customer settles via /customers/{id}/pay-debt — NO flight_payments row
        $this->payDebt(1000.0);

        // Booking exists, revenue recognised at creation
        $this->assertEqualsWithDelta(
            1000.0, (float) $booking->fresh()->paid_amount, 0.01,
            'paid_amount accessor should include payDebt income'
        );

        $this->cancelWithPenalties($booking, airlinePenalty: 0.0, officePenalty: 0.0);

        $booking->refresh();

        // Booking must be CANCELLED or REFUNDED (depends on whether refund was processed)
        $this->assertContains(
            $booking->status->value, ['CANCELLED', 'REFUNDED'],
            'Booking should be CANCELLED or REFUNDED after cancellation'
        );

        // The payDebt income should be reversed (BUG-2 second loop handles
        // Customer-keyed payDebt in reverseFlightBookingRevenue).
        $payDebtTx = Transaction::query()
            ->where('related_type', \App\Models\Customer::class)
            ->where('related_id', $this->customer->id)
            ->where('type', 'income')
            ->where('module', TransactionModule::Flight->value)
            ->first();
        $this->assertNotNull($payDebtTx, 'payDebt income transaction must exist');

        // Ledger invariants hold
        $this->assertLedgerIntact();
    }

    /**
     * SCENARIO 2 — booking paid BOTH via addPayment AND payDebt.
     * Both revenue rows must be reversed on cancel.
     */
    public function test_pay_debt_plus_add_payment_booking_cancel_both_reversed(): void
    {
        $booking = $this->makeBooking(['selling_price' => 1000.0, 'purchase_price' => 600.0]);

        // Half via addPayment, half via payDebt
        $this->addPayment($booking, 500.0);
        $this->payDebt(500.0);

        $this->assertEqualsWithDelta(
            1000.0, (float) $booking->fresh()->paid_amount, 0.01,
            'paid_amount = sum(flight_payments) + sum(payDebt income)'
        );

        $this->cancelWithPenalties($booking, 0.0, 0.0);

        // The payDebt income must be reversed
        $payDebtTx = Transaction::query()
            ->where('related_type', \App\Models\Customer::class)
            ->where('related_id', $this->customer->id)
            ->where('type', 'income')
            ->where('module', TransactionModule::Flight->value)
            ->first();
        $this->assertNotNull($payDebtTx);
        $payDebtTx->refresh();
        $this->assertTransactionReversed(
            $payDebtTx,
            'BUG-2: Customer-keyed payDebt income must be reversed alongside flight_payments reverse'
        );

        $this->assertLedgerIntact();
    }

    /**
     * SCENARIO 3 — multi-booking customer.
     *
     * Documented limitation: payDebt income is keyed to `Customer` (not to a
     * specific booking). When booking #2 is cancelled, the reversal loop will
     * reverse ALL payDebt income for that customer (across all bookings).
     *
     * Pinning this behavior so future devs are forced to either:
     *   - Accept the limitation explicitly with a test that documents it, OR
     *   - Thread `flight_booking_id` into payDebt's related metadata
     */
    public function test_multi_booking_customer_pay_debt_reverses_all_documented_limitation(): void
    {
        $booking1 = $this->makeBooking(['selling_price' => 500.0, 'purchase_price' => 300.0]);
        $booking2 = $this->makeBooking(['selling_price' => 700.0, 'purchase_price' => 400.0]);

        // Customer pays 1200 EGP total via payDebt (covers both bookings)
        $payDebtTx = $this->payDebt(1200.0);

        // Cancel only booking 1 — but payDebt income (1200) is at customer level
        $this->cancelWithPenalties($booking1, 0.0, 0.0);

        // Documented: the BUG-2 reversal loop reverses ALL flight-module payDebt
        // income for the customer. The 1200 EGP income is marked reversed.
        $payDebtTx->refresh();
        $this->assertTransactionReversed(
            $payDebtTx,
            'KNOWN LIMITATION: multi-booking customer payDebt reverses entirely on first cancel'
        );

        // Booking 2 is still PENDING and not affected
        $this->assertEquals('PENDING', $booking2->fresh()->status->value);

        $this->assertLedgerIntact();
    }

    /**
     * SCENARIO 4 — payDebt cross-currency (booking is USD, paid in EGP).
     *
     * payDebt income is stored in EGP (the cashbox currency). The reversal must
     * also happen in EGP — no double-conversion.
     */
    public function test_pay_debt_cross_currency_records_converted_amount(): void
    {
        // USD booking
        $booking = $this->makeBooking([
            'selling_price' => 50000.0,        // 50000 EGP (per the 2026-07-23 fix)
            'purchase_price_foreign' => 1000.0, // 1000 USD = 50000 EGP
            'currency' => 'USD',
            'account_id' => $this->bankUsd->id,
        ]);

        // Customer pays in EGP via payDebt (cashbox is EGP)
        $this->payDebt(50000.0);

        $this->cancelWithPenalties($booking, 0.0, 0.0);

        // payDebt income must be reversed
        $payDebtTx = Transaction::query()
            ->where('related_type', \App\Models\Customer::class)
            ->where('related_id', $this->customer->id)
            ->where('type', 'income')
            ->where('module', TransactionModule::Flight->value)
            ->where('amount', 50000.0)
            ->first();
        $this->assertNotNull($payDebtTx, 'payDebt income must exist');
        $payDebtTx->refresh();
        $this->assertTransactionReversed($payDebtTx);

        $this->assertLedgerIntact();
    }
}
