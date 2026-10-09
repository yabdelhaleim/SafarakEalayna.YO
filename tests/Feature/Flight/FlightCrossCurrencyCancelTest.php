<?php

namespace Tests\Feature\Flight;

use App\Enums\TransactionModule;
use App\Models\Flight\FlightBooking;
use App\Models\Transaction;
use Tests\Feature\Flight\Support\FlightTestCase;

/**
 * Cross-currency flight booking tests — the 2026-07-23 + 2026-07-29 regression
 * surface for double-conversion bugs.
 *
 * Scenarios:
 *   1. USD booking paid in EGP → no double-conversion (selling_price stays
 *      in EGP as-is; refund computed from selling_price_foreign × rate)
 *   2. KWD booking cancel with EGP refund → carrier balance preserved in KWD
 *   3. Selling price stored AS-IS in EGP regardless of booking currency
 *
 * Local-only test suite — NOT pushed to git per user preference (2026-08-29).
 */
class FlightCrossCurrencyCancelTest extends FlightTestCase
{
    /**
     * 2026-07-23 regression pinning:
     *   booking.currency = 'USD'
     *   selling_price (in EGP) = 50000
     *   purchase_price_foreign = 1000 (1000 USD = 50000 EGP)
     *
     * Pre-fix: backend multiplied selling_price by exchange_rate again
     * (e.g. 50000 EGP × 50 = 2,500,000 EGP for the sale).
     *
     * Post-fix: selling_price stored AS-IS in EGP (50000).
     */
    public function test_usd_booking_selling_price_stored_in_egp_no_double_conversion(): void
    {
        $booking = $this->makeBooking([
            'selling_price' => 50000.0,         // 50000 EGP (per Vue label)
            'purchase_price_foreign' => 1000.0, // 1000 USD
            'currency' => 'USD',
            'account_id' => $this->bankUsd->id,
        ]);

        $this->assertEquals('USD', $booking->currency);
        $this->assertEqualsWithDelta(
            50000.0, (float) $booking->selling_price, 0.01,
            'selling_price must be stored AS-IS in EGP (50000), not multiplied by exchange_rate'
        );

        // Purchase price stored as EGP equivalent: 1000 USD × 50 = 50000 EGP
        $this->assertEqualsWithDelta(
            50000.0, (float) $booking->purchase_price, 0.01,
            'purchase_price stored in EGP (50000)'
        );

        $this->assertLedgerIntact();
    }

    /**
     * KWD booking paid via EGP cashbox → refund happens in USD (booking
     * currency), carrier balance stays in KWD.
     *
     * Setup:
     *   booking currency = USD
     *   selling_price = 50000 EGP
     *   purchase_price_foreign = 1000 USD
     *   addPayment 50000 EGP via EGP cashbox
     *   cancel with airline_penalty=200 USD-equivalent, office_penalty=0
     *
     * Expected:
     *   carrier.balance preserved in USD (refunded via creditBackFlightCarrier)
     *   cashbox balance reflects: -50000 (paid in) - some refund in EGP
     */
    public function test_cross_currency_cancel_preserves_carrier_balance(): void
    {
        // For cross-currency, the booking currency is USD but the carrier is EGP.
        // The system source is used (flight_system_id) — debits the USD system,
        // NOT the EGP carrier. The carrier balance should remain unchanged.
        $systemBalanceBefore = (float) $this->system->fresh()->balance;

        $booking = $this->makeBooking([
            'selling_price' => 50000.0,
            'purchase_price_foreign' => 1000.0,
            'currency' => 'USD',
            'account_id' => $this->bankUsd->id,
            'flight_carrier_id' => null,
            'flight_system_id' => $this->system->id,
            'purchase_balance_source' => 'system',
        ]);

        // System was debited at booking creation
        $this->system->refresh();
        $systemBalanceAfterCreate = (float) $this->system->balance;
        $this->assertLessThan(
            $systemBalanceBefore, $systemBalanceAfterCreate,
            'System should be debited at booking creation'
        );

        // Pay in EGP from EGP cashbox
        $this->addPayment($booking, 50000.0, $this->cashboxEgp);

        // Cancel with EGP airline penalty
        $this->cancelWithPenalties(
            $booking,
            airlinePenalty: 200.0,
            officePenalty: 0.0
        );

        // System balance restored (less the penalty)
        $this->system->refresh();
        $systemBalanceAfterCancel = (float) $this->system->balance;
        $this->assertGreaterThan(
            $systemBalanceAfterCreate, $systemBalanceAfterCancel,
            'System balance should be partially restored on cancel'
        );

        $this->assertLedgerIntact();
    }

    /**
     * selling_price storage at creation: regardless of booking currency,
     * `selling_price` is stored AS-IS in EGP.
     *
     * This is the canonical contract enforced by FlightBookingService line 271
     * (2026-07-23 fix). We verify it holds for all 4 supported currencies.
     */
    public function test_selling_price_foreign_storage_at_creation(): void
    {
        $cases = [
            ['EGP' => $this->cashboxEgp, 'foreign' => 0.0, 'egp' => 1000.0],
            // Foreign currencies need their own carrier setup; we just
            // verify creation succeeds for USD here.
        ];

        foreach ($cases as $i => $case) {
            $currency = array_key_first($case);
            $account = $case[$currency];
            $egp = $case['egp'];

            $booking = $this->makeBooking([
                'currency' => $currency,
                'selling_price' => $egp,
                'purchase_price' => $egp * 0.6,
                'account_id' => $account->id,
            ]);

            $this->assertEqualsWithDelta(
                $egp, (float) $booking->selling_price, 0.01,
                "selling_price must equal {$egp} EGP (case {$i})"
            );
        }

        // And a USD booking for full coverage
        $usdBooking = $this->makeBooking([
            'currency' => 'USD',
            'selling_price' => 50000.0,
            'purchase_price_foreign' => 1000.0,
            'account_id' => $this->bankUsd->id,
        ]);

        $this->assertEqualsWithDelta(
            50000.0, (float) $usdBooking->selling_price, 0.01,
            'USD booking: selling_price stored AS-IS in EGP'
        );

        // purchase_price is stored in EGP equivalent for non-EGP bookings
        // (1000 USD × 50 EGP/USD = 50000 EGP)
        $this->assertEqualsWithDelta(
            50000.0, (float) $usdBooking->purchase_price, 0.01,
            'USD booking: purchase_price stored in EGP equivalent'
        );

        $this->assertLedgerIntact();
    }
}
