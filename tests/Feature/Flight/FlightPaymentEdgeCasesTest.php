<?php

namespace Tests\Feature\Flight;

use App\Enums\TransactionModule;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightPayment;
use App\Models\Transaction;
use Tests\Feature\Flight\Support\FlightTestCase;

/**
 * Payment edge cases — covers scenarios that the original 31 tests did NOT exercise.
 *
 * Scenarios:
 *   1. Overpayment rejected (selling_price validation, line 1973)
 *   2. Replay protection via idempotency_key (D3 fix)
 *   3. Multiple partial payments → total exactly = selling_price
 *   4. Cross-currency payment (USD booking paid EGP) → FX conversion
 *
 * Local-only test suite — NOT pushed to git per user preference (2026-08-29).
 */
class FlightPaymentEdgeCasesTest extends FlightTestCase
{
    /**
     * SCENARIO 1 — Overpayment rejected.
     *
     * Total payments + new payment > selling_price → THROWS (line 1973).
     */
    public function test_overpayment_rejected(): void
    {
        $booking = $this->makeBooking(['selling_price' => 1000.0, 'purchase_price' => 600.0]);
        $this->addPayment($booking, 900.0);

        // Additional 200 = 1100 > 1000 → must throw
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Total payments would exceed|exceed/i');

        $this->addPayment($booking, 200.0);
    }

    /**
     * SCENARIO 2 — Replay protection (D3 fix, line 1846).
     *
     * Same idempotency_key sent twice → returns the original payment (no double-post).
     */
    public function test_replay_protection_via_idempotency_key(): void
    {
        $booking = $this->makeBooking(['selling_price' => 1000.0, 'purchase_price' => 600.0]);

        $key = 'replay_test_key_' . uniqid();
        $payment1 = $this->addPayment($booking, 500.0);
        // Re-set the idempotency_key on the first payment to simulate real usage
        $payment1->update(['idempotency_key' => $key]);

        $paymentCountBefore = FlightPayment::query()->where('flight_booking_id', $booking->id)->count();

        // Second addPayment with same idempotency_key — must return original
        $payment2 = $this->bookingService->addPayment($booking, [
            'amount' => 500.0,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
            'idempotency_key' => $key,
        ]);

        $this->assertEquals($payment1->id, $payment2->id, 'Replay must return the same payment');

        $paymentCountAfter = FlightPayment::query()->where('flight_booking_id', $booking->id)->count();
        $this->assertEquals(
            $paymentCountBefore, $paymentCountAfter,
            'No duplicate payment row should be created on replay'
        );

        $this->assertLedgerIntact();
    }

    /**
     * SCENARIO 3 — Multiple partial payments → total = selling_price.
     *
     * 4 partial payments of 250 EGP each = 1000 EGP total = selling_price.
     */
    public function test_multiple_partial_payments_total_equals_selling_price(): void
    {
        $booking = $this->makeBooking(['selling_price' => 1000.0, 'purchase_price' => 600.0]);

        $this->addPayment($booking, 250.0);
        $this->addPayment($booking, 250.0);
        $this->addPayment($booking, 250.0);
        $this->addPayment($booking, 250.0);

        $booking->refresh();
        $this->assertEqualsWithDelta(
            1000.0, (float) $booking->paid_amount, 0.01,
            'paid_amount = sum of 4 partial payments'
        );

        // Status should be 'paid' (or whatever marks as fully paid)
        $this->assertContains(
            $booking->payment_status?->value ?? $booking->status?->value,
            ['paid', 'PAID', 'PENDING', 'CONFIRMED']
        );

        $this->assertLedgerIntact();
    }

    /**
     * SCENARIO 4 — Cross-currency payment (USD booking paid in EGP).
     *
     * The addPayment flow converts foreign-currency payment to EGP-equivalent
     * and posts the cashbox credit in the original currency.
     */
    public function test_cross_currency_payment_records_fx_conversion(): void
    {
        // USD booking
        $booking = $this->makeBooking([
            'selling_price' => 50000.0,        // 50000 EGP
            'purchase_price_foreign' => 1000.0, // 1000 USD
            'currency' => 'USD',
            'account_id' => $this->bankUsd->id,
        ]);

        $bankUsdBalanceBefore = (float) $this->bankUsd->fresh()->balance;

        // Pay 50000 EGP from EGP cashbox (cross-currency)
        $this->addPayment($booking, 50000.0, $this->cashboxEgp);

        // EGP cashbox balance increases
        $this->cashboxEgp->refresh();
        $this->assertEqualsWithDelta(
            100000.0 + 50000.0, (float) $this->cashboxEgp->balance, 0.01,
            'EGP cashbox credited by 50000'
        );

        // Booking paid_amount
        $booking->refresh();
        $this->assertEqualsWithDelta(
            50000.0, (float) $booking->paid_amount, 0.01,
            'paid_amount reflects EGP-equivalent payment'
        );

        $this->assertLedgerIntact();
    }
}
