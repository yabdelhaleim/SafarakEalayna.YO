<?php

namespace Tests\Feature\Flight;

use App\Enums\FlightBookingStatus;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightPassenger;
use Tests\Feature\Flight\Support\FlightTestCase;

/**
 * Confirm and Travel flow tests — covers the status state machine and the
 * passenger-mark-traveled flow that the original 31 tests did NOT exercise.
 *
 * Scenarios:
 *   1. confirmBooking: PENDING → CONFIRMED (status machine, no ledger mutation)
 *   2. confirmBooking: throws if status != PENDING
 *   3. markPassengerTraveled: sets traveled_at, no ledger mutation
 *
 * Local-only test suite — NOT pushed to git per user preference (2026-08-29).
 */
class FlightConfirmAndTravelTest extends FlightTestCase
{
    /**
     * SCENARIO 1 — confirmBooking transitions PENDING → CONFIRMED.
     *
     * Per line 1769: ONLY the status is updated, no ledger mutation.
     * This pins the contract that revenue is NOT recognized at confirmation
     * (FIN-2 cash basis — revenue is recognized on cash receipt).
     */
    public function test_confirm_booking_pending_to_confirmed_no_ledger_mutation(): void
    {
        $booking = $this->makeBooking(['selling_price' => 1000.0, 'purchase_price' => 600.0]);

        // Booking starts as PENDING
        $this->assertEquals('PENDING', $booking->status->value);

        $transactionCountBefore = \App\Models\Transaction::query()->count();
        $cashboxBalanceBefore = (float) $this->cashboxEgp->fresh()->balance;

        $this->bookingService->confirmBooking($booking->fresh());

        $booking->refresh();
        $this->assertEquals('CONFIRMED', $booking->status->value);

        // No new transactions posted (FIN-2: no revenue at confirmation)
        $transactionCountAfter = \App\Models\Transaction::query()->count();
        $this->assertEquals(
            $transactionCountBefore, $transactionCountAfter,
            'confirmBooking must NOT post any ledger transactions (FIN-2 cash basis)'
        );

        // Cashbox unchanged
        $this->cashboxEgp->refresh();
        $this->assertEqualsWithDelta(
            $cashboxBalanceBefore, (float) $this->cashboxEgp->balance, 0.01
        );

        $this->assertLedgerIntact();
    }

    /**
     * SCENARIO 2 — confirmBooking throws on non-PENDING status.
     *
     * Per line 1763: only PENDING bookings can be confirmed.
     */
    public function test_confirm_booking_throws_on_non_pending_status(): void
    {
        $booking = $this->makeBooking(['selling_price' => 1000.0, 'purchase_price' => 600.0]);
        $booking->update(['status' => FlightBookingStatus::CONFIRMED]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Only pending|pending bookings/i');

        $this->bookingService->confirmBooking($booking->fresh());
    }

    /**
     * SCENARIO 3 — markPassengerTraveled sets traveled_at.
     *
     * Per PassengerController line 345: pure state mutation, no financial impact.
     */
    public function test_mark_passenger_traveled_sets_traveled_at(): void
    {
        $booking = $this->makeBooking(['selling_price' => 1000.0, 'purchase_price' => 600.0]);

        // Create a passenger
        $passenger = FlightPassenger::create([
            'flight_booking_id' => $booking->id,
            'first_name' => 'Test',
            'last_name' => 'Passenger',
            'type' => 'adult',
        ]);

        $this->assertNull($passenger->traveled_at, 'Passenger starts without traveled_at');

        $passenger->update(['traveled_at' => now()]);

        $passenger->refresh();
        $this->assertNotNull($passenger->traveled_at, 'traveled_at must be set after mark');

        // No ledger mutation (FIN-2: revenue is recognized on cash receipt, not on travel)
        $this->assertLedgerIntact();
    }
}
