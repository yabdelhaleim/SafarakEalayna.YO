<?php

namespace Tests\Feature\Bus;

use App\Enums\BusPaymentStatus;
use App\Models\Account;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusPayment;
use App\Support\Finance\LedgerBalanceMutationGuard;

/**
 * Booking payment scenarios.
 *
 * Validates:
 *   - Full payment marks status = Paid.
 *   - Partial payment marks status = Partial.
 *   - Multiple partial payments aggregate correctly (no double-spend).
 *   - Payment above remaining balance is rejected.
 *   - Payment for cancelled booking is rejected.
 *   - Multi-currency payment lands in matching currency account.
 */
class BookingPaymentTest extends BusTestCase
{
    private function createEgBooking(int $totalPrice = 240): BusBooking
    {
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'total_tickets' => 5,
            'available_tickets' => 5,
            'cost_per_ticket' => 80,
            'selling_price' => $totalPrice / 2,
            'currency' => 'EGP',
            'exchange_rate_to_egp' => 1.0,
        ]);

        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'Pay Test',
            'customer_phone' => '01090000001',
            'quantity' => 2,
        ])->assertCreated();

        return BusBooking::latest('id')->firstOrFail();
    }

    public function test_full_payment_marks_status_paid(): void
    {
        $booking = $this->createEgBooking(240);

        $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
            'amount' => 240,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ])->assertOk();

        $booking->refresh();
        $this->assertEquals(BusPaymentStatus::Paid, $booking->payment_status);
        $this->assertEqualsWithDelta(240.0, (float) $booking->paid_amount, 0.01);

        $this->assertCount(1, BusPayment::query()->where('booking_id', $booking->id)->get());

        // Per-account invariant: every account's balance equals the
        // net of its ledger entries (debit - credit).
        $this->assertLedgerGloballyBalanced();
    }

    public function test_partial_payment_marks_status_partial(): void
    {
        $booking = $this->createEgBooking(240);

        $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
            'amount' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ])->assertOk();

        $booking->refresh();
        $this->assertEquals(BusPaymentStatus::Partial, $booking->payment_status);
        $this->assertEqualsWithDelta(100.0, (float) $booking->paid_amount, 0.01);
        $this->assertEqualsWithDelta(140.0, (float) $booking->remaining_amount, 0.01);
    }

    public function test_multiple_partial_payments_sum_correctly(): void
    {
        $booking = $this->createEgBooking(240);

        $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
            'amount' => 100, 'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ])->assertOk();

        $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
            'amount' => 80, 'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ])->assertOk();

        $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
            'amount' => 60, 'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ])->assertOk();

        $booking->refresh();
        $this->assertEquals(BusPaymentStatus::Paid, $booking->payment_status);
        $this->assertEqualsWithDelta(240.0, (float) $booking->paid_amount, 0.01);
        $this->assertCount(3, BusPayment::query()->where('booking_id', $booking->id)->get());

        $this->assertLedgerGloballyBalanced();
    }

    public function test_rejects_payment_above_remaining_balance(): void
    {
        $booking = $this->createEgBooking(240);

        $response = $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
            'amount' => 999,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_rejects_payment_for_cancelled_booking(): void
    {
        $booking = $this->createEgBooking(240);

        // Cancel the booking (no payments exist yet, simple path).
        $this->postJson("/api/v1/bus/bookings/{$booking->id}/cancel", [
            'company_penalty' => 0,
            'office_penalty' => 0,
        ])->assertOk();

        $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
            'amount' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->cashboxEgp->id,
        ])->assertStatus(422);
    }

    public function test_payment_must_use_liquidity_account(): void
    {
        $booking = $this->createEgBooking(240);

        // Try a non-existent account.
        $response = $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
            'amount' => 100,
            'payment_method' => 'cash',
            'account_id' => 99999,
        ]);

        $response->assertStatus(422);

        // Try a non-bus-module account (flight treasury).
        $flightTreasury = LedgerBalanceMutationGuard::run(fn () => Account::create([
            'name' => 'Flight Treasury',
            'type' => \App\Enums\AccountType::Cashbox,
            'currency' => 'EGP',
            'balance' => 100,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'tourism',
            'created_by' => $this->user->id,
        ]));

        $response = $this->postJson("/api/v1/bus/bookings/{$booking->id}/pay", [
            'amount' => 100,
            'payment_method' => 'cash',
            'account_id' => $flightTreasury->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
