<?php

namespace Tests\Feature\Flight;

use App\Enums\FlightBookingStatus;
use App\Enums\TransactionModule;
use App\Models\Flight\FlightBooking;
use App\Models\Transaction;
use Tests\Feature\Flight\Support\FlightTestCase;

/**
 * Coverage for the high-priority scenarios that the original 18 tests did NOT exercise.
 *
 * 1. Price updates after creation — `updateBooking` / `updatePrices`
 * 2. Partial payment + cancel — `paid < selling_price` then cancel
 * 3. Over-penalty edge case — `airline_penalty > selling_price`
 * 4. Insufficient carrier balance — debit blocked when carrier has no credit
 *
 * Local-only test suite — NOT pushed to git per user preference (2026-08-29).
 */
class FlightUpdateAndEdgeCasesTest extends FlightTestCase
{
    // ────────────────────────────────────────────────────────────────────────
    // 1. Price updates after creation
    // ────────────────────────────────────────────────────────────────────────

    /**
     * `updatePrices` on a PENDING booking updates the price columns
     * (purchase_price, selling_price, profit) without rebalancing the ledger.
     *
     * Contract pinning (2026-08-29):
     *   - Only PENDING bookings can have prices updated (line 1692 guard)
     *   - Negative prices are rejected (D4 fix at line 1703)
     *   - The carrier's prepaid balance is NOT re-debited — pending bookings
     *     have no real money flow yet.
     *   - The customer's AR sale-debt is NOT re-recorded — same reason.
     */
    public function test_update_prices_on_pending_booking_updates_only_record_not_ledger(): void
    {
        $booking = $this->makeBooking(['selling_price' => 1000.0, 'purchase_price' => 600.0]);
        $originalCarrierBalance = (float) $this->carrier->fresh()->balance;

        // Increase prices
        $this->bookingService->updatePrices($booking->fresh(), 800.0, 1200.0);

        $booking->refresh();
        $this->assertEqualsWithDelta(1200.0, (float) $booking->selling_price, 0.01);
        $this->assertEqualsWithDelta(800.0, (float) $booking->purchase_price, 0.01);
        $this->assertEqualsWithDelta(400.0, (float) $booking->profit, 0.01);

        // Carrier balance should be UNCHANGED — updatePrices doesn't rebalance
        $this->carrier->refresh();
        $this->assertEqualsWithDelta(
            $originalCarrierBalance, (float) $this->carrier->balance, 0.01,
            'updatePrices should NOT re-debit the carrier'
        );

        // Ledger invariants hold (no new transactions posted)
        $this->assertLedgerIntact();
    }

    /**
     * `updatePrices` on a CONFIRMED booking must be REJECTED.
     * Only PENDING status allows price updates (line 1692).
     */
    public function test_update_prices_on_confirmed_booking_throws(): void
    {
        $booking = $this->makeBooking(['selling_price' => 1000.0, 'purchase_price' => 600.0]);
        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);

        $this->expectException(\Exception::class);
        $this->bookingService->updatePrices($booking->fresh(), 800.0, 1200.0);
    }

    /**
     * Negative prices are rejected (D4 defensive guard, line 1703).
     */
    public function test_update_prices_rejects_negative_values(): void
    {
        $booking = $this->makeBooking(['selling_price' => 1000.0, 'purchase_price' => 600.0]);

        $this->expectException(\InvalidArgumentException::class);
        $this->bookingService->updatePrices($booking->fresh(), -100.0, 1000.0);
    }

    /**
     * `updateBooking` on a PENDING booking can change selling_price + purchase_price.
     */
    public function test_update_booking_changes_prices_and_profit(): void
    {
        $booking = $this->makeBooking(['selling_price' => 1000.0, 'purchase_price' => 600.0]);

        $this->bookingService->updateBooking($booking->fresh(), [
            'selling_price' => 1500.0,
            'purchase_price' => 900.0,
        ]);

        $booking->refresh();
        $this->assertEqualsWithDelta(1500.0, (float) $booking->selling_price, 0.01);
        $this->assertEqualsWithDelta(900.0, (float) $booking->purchase_price, 0.01);
        $this->assertEqualsWithDelta(600.0, (float) $booking->profit, 0.01);

        $this->assertLedgerIntact();
    }

    // ────────────────────────────────────────────────────────────────────────
    // 2. Partial payment + cancel
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Partial payment (paid < selling_price) → cancel → refund = partial paid.
     *
     * Selling=1000, paid=400, cancel with no penalties → refund=400 (not 1000).
     */
    public function test_partial_payment_cancel_refunds_only_paid_amount(): void
    {
        $booking = $this->makeBooking(['selling_price' => 1000.0, 'purchase_price' => 600.0]);
        $this->addPayment($booking, 400.0);

        $booking->refresh();
        $this->assertEqualsWithDelta(400.0, (float) $booking->paid_amount, 0.01);

        $this->cancelWithPenalties($booking, airlinePenalty: 0.0, officePenalty: 0.0);

        // refund_amount = total_paid - airline_penalty - office_penalty = 400
        $this->assertDatabaseHas('flight_refunds', [
            'flight_booking_id' => $booking->id,
            'total_paid' => 400.0,
            'refund_amount' => 400.0,
        ]);

        $this->assertLedgerIntact();
    }

    /**
     * Partial payment + cancel with airline_penalty > paid → refund = 0.
     */
    public function test_partial_payment_with_penalty_exceeds_paid_no_refund(): void
    {
        $booking = $this->makeBooking(['selling_price' => 1000.0, 'purchase_price' => 600.0]);
        $this->addPayment($booking, 300.0);

        // airline_penalty=500 > paid=300 → refund_amount = 0
        $this->cancelWithPenalties($booking, airlinePenalty: 500.0, officePenalty: 0.0);

        $this->assertDatabaseHas('flight_refunds', [
            'flight_booking_id' => $booking->id,
            'total_paid' => 300.0,
            'airline_penalty' => 500.0,
            'refund_amount' => 0.0,
            'status' => 'no_refund',
        ]);

        $this->assertLedgerIntact();
    }

    /**
     * Partial payDebt + addPayment → cancel reverses both partial amounts.
     */
    public function test_partial_pay_debt_plus_partial_payment_cancel(): void
    {
        $booking = $this->makeBooking(['selling_price' => 1000.0, 'purchase_price' => 600.0]);

        // 200 via addPayment, 300 via payDebt (total 500 paid out of 1000)
        $this->addPayment($booking, 200.0);
        $this->payDebt(300.0);

        $booking->refresh();
        $this->assertEqualsWithDelta(500.0, (float) $booking->paid_amount, 0.01);

        $this->cancelWithPenalties($booking, 0.0, 0.0);

        // refund = 500 - 0 - 0 = 500
        $this->assertDatabaseHas('flight_refunds', [
            'flight_booking_id' => $booking->id,
            'total_paid' => 500.0,
            'refund_amount' => 500.0,
        ]);

        // payDebt income must be reversed
        $payDebtTx = Transaction::query()
            ->where('related_type', \App\Models\Customer::class)
            ->where('related_id', $this->customer->id)
            ->where('type', 'income')
            ->where('module', TransactionModule::Flight->value)
            ->first();
        $this->assertNotNull($payDebtTx);
        $payDebtTx->refresh();
        $this->assertTransactionReversed($payDebtTx);

        $this->assertLedgerIntact();
    }

    // ────────────────────────────────────────────────────────────────────────
    // 3. Over-penalty edge case
    // ────────────────────────────────────────────────────────────────────────

    /**
     * airline_penalty > selling_price → THROWS InvalidArgumentException.
     *
     * Per line 2262: "مجموع خصم الطيران وعمولة الإلغاء لا يمكن أن يتجاوز مبلغ البيع الأصلي للحجز"
     * (total penalties cannot exceed the original sale amount).
     */
    public function test_over_airline_penalty_throws_invalid_argument(): void
    {
        $booking = $this->makeBooking(['selling_price' => 1000.0, 'purchase_price' => 600.0]);
        $this->addPayment($booking, 1000.0);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/لا يمكن أن يتجاوز مبلغ البيع|exceeds/i');

        $this->cancelWithPenalties(
            $booking,
            airlinePenalty: 1500.0, // > selling_price
            officePenalty: 0.0
        );
    }

    /**
     * Combined over-penalty (airline + office > selling_price) → THROWS.
     */
    public function test_combined_penalties_exceed_selling_throws(): void
    {
        $booking = $this->makeBooking(['selling_price' => 1000.0, 'purchase_price' => 600.0]);
        $this->addPayment($booking, 1000.0);

        $this->expectException(\Exception::class);

        $this->cancelWithPenalties(
            $booking,
            airlinePenalty: 800.0,
            officePenalty: 300.0  // total = 1100 > 1000
        );
    }

    /**
     * Edge case: penalties equal to selling_price → refund = 0, allowed.
     * Tests the boundary condition of the over-penalty guard at line 2262.
     */
    public function test_penalties_equal_to_selling_price_allowed_refund_zero(): void
    {
        $booking = $this->makeBooking(['selling_price' => 1000.0, 'purchase_price' => 600.0]);
        $this->addPayment($booking, 1000.0);

        $this->cancelWithPenalties(
            $booking,
            airlinePenalty: 700.0,
            officePenalty: 300.0  // total = 1000 = selling_price
        );

        $this->assertDatabaseHas('flight_refunds', [
            'flight_booking_id' => $booking->id,
            'airline_penalty' => 700.0,
            'office_penalty' => 300.0,
            'refund_amount' => 0.0,
            'status' => 'no_refund',
        ]);

        // BUG-7: office_penalty still posted as income at boundary
        $officeIncome = Transaction::query()
            ->where('related_type', FlightBooking::class)
            ->where('related_id', $booking->id)
            ->where('notes', 'like', 'office_penalty%')
            ->first();
        $this->assertNotNull($officeIncome, 'BUG-7: office_penalty posted at boundary (refund=0)');
        $this->assertEqualsWithDelta(300.0, (float) $officeIncome->amount, 0.01);

        $this->assertLedgerIntact();
    }

    // ────────────────────────────────────────────────────────────────────────
    // 4. Insufficient carrier balance
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Booking creation must FAIL when carrier has insufficient balance.
     *
     * The `debitFlightCarrier` guard at line 1009 checks `available_balance`
     * (which is `balance + credit_limit`). Drain BOTH balance AND credit_limit
     * to make the booking fail.
     */
    public function test_booking_creation_fails_when_carrier_balance_insufficient(): void
    {
        $this->carrier->refresh();
        $currentBalance = (float) $this->carrier->balance;
        // Set credit_limit=0 and decrement balance directly via the
        // model's protected mutateBalanceInternal path (via the
        // LedgerBalanceMutationGuard which bypasses the observer).
        \App\Support\Finance\LedgerBalanceMutationGuard::run(function () use ($currentBalance) {
            $this->carrier->update(['credit_limit' => 0.0]);
            // Bypass observer guard by manipulating through the same
            // internal path used by debit()/credit().
            \App\Models\Flight\FlightCarrier::withoutEvents(function () use ($currentBalance) {
                \Illuminate\Support\Facades\DB::table('flight_carriers')
                    ->where('id', $this->carrier->id)
                    ->update(['balance' => 0]);
            });
        });

        $this->carrier->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $this->carrier->balance, 0.01, 'Setup: drain carrier');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/رصيد شركة الطيران|carrier balance/i');

        $this->makeBooking([
            'selling_price' => 1000.0,
            'purchase_price' => 600.0,
        ]);
    }

    /**
     * After insufficient-balance rejection, NO transactions should be posted
     * (the booking should be created and rolled back, leaving ledger clean).
     */
    public function test_insufficient_balance_does_not_post_partial_transactions(): void
    {
        $this->carrier->refresh();
        $currentBalance = (float) $this->carrier->balance;
        \App\Support\Finance\LedgerBalanceMutationGuard::run(function () use ($currentBalance) {
            $this->carrier->update(['credit_limit' => 0.0]);
            \App\Models\Flight\FlightCarrier::withoutEvents(function () use ($currentBalance) {
                \Illuminate\Support\Facades\DB::table('flight_carriers')
                    ->where('id', $this->carrier->id)
                    ->update(['balance' => 0]);
            });
        });

        $transactionCountBefore = Transaction::query()->count();

        try {
            $this->makeBooking([
                'selling_price' => 1000.0,
                'purchase_price' => 600.0,
            ]);
            $this->fail('Expected booking creation to throw');
        } catch (\Exception $e) {
            // Expected
        }

        // No new transactions posted (DB::transaction rolled back)
        $transactionCountAfter = Transaction::query()->count();
        $this->assertEquals(
            $transactionCountBefore, $transactionCountAfter,
            'DB transaction should roll back — no partial ledger entries'
        );
    }

    /**
     * Booking succeeds when carrier's available_balance is exactly equal to purchase_price.
     * available_balance = balance + credit_limit.
     */
    public function test_booking_creation_succeeds_with_exact_available_balance(): void
    {
        // Carrier has 50000 balance (from setUp). Drain to exactly 600
        // (purchase_price) so the booking can consume the last credit.
        $toDrain = 50000.0 - 600.0;
        \App\Support\Finance\LedgerBalanceMutationGuard::run(function () use ($toDrain) {
            $this->carrier->update(['credit_limit' => 0.0]);
            \App\Models\Flight\FlightCarrier::withoutEvents(function () use ($toDrain) {
                \Illuminate\Support\Facades\DB::table('flight_carriers')
                    ->where('id', $this->carrier->id)
                    ->update(['balance' => 600.0]);
            });
        });

        $this->carrier->refresh();
        $this->assertEqualsWithDelta(600.0, (float) $this->carrier->balance, 0.01, 'Setup: drain to 600');

        $booking = $this->makeBooking([
            'selling_price' => 1000.0,
            'purchase_price' => 600.0,
        ]);

        $this->carrier->refresh();
        $this->assertEqualsWithDelta(
            0.0, (float) $this->carrier->balance, 0.01,
            'Carrier balance should be zero after exact-match debit'
        );

        $this->assertLedgerIntact();
    }
}
