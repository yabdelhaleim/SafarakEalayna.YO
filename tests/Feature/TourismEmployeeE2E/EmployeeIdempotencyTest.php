<?php

namespace Tests\Feature\TourismEmployeeE2E;

use App\Models\Flight\FlightPayment;
use App\Models\HajjUmraPayment;
use App\Models\Program;
use App\Models\VisaPayment;

/**
 * Idempotency tests under the Employee role.
 *
 * Verifies the (booking_id, idempotency_key) UNIQUE constraint on payment
 * tables prevents duplicate postings when an employee retries the same call
 * (network blip, double-click, mobile retried after offline, etc.).
 */
class EmployeeIdempotencyTest extends EmployeeTestCase
{
    /* ============================================================
     *  HAJJ/UMRAH
     * ============================================================ */

    public function test_hajj_payment_idempotent_under_same_key(): void
    {
        $program = $this->createHajjProgram();
        $this->actAs($this->admin);
        $booking = $this->createHajjBooking($program);

        $this->actAs($this->normalEmployee);
        $key = 'EMP_AUDIT_HAJJ_IDEM_'.uniqid();

        $payload = [
            'amount' => 2000.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => $key,
        ];

        // First call — should succeed
        $r1 = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", $payload);
        $r1->assertStatus(201);

        $count1 = HajjUmraPayment::query()
            ->where('hajj_umra_booking_id', $booking->id)
            ->count();
        $this->assertSame(1, $count1, 'First payment must be inserted');

        // Replay with same key — must NOT create a duplicate
        $r2 = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", $payload);
        // 200 = idempotent return, 422 = rejected by unique constraint, 409 = conflict.
        // All three are acceptable; what matters is NO new payment row.
        $this->assertContains($r2->status(), [200, 201, 409, 422]);

        $count2 = HajjUmraPayment::query()
            ->where('hajj_umra_booking_id', $booking->id)
            ->count();
        $this->assertSame(
            $count1,
            $count2,
            'Replay with same idempotency_key must NOT insert a new payment row'
        );
    }

    public function test_hajj_payment_with_different_key_inserts_new_row(): void
    {
        $program = $this->createHajjProgram();
        $this->actAs($this->admin);
        $booking = $this->createHajjBooking($program);

        $this->actAs($this->normalEmployee);

        $payloadA = [
            'amount' => 1000.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'EMP_AUDIT_HAJJ_A_'.uniqid(),
        ];
        $payloadB = [
            'amount' => 1000.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'EMP_AUDIT_HAJJ_B_'.uniqid(),
        ];

        $r1 = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", $payloadA);
        $r1->assertStatus(201);

        $r2 = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", $payloadB);
        $r2->assertStatus(201);

        $count = HajjUmraPayment::query()
            ->where('hajj_umra_booking_id', $booking->id)
            ->count();
        $this->assertSame(2, $count, 'Different idempotency keys must insert distinct payment rows');
    }

    /* ============================================================
     *  VISA
     * ============================================================ */

    public function test_visa_payment_idempotent_under_same_key(): void
    {
        $this->actAs($this->admin);
        $booking = $this->createVisaBooking();

        $this->actAs($this->normalEmployee);
        $key = 'EMP_AUDIT_VISA_IDEM_'.uniqid();
        $payload = [
            'amount' => 500.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => $key,
        ];

        $r1 = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", $payload);
        $r1->assertStatus(201);

        $count1 = VisaPayment::query()
            ->where('visa_booking_id', $booking->id)
            ->count();
        $this->assertSame(1, $count1);

        // Replay — must not duplicate
        $r2 = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", $payload);
        $this->assertContains($r2->status(), [200, 201, 409, 422]);

        $count2 = VisaPayment::query()
            ->where('visa_booking_id', $booking->id)
            ->count();
        $this->assertSame($count1, $count2);
    }

    /* ============================================================
     *  FLIGHT
     * ============================================================ */

    public function test_flight_payment_idempotent_under_same_key(): void
    {
        [$system, $carrier] = $this->createFlightInfra();
        $this->actAs($this->admin);
        $booking = $this->createFlightBooking($carrier);

        $this->actAs($this->normalEmployee);
        $key = 'EMP_AUDIT_FLIGHT_IDEM_'.uniqid();
        $payload = [
            'amount' => 1500.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => $key,
        ];

        $r1 = $this->postJson("/api/v1/flight/bookings/{$booking->id}/payments", $payload);
        $r1->assertStatus(201);

        $count1 = FlightPayment::query()
            ->where('flight_booking_id', $booking->id)
            ->count();
        $this->assertSame(1, $count1);

        // Replay — must not duplicate
        $r2 = $this->postJson("/api/v1/flight/bookings/{$booking->id}/payments", $payload);
        $this->assertContains($r2->status(), [200, 201, 409, 422]);

        $count2 = FlightPayment::query()
            ->where('flight_booking_id', $booking->id)
            ->count();
        $this->assertSame($count1, $count2);
    }

    /* ============================================================
     *  HELPERS
     * ============================================================ */

    protected function createHajjBooking(Program $program): \App\Models\HajjUmraBooking
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'program_id' => $program->id,
            'selling_price' => 30000.0,
            'purchase_price' => 25000.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
            'agent_name' => 'EMP_AUDIT_20260817',
            'notes' => 'Idempotency audit booking',
        ];
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);

        return \App\Models\HajjUmraBooking::findOrFail($response->json('data.id'));
    }

    protected function createVisaBooking(): \App\Models\VisaBooking
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'purchase_price' => 1000.0,
            'selling_price' => 1500.0,
            'service_fee' => 100.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
            'visa_details' => [
                'visa_type' => 'tourist',
                'country' => 'EG',
            ],
        ];
        $response = $this->postJson('/api/v1/visa/bookings', $payload);

        return \App\Models\VisaBooking::findOrFail($response->json('data.id'));
    }

    protected function createFlightBooking(\App\Models\Flight\FlightCarrier $carrier): \App\Models\Flight\FlightBooking
    {
        $payload = $this->flightBookingPayload($carrier);
        $response = $this->postJson('/api/v1/flight/bookings', $payload);

        return \App\Models\Flight\FlightBooking::findOrFail($response->json('data.id'));
    }
}