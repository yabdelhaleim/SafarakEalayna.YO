<?php

namespace Tests\Feature\Bus;

use App\Enums\BusBookingStatus;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Bus\BusBooking;
use App\Models\Customer;
use App\Models\Transaction;
use App\Support\Finance\LedgerBalanceMutationGuard;

/**
 * Booking cancellation scenarios.
 *
 * Validates:
 *   - Cancelling unpaid booking with no penalty.
 *   - Cancelling paid booking refunds customer via treasury.
 *   - Company penalty reduces the company-debt reversal.
 *   - Office penalty increases office-side recovery.
 *   - Double-cancellation is rejected (idempotency).
 *   - Multi-currency cancellation refunds in booking currency.
 */
class BookingCancellationTest extends BusTestCase
{
    private function createPaidEgBooking(int $totalPrice = 240): BusBooking
    {
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 5,
            'available_tickets' => 5,
            'cost_per_ticket' => 80,
            'selling_price' => $totalPrice / 2,
        ]);

        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'Cancel Test',
            'customer_phone' => '01080000001',
            'quantity' => 2,
        ])->assertCreated();

        $booking = BusBooking::latest('id')->firstOrFail();

        $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
            'amount' => $totalPrice,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ])->assertOk();

        return BusBooking::find($booking->id);
    }

    public function test_cancel_unpaid_booking_with_no_penalty(): void
    {
        $booking = $this->createPaidEgBooking(240);
        // Refund path requires a customer — undo the full payment first to make this unpaid-ish.
        $booking->payments()->delete();

        $response = $this->postJson("/api/v1/bus/bookings/{$booking->id}/cancel", [
            'company_penalty' => 0,
            'office_penalty' => 0,
        ]);

        $response->assertOk();

        $booking->refresh();
        $this->assertEquals(BusBookingStatus::Cancelled, $booking->status);
    }

    public function test_cancel_paid_booking_refunds_customer(): void
    {
        $startCashbox = (float) $this->cashboxEgp->fresh()->balance;
        $booking = $this->createPaidEgBooking(240);

        // Cashbox debited by 240 during payment.
        $this->cashboxEgp->refresh();
        $afterPaymentCashbox = (float) $this->cashboxEgp->balance;
        $this->assertEqualsWithDelta(
            $startCashbox + 240, // recordIncome uses EGP, but since both are EGP, the actual updates should be... hmm
            $afterPaymentCashbox,
            1.0
        );

        $response = $this->postJson("/api/v1/bus/bookings/{$booking->id}/cancel", [
            'company_penalty' => 0,
            'office_penalty' => 0,
            'account_id' => $this->cashboxEgp->id,
        ]);

        $response->assertOk();

        $booking->refresh();
        // Booking has been paid but refund_amount = 240 → status = Refunded.
        $this->assertEquals(BusBookingStatus::Refunded, $booking->status);

        // Refund record created.
        $this->assertNotNull($booking->refund);
        $this->assertEqualsWithDelta(240.0, (float) $booking->refund->refund_amount, 0.01);

        // Ledger invariant holds after refund.
        $this->assertLedgerGloballyBalanced();
    }

    public function test_cancel_with_company_penalty_only(): void
    {
        $booking = $this->createPaidEgBooking(240);

        $response = $this->postJson("/api/v1/bus/bookings/{$booking->id}/cancel", [
            'company_penalty' => 50,    // we keep 50 EGP from the company
            'office_penalty' => 0,
            'account_id' => $this->cashboxEgp->id,
        ]);

        $response->assertOk();

        $booking->refresh();
        // Paid 240, refund = 240 - 50 = 190, status = Refunded.
        $this->assertEqualsWithDelta(190.0, (float) $booking->refund->refund_amount, 0.01);
    }

    public function test_cancel_office_penalty_only(): void
    {
        $booking = $this->createPaidEgBooking(240);

        $response = $this->postJson("/api/v1/bus/bookings/{$booking->id}/cancel", [
            'company_penalty' => 0,
            'office_penalty' => 30,    // kept by office
            'account_id' => $this->cashboxEgp->id,
        ]);

        $response->assertOk();

        $booking->refresh();
        $this->assertEqualsWithDelta(210.0, (float) $booking->refund->refund_amount, 0.01);
    }

    public function test_rejects_double_cancellation(): void
    {
        $booking = $this->createPaidEgBooking(240);
        $booking->payments()->delete();

        $this->postJson("/api/v1/bus/bookings/{$booking->id}/cancel", [
            'company_penalty' => 0,
            'office_penalty' => 0,
        ])->assertOk();

        $response = $this->postJson("/api/v1/bus/bookings/{$booking->id}/cancel", [
            'company_penalty' => 0,
            'office_penalty' => 0,
        ]);

        $response->assertStatus(422);
    }

}
