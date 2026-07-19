<?php

namespace Tests\Feature\Bus;

use App\Enums\BusInventoryPaymentType;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusInventory;
use App\Models\Bus\BusRefundRequest;
use App\Models\Customer;
use App\Models\Treasury;
use App\Services\Bus\BusBookingService;
use App\Services\Bus\BusRefundService;
use App\Support\Finance\LedgerBalanceMutationGuard;

/**
 * FX-hardening tests for the legacy {@see BusRefundService}.
 *
 * Background:
 *   BusRefundService is the LEGACY admin UI path for creating refund
 *   requests (vs. {@see BusBookingService::cancelBooking()} which creates
 *   the BusRefundRequest inline as part of the cancel flow). It has TWO
 *   FX bugs:
 *
 *     ① `createRefundRequest()` hard-codes `$originalCurrency = 'EGP'`
 *       on line 39 — a USD-priced booking produces a refund request
 *       marked as EGP, corrupting downstream reports.
 *
 *     ② The currency-mismatch check in `processRefundRequest()` is
 *       correct (treasury currency vs. refund_currency) but it's only
 *       as strong as the refund_currency field — if ① leaks wrong currency
 *       into the request, the mismatch check fires for the wrong reason.
 *
 *   The fix in this PR: replace the hard-coded EGP with
 *   `$booking->currency ?? 'EGP'` so the request inherits the booking's
 *   actual currency.
 *
 * What this covers:
 *   1. USD booking → refund request has `original_currency = 'USD'`
 *      (after fix). Today: this test fails — flags the bug.
 *   2. SAR booking with penalty → refund_amount + base_currency_refund
 *      math is consistent with the booking currency.
 *   3. processRefundRequest() throws on treasury-currency mismatch
 *      (the validation works correctly when the input is right).
 */
class BusRefundServiceFxHardeningTest extends BusTestCase
{
    // ─────────────────────────────────────────────────────────────────────
    // 1 — USD booking → refund request currency matches booking
    // ─────────────────────────────────────────────────────────────────────

    public function test_create_refund_request_uses_booking_currency_not_hardcoded_egp(): void
    {
        // SETUP: create a USD-priced booking (100 USD = 5000 EGP-equivalent).
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 5,
            'available_tickets' => 5,
            'cost_per_ticket' => 50,
            'selling_price' => 100,
            'payment_type' => BusInventoryPaymentType::Deferred->value,
            'currency' => 'USD',
            'exchange_rate_to_egp' => 50.0,
        ]);

        $customer = Customer::factory()->create(['phone' => '01000002000']);
        $booking = app(BusBookingService::class)->createBooking([
            'inventory_id' => $inventory->id,
            'customer_id' => $customer->id,
            'customer_name' => 'USD Refund',
            'customer_phone' => '01000002000',
            'quantity' => 1,
        ]);
        $this->assertEquals('USD', $booking->currency, 'Booking created in USD');

        // ACTION: legacy refund-request flow.
        $refundRequest = app(BusRefundService::class)->createRefundRequest([
            'bus_booking_id' => $booking->id,
            'cancellation_fee' => 0,
            'notes' => 'admin-initiated USD refund',
        ], $this->user->id);

        // POSTCONDITION (after fix): refund request preserves USD.
        $this->assertEquals(
            'USD',
            $refundRequest->original_currency,
            'BusRefundService::createRefundRequest must inherit the booking currency (was hard-coded to EGP)'
        );
        $this->assertEquals(
            'USD',
            $refundRequest->refund_currency,
            'refund_currency defaults to original_currency — both must be USD'
        );
        $this->assertEquals(100.0, (float) $refundRequest->original_amount, 'original_amount = booking total_price in USD');
        $this->assertEquals(100.0, (float) $refundRequest->refund_amount, 'no penalty → full USD refund');
        $this->assertEquals(5000.0, (float) $refundRequest->base_currency_refund, '100 USD × 50 (booking rate) = 5000 EGP — Fix #7: rate defaults to booking stored rate');
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2 — SAR booking + penalty → refund math is currency-aware
    // ─────────────────────────────────────────────────────────────────────

    public function test_create_refund_request_for_sar_booking_with_penalty_calculates_in_booking_currency(): void
    {
        // SAR booking of 75 SAR with a 25 SAR cancellation fee.
        // Expected: refund_amount = 50 SAR, base_currency_refund = 50 × rate.
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 5,
            'available_tickets' => 5,
            'cost_per_ticket' => 40,
            'selling_price' => 75,
            'payment_type' => BusInventoryPaymentType::Deferred->value,
            'currency' => 'SAR',
            'exchange_rate_to_egp' => 13.3333,
        ]);

        $customer = Customer::factory()->create(['phone' => '01000002001']);
        $booking = app(BusBookingService::class)->createBooking([
            'inventory_id' => $inventory->id,
            'customer_id' => $customer->id,
            'customer_name' => 'SAR Refund',
            'customer_phone' => '01000002001',
            'quantity' => 1,
        ]);

        $refundRequest = app(BusRefundService::class)->createRefundRequest([
            'bus_booking_id' => $booking->id,
            'cancellation_fee' => 25.0,
            'refund_exchange_rate' => 13.3333,
        ], $this->user->id);

        $this->assertEquals('SAR', $refundRequest->original_currency);
        $this->assertEquals(75.0, (float) $refundRequest->original_amount);
        $this->assertEquals(25.0, (float) $refundRequest->cancellation_fee);
        $this->assertEquals(50.0, (float) $refundRequest->refund_amount, '75 - 25 = 50 SAR');
        $this->assertEquals(
            666.67,
            round((float) $refundRequest->base_currency_refund, 2),
            '50 SAR × 13.3333 = ~666.67 EGP-equivalent'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3 — processRefundRequest validates treasury currency matches
    // ─────────────────────────────────────────────────────────────────────

    public function test_process_refund_request_throws_when_treasury_currency_mismatches(): void
    {
        // The currency-mismatch guard in processRefundRequest is correct
        // (it's NOT a bug). This test pins the behavior: a refund request
        // whose refund_currency differs from the destination treasury's
        // currency must be rejected with a clear Arabic error.
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 5,
            'available_tickets' => 5,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
            'payment_type' => BusInventoryPaymentType::Deferred->value,
        ]);

        $customer = Customer::factory()->create(['phone' => '01000002002']);
        $booking = app(BusBookingService::class)->createBooking([
            'inventory_id' => $inventory->id,
            'customer_id' => $customer->id,
            'customer_name' => 'Mismatch Test',
            'customer_phone' => '01000002002',
            'quantity' => 1,
        ]);

        // Create a USD treasury — but the booking (and refund) is EGP.
        $usdTreasury = LedgerBalanceMutationGuard::run(fn () => Treasury::create([
            'name' => 'USD Treasury (mismatch)',
            'currency' => 'USD',
            'current_balance' => 1000.0,
            'is_active' => true,
        ]));

        // Pre-fix: the refund request is created with refund_currency=EGP
        // (hard-coded). Post-fix: it inherits booking.currency=EGP.
        $refundRequest = app(BusRefundService::class)->createRefundRequest([
            'bus_booking_id' => $booking->id,
            'cancellation_fee' => 0,
            'destination' => 'agency_treasury',
            'treasury_id' => $usdTreasury->id,
            'refund_currency' => 'EGP', // explicit, to pin the test
        ], $this->user->id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/تضارب في العملة|currency/i');

        app(BusRefundService::class)->processRefundRequest($refundRequest->id, $this->user->id);

        // Sanity: the booking is unchanged (processRefundRequest threw before mutating it).
        $this->assertEquals(4, (int) $inventory->fresh()->available_tickets, 'Capacity NOT restored (process threw before mutating)');
    }
}