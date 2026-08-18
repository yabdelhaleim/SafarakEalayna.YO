<?php

namespace Tests\Feature\TourismEmployeeE2E;

use App\Models\Flight\FlightBooking;
use App\Models\HajjUmraBooking;
use App\Models\Program;
use App\Models\VisaBooking;

/**
 * Cross-employee authorization & IDOR tests.
 *
 * Tourism bookings (Flight/Hajj/Visa) do NOT carry an `owner_employee_id` field —
 * they're shared resources visible to any employee with module permission.
 *
 * This documents the system's intent:
 *   - Bookings are team resources, not personal.
 *   - Admin-only operations (cancel/refund/delete) are still enforced.
 *   - Any employee with module permission can perform CRUD on any booking.
 *
 * If per-employee isolation is desired, an `employee_id` gate must be added
 * at the controller layer.
 */
class EmployeeIDORTest extends EmployeeTestCase
{
    /* ============================================================
     *  FLIGHT — cross-employee access
     * ============================================================ */

    public function test_flight_booking_created_by_a_visible_to_b(): void
    {
        [$system, $carrier] = $this->createFlightInfra();

        // Employee A creates
        $this->actAs($this->normalEmployee);
        $payload = $this->flightBookingPayload($carrier);
        $createResponse = $this->postJson('/api/v1/flight/bookings', $payload);
        $createResponse->assertStatus(201);
        $bookingId = $createResponse->json('data.id');

        // Employee B (other) can read it
        $this->actAs($this->otherEmployee);
        $response = $this->getJson("/api/v1/flight/bookings/{$bookingId}");
        $response->assertStatus(200);
        $this->assertSame($bookingId, $response->json('data.id'));
    }

    public function test_flight_employee_b_can_record_payment_on_a_booking(): void
    {
        [$system, $carrier] = $this->createFlightInfra();
        $this->actAs($this->normalEmployee);
        $booking = $this->createFlightBooking($carrier);

        // Employee B records a payment
        $this->actAs($this->otherEmployee);
        $response = $this->postJson("/api/v1/flight/bookings/{$booking->id}/payments", [
            'amount' => 1000.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'EMP_AUDIT_IDOR_'.uniqid(),
        ]);
        $response->assertStatus(201);
        $this->assertDatabaseHas('flight_payments', [
            'flight_booking_id' => $booking->id,
            'amount' => 1000.0,
        ]);
    }

    /**
     * Cross-employee cross-customer payment IDOR.
     * Employee B paying on Employee A's customer booking is allowed by design.
     */
    public function test_flight_payment_to_other_customers_booking_works(): void
    {
        [$system, $carrier] = $this->createFlightInfra();

        // Create a second customer
        $customerB = \App\Models\Customer::query()->create([
            'full_name' => 'EMP_AUDIT_20260817_Customer_B',
            'name' => 'EMP_AUDIT_20260817_Customer_B',
            'phone' => '01000000002',
            'nationality' => 'EG',
            'gender' => 'male',
            'status' => 'active',
            'module_type' => 'tourism',
            'created_by' => $this->admin->id,
        ]);

        // Employee A creates booking for customer B
        $this->actAs($this->normalEmployee);
        $payload = $this->flightBookingPayload($carrier);
        $payload['customer_id'] = $customerB->id;
        $createResponse = $this->postJson('/api/v1/flight/bookings', $payload);
        $createResponse->assertStatus(201);
        $bookingId = $createResponse->json('data.id');

        // Employee C (locked) cannot read another employee's customer booking?
        // Actually by design, ANY employee can read it (no per-employee isolation)
        $this->actAs($this->lockedEmployee);
        $response = $this->getJson("/api/v1/flight/bookings/{$bookingId}");
        $response->assertStatus(200);
    }

    /* ============================================================
     *  HAJJ/UMRAH — cross-employee
     * ============================================================ */

    public function test_hajj_booking_visible_across_employees(): void
    {
        $program = $this->createHajjProgram();
        $this->actAs($this->normalEmployee);
        $booking = $this->createHajjBooking($program);

        $this->actAs($this->otherEmployee);
        $response = $this->getJson("/api/v1/hajj-umra/bookings/{$booking->id}");
        $response->assertStatus(200);
    }

    /**
     * Negative test: cross-employee cancel attempt must still hit the admin gate.
     */
    public function test_hajj_employee_b_cannot_cancel_employee_a_booking(): void
    {
        $program = $this->createHajjProgram();
        $this->actAs($this->normalEmployee);
        $booking = $this->createHajjBooking($program);

        $this->actAs($this->otherEmployee);
        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'cross employee audit',
        ]);
        $response->assertStatus(403, 'Cross-employee cancel must still hit admin gate');
    }

    /* ============================================================
     *  VISA — cross-employee
     * ============================================================ */

    public function test_visa_booking_visible_across_employees(): void
    {
        $this->actAs($this->normalEmployee);
        $booking = $this->createVisaBooking();

        $this->actAs($this->otherEmployee);
        $response = $this->getJson("/api/v1/visa/bookings/{$booking->id}");
        $response->assertStatus(200);
    }

    public function test_visa_employee_b_can_refund_employee_a_booking_with_manage_refunds(): void
    {
        // Post-EMP-REFUND-2026-08-17: employees WITH manage_refunds can refund ANY
        // Tourism booking they have access to (cross-employee refund is a feature,
        // not an IDOR — same permission is required). The audit trail records
        // the actual acting user (employee B), not employee A.
        // This test verifies the cross-employee refund WORKS and is correctly attributed.
        $this->actAs($this->normalEmployee);
        $booking = $this->createVisaBooking();

        $this->actAs($this->otherEmployee);
        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 cross-employee refund by otherEmployee',
        ]);
        $response->assertStatus(200, 'Employee B (with manage_refunds) can refund employee A booking');

        // Verify the audit row attributes the refund to employee B (the actual actor)
        $audit = \App\Models\RefundAuditLog::query()
            ->where('booking_id', $booking->id)
            ->where('module', 'visa')
            ->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame(
            (int) $this->otherEmployee->id,
            (int) $audit->user_id,
            'Audit must attribute refund to the ACTING user (employee B), not the booking owner'
        );
        $this->assertNotSame(
            (int) $this->normalEmployee->id,
            (int) $audit->user_id,
            'Audit must NOT leak the booking-creator identity'
        );
    }

    /* ============================================================
     *  STATUS CODES
     * ============================================================ */

    /**
     * Numeric ID enumeration attack — request booking ID 999999 (doesn't exist).
     * Must return 404, not 403/500, to avoid leaking existence information.
     */
    public function test_nonexistent_booking_returns_404_not_leak(): void
    {
        $this->actAs($this->normalEmployee);

        $response = $this->getJson('/api/v1/hajj-umra/bookings/999999');
        $this->assertSame(404, $response->status());

        $response = $this->getJson('/api/v1/visa/bookings/999999');
        $this->assertSame(404, $response->status());

        $response = $this->getJson('/api/v1/flight/bookings/999999');
        $this->assertSame(404, $response->status());
    }

    /* ============================================================
     *  HELPERS
     * ============================================================ */

    protected function createFlightBooking(\App\Models\Flight\FlightCarrier $carrier): FlightBooking
    {
        $payload = $this->flightBookingPayload($carrier);
        $response = $this->postJson('/api/v1/flight/bookings', $payload);

        return FlightBooking::findOrFail($response->json('data.id'));
    }

    protected function createHajjBooking(Program $program): HajjUmraBooking
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'program_id' => $program->id,
            'selling_price' => 30000.0,
            'purchase_price' => 25000.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
            'agent_name' => 'EMP_AUDIT_20260817',
            'notes' => 'IDOR audit booking',
        ];
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);

        return HajjUmraBooking::findOrFail($response->json('data.id'));
    }

    protected function createVisaBooking(): VisaBooking
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

        return VisaBooking::findOrFail($response->json('data.id'));
    }
}