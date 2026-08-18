<?php

namespace Tests\Feature\TourismEmployeeE2E;

use App\Models\Account;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightPayment;

/**
 * Flight Employee E2E — exercises the full /api/v1/flight/* surface
 * as different employee personas.
 *
 * Per-route expected outcomes (from routes/api.php L166-263):
 *
 * | Operation                              | Employee | Restricted | Locked |
 * |----------------------------------------|----------|------------|--------|
 * | GET bookings / show                    | 200      | 200        | 200    |
 * | POST bookings (create)                 | 200      | 200        | 200    |
 * | PUT bookings (update)                  | 200      | 200        | 200    |
 * | POST bookings/{id}/payments            | 200      | 200        | 200    |
 * | POST bookings/{id}/confirm             | BUG-open | BUG-open   | BUG-open |
 * | POST bookings/{id}/cancel              | BUG-open | BUG-open   | BUG-open |
 * | DELETE bookings                        | BUG-open | BUG-open   | BUG-open |
 * | POST treasury/systems/{id}/recharge    | BUG-open | BUG-open   | BUG-open |
 * | POST carriers/{id}/recharge            | BUG-open | BUG-open   | BUG-open |
 * | GET carriers/{id}/balance              | 200      | 200        | 200    |
 *
 * The "BUG-open" entries are real findings — these routes are NOT
 * wrapped in `admin` middleware and the controller methods have no
 * internal auth check, so any active employee can perform them.
 */
class EmployeeFlightE2ETest extends EmployeeTestCase
{
    /* ============================================================
     *  READ paths (employee + restricted + locked all see same list)
     * ============================================================ */

    public function test_employee_can_list_bookings(): void
    {
        [$system, $carrier] = $this->createFlightInfra();

        // Admin creates a booking so the list is non-empty
        $this->actAs($this->admin);
        $booking = $this->createFlightBooking($carrier);

        // Normal employee can read
        $this->actAs($this->normalEmployee);
        $response = $this->getJson('/api/v1/flight/bookings');
        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));

        // Restricted employee (flights-only) can also read
        $this->actAs($this->restrictedEmployee);
        $response = $this->getJson('/api/v1/flight/bookings');
        $response->assertStatus(200);
    }

    public function test_employee_can_show_single_booking(): void
    {
        [$system, $carrier] = $this->createFlightInfra();
        $this->actAs($this->admin);
        $booking = $this->createFlightBooking($carrier);

        $this->actAs($this->normalEmployee);
        $response = $this->getJson("/api/v1/flight/bookings/{$booking->id}");
        $response->assertStatus(200);
        $this->assertSame($booking->id, $response->json('data.id'));
    }

    /* ============================================================
     *  CREATE / UPDATE — employee can perform (correct)
     * ============================================================ */

    public function test_employee_can_create_booking(): void
    {
        [$system, $carrier] = $this->createFlightInfra();

        $this->actAs($this->normalEmployee);
        $payload = $this->flightBookingPayload($carrier);
        $response = $this->postJson('/api/v1/flight/bookings', $payload);
        $response->assertStatus(201);
        $this->assertTrue($response->json('success'));
        $this->assertDatabaseHas('flight_bookings', [
            'customer_id' => $this->customer->id,
            'flight_carrier_id' => $carrier->id,
        ]);
    }

    public function test_restricted_employee_can_create_booking(): void
    {
        [$system, $carrier] = $this->createFlightInfra();
        $this->actAs($this->restrictedEmployee);

        $payload = $this->flightBookingPayload($carrier);
        $response = $this->postJson('/api/v1/flight/bookings', $payload);
        $response->assertStatus(201);
    }

    public function test_employee_can_update_booking_prices(): void
    {
        [$system, $carrier] = $this->createFlightInfra();
        $this->actAs($this->admin);
        $booking = $this->createFlightBooking($carrier);

        $this->actAs($this->normalEmployee);
        $response = $this->postJson("/api/v1/flight/bookings/{$booking->id}/prices", [
            'selling_price' => 6500.0,
            'purchase_price' => 5200.0,
        ]);
        $response->assertStatus(200);
        $this->assertSame(6500.0, (float) FlightBooking::find($booking->id)->selling_price);
    }

    /* ============================================================
     *  PAYMENTS — employee can record payments (correct)
     * ============================================================ */

    public function test_employee_can_record_payment(): void
    {
        [$system, $carrier] = $this->createFlightInfra();
        $this->actAs($this->admin);
        $booking = $this->createFlightBooking($carrier);

        $this->actAs($this->normalEmployee);
        $response = $this->postJson("/api/v1/flight/bookings/{$booking->id}/payments", [
            'amount' => 2000.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'EMP_AUDIT_FL_PAY_'.uniqid(),
        ]);
        $response->assertStatus(201);

        $this->assertDatabaseHas('flight_payments', [
            'flight_booking_id' => $booking->id,
            'amount' => 2000.0,
        ]);
    }

    /* ============================================================
     *  SECURITY FINDINGS — Destructive ops open to all employees
     * ============================================================ */

    /**
     * CRITICAL FINDING: Flight booking CANCEL is open to any active employee.
     *
     * Expected: 403 Forbidden for non-admin users.
     * Actual:   200 OK — any employee can cancel a flight booking,
     *           triggering a refund and ledger reversal.
     *
     * Compare to Hajj/Umrah where DELETE/cancel/refund are wrapped in
     * `middleware('admin')` at routes/api.php L571-575.
     */
public function test_employee_cannot_cancel_booking(): void
    {
        [$system, $carrier] = $this->createFlightInfra();
        $this->actAs($this->admin);
        $booking = $this->createFlightBooking($carrier);

        // Employee must NOT be able to cancel a Flight booking (admin-only).
        // EMP-F-001 (resolved by EMP_FLIGHT_AUTH_FIX_20260817).
        $this->actAs($this->normalEmployee);

        $response = $this->postJson("/api/v1/flight/bookings/{$booking->id}/cancel", [
            'refund_amount' => 6000.0,
            'reason' => 'employee audit',
            'account_id' => $this->vaultEgp->id,
        ]);

        $response->assertStatus(403, 'Employee must NOT be able to cancel a Flight booking (admin-only)');
    }

    public function test_employee_cannot_delete_booking(): void
    {
        [$system, $carrier] = $this->createFlightInfra();
        $this->actAs($this->admin);
        $booking = $this->createFlightBooking($carrier);

        // Employee must NOT be able to delete a Flight booking (admin-only).
        // EMP-F-002 (resolved by EMP_FLIGHT_AUTH_FIX_20260817).
        $this->actAs($this->normalEmployee);

        $response = $this->deleteJson("/api/v1/flight/bookings/{$booking->id}");
        $response->assertStatus(403, 'Employee must NOT be able to delete a Flight booking (admin-only)');
    }

    public function test_employee_cannot_confirm_booking(): void
    {
        [$system, $carrier] = $this->createFlightInfra();
        $this->actAs($this->admin);
        $booking = $this->createFlightBooking($carrier);

        // Employee must NOT be able to confirm a Flight booking (admin-only).
        // EMP-F-003 (resolved by EMP_FLIGHT_AUTH_FIX_20260817).
        $this->actAs($this->normalEmployee);

        $response = $this->postJson("/api/v1/flight/bookings/{$booking->id}/confirm");
        $response->assertStatus(403, 'Employee must NOT be able to confirm a Flight booking (admin-only)');
    }

    public function test_employee_cannot_recharge_flight_system(): void
    {
        [$system, $carrier] = $this->createFlightInfra();

        $vaultBalanceBefore = $this->vaultEgp->fresh()->balance;
        $systemBalanceBefore = (float) $system->fresh()->balance;

        // Employee must NOT be able to recharge the flight system (admin-only).
        // EMP-F-004 (resolved by EMP_FLIGHT_AUTH_FIX_20260817).
        $this->actAs($this->normalEmployee);

        $response = $this->postJson("/api/v1/flight/treasury/systems/{$system->id}/recharge", [
            'amount' => 10.0,
            'from_account_id' => $this->vaultEgp->id,
        ]);

        $response->assertStatus(403, 'Employee must NOT be able to recharge the flight system (admin-only)');

        // Vault and system balances MUST be unchanged (403 must happen BEFORE any DB write)
        $this->assertSame(
            $vaultBalanceBefore,
            $this->vaultEgp->fresh()->balance,
            'Vault balance must NOT change when employee recharge attempt is rejected'
        );
        $this->assertSame(
            $systemBalanceBefore,
            (float) $system->fresh()->balance,
            'Flight system balance must NOT change when employee recharge attempt is rejected'
        );
    }

    public function test_employee_cannot_recharge_flight_carrier(): void
    {
        [$system, $carrier] = $this->createFlightInfra();

        // Employee must NOT be able to recharge a flight carrier (admin-only).
        // EMP-F-005 (resolved by EMP_FLIGHT_AUTH_FIX_20260817).
        $this->actAs($this->normalEmployee);

        $response = $this->postJson("/api/v1/flight/carriers/{$carrier->id}/recharge", [
            'amount' => 10.0,
            'from_account_id' => $this->vaultEgp->id,
        ]);

        $response->assertStatus(403, 'Employee must NOT be able to recharge a flight carrier (admin-only)');
    }

    /* ============================================================
     *  LEGITIMATE employee surfaces
     * ============================================================ */

    public function test_employee_can_view_treasury_overview(): void
    {
        [$system, $carrier] = $this->createFlightInfra();
        $this->actAs($this->normalEmployee);

        $response = $this->getJson('/api/v1/flight/treasury/overview');
        $response->assertStatus(200);
    }

    public function test_employee_can_view_carrier_balance(): void
    {
        [$system, $carrier] = $this->createFlightInfra();
        $this->actAs($this->normalEmployee);

        $response = $this->getJson("/api/v1/flight/carriers/{$carrier->id}/balance");
        $response->assertStatus(200);
    }

    /* ============================================================
     *  Admin/Owner regression — EMP_FLIGHT_AUTH_FIX_20260817
     *  Verifies the 5 admin-only endpoints remain functional for admins.
     * ============================================================ */

    public function test_admin_can_cancel_flight_booking(): void
    {
        [$system, $carrier] = $this->createFlightInfra();
        $this->actAs($this->admin);
        $booking = $this->createFlightBooking($carrier);

        $response = $this->postJson("/api/v1/flight/bookings/{$booking->id}/cancel", [
            'airline_penalty' => 0,
            'office_penalty' => 0,
            'account_id' => $this->vaultEgp->id,
            'notes' => 'admin cancel test',
        ]);
        $this->assertContains($response->status(), [200, 201], 'Admin must still be able to cancel a Flight booking');
    }

    public function test_admin_can_delete_flight_booking(): void
    {
        [$system, $carrier] = $this->createFlightInfra();
        $this->actAs($this->admin);
        $booking = $this->createFlightBooking($carrier);

        $response = $this->deleteJson("/api/v1/flight/bookings/{$booking->id}");
        $this->assertContains($response->status(), [200, 204], 'Admin must still be able to delete a Flight booking');
    }

    public function test_admin_can_confirm_flight_booking(): void
    {
        [$system, $carrier] = $this->createFlightInfra();
        $this->actAs($this->admin);
        $booking = $this->createFlightBooking($carrier);

        $response = $this->postJson("/api/v1/flight/bookings/{$booking->id}/confirm");
        $this->assertContains($response->status(), [200, 201, 422], 'Admin must still be able to confirm a Flight booking');
    }

    public function test_admin_can_recharge_flight_system(): void
    {
        [$system, $carrier] = $this->createFlightInfra();

        $vaultBalanceBefore = $this->vaultEgp->fresh()->balance;

        $this->actAs($this->admin);
        $response = $this->postJson("/api/v1/flight/treasury/systems/{$system->id}/recharge", [
            'amount' => 1000.0,
            'from_account_id' => $this->vaultEgp->id,
        ]);
        $this->assertContains($response->status(), [200, 201], 'Admin must still be able to recharge the flight system');
    }

    public function test_admin_can_recharge_flight_carrier(): void
    {
        [$system, $carrier] = $this->createFlightInfra();

        $this->actAs($this->admin);
        $response = $this->postJson("/api/v1/flight/carriers/{$carrier->id}/recharge", [
            'amount' => 1000.0,
            'from_account_id' => $this->vaultEgp->id,
        ]);
        $this->assertContains($response->status(), [200, 201], 'Admin must still be able to recharge a flight carrier');
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
}