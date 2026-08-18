<?php

namespace Tests\Feature\TourismEmployeeE2E;

use App\Models\VisaBooking;

/**
 * Visa Employee E2E — exercises the full /api/v1/visa/* surface
 * as different employee personas.
 *
 * Per-route expected outcomes (from routes/api.php L584-619):
 *
 * | Operation                                | Employee | Restricted | Locked |
 * |------------------------------------------|----------|------------|--------|
 * | GET bookings / show                      | 200      | 200        | 200    |
 * | POST bookings (create)                   | 200/201  | 200/201    | 200/201|
 * | PUT bookings (update)                    | 200      | 200        | 200    |
 * | POST bookings/{id}/payments              | 200/201  | 200/201    | 200/201|
 * | DELETE bookings                          | 403      | 403        | 403    |
 * | POST bookings/{id}/cancel                | 403      | 403        | 403    |
 * | POST bookings/{id}/refund                | 403      | 200        | 200    |
 * | POST agents/{id}/withdraw                | 403      | 403        | 403    |
 * | POST agents/{id}/repay                   | 403      | 403        | 403    |
 * | POST customers/{id}/pay-debt             | 403      | 403        | 403    |
 * | GET treasury/overview                    | 200      | 200        | 200    |
 */
class EmployeeVisaE2ETest extends EmployeeTestCase
{
    /* ============================================================
     *  READ paths
     * ============================================================ */

    public function test_employee_can_list_bookings(): void
    {
        $this->actAs($this->admin);
        $booking = $this->createVisaBooking();

        $this->actAs($this->normalEmployee);
        $response = $this->getJson('/api/v1/visa/bookings');
        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));
    }

    public function test_employee_can_show_booking(): void
    {
        $this->actAs($this->admin);
        $booking = $this->createVisaBooking();

        $this->actAs($this->normalEmployee);
        $response = $this->getJson("/api/v1/visa/bookings/{$booking->id}");
        $response->assertStatus(200);
        $this->assertSame($booking->id, $response->json('data.id'));
    }

    /* ============================================================
     *  CREATE / UPDATE — employee allowed (correct)
     * ============================================================ */

    public function test_employee_can_create_booking(): void
    {
        $this->actAs($this->normalEmployee);

        $payload = $this->visaBookingPayload();
        $response = $this->postJson('/api/v1/visa/bookings', $payload);
        $response->assertStatus(201);
        $this->assertTrue($response->json('success'));
        $this->assertDatabaseHas('visa_bookings', [
            'customer_id' => $this->customer->id,
        ]);
    }

    public function test_restricted_employee_can_create_booking(): void
    {
        $this->actAs($this->restrictedEmployee);

        $payload = $this->visaBookingPayload();
        $response = $this->postJson('/api/v1/visa/bookings', $payload);
        $response->assertStatus(201);
    }

    public function test_employee_can_update_booking(): void
    {
        $this->actAs($this->admin);
        $booking = $this->createVisaBooking();

        $this->actAs($this->normalEmployee);
        $response = $this->putJson("/api/v1/visa/bookings/{$booking->id}", [
            'notes' => 'Updated by employee',
        ]);
        $response->assertStatus(200);
    }

    /* ============================================================
     *  PAYMENTS — employee allowed (correct)
     * ============================================================ */

    public function test_employee_can_record_payment(): void
    {
        $this->actAs($this->admin);
        $booking = $this->createVisaBooking();

        $this->actAs($this->normalEmployee);
        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'EMP_AUDIT_VISA_PAY_'.uniqid(),
        ]);
        $response->assertStatus(201);
        $this->assertDatabaseHas('visa_payments', [
            'visa_booking_id' => $booking->id,
            'amount' => 500.0,
        ]);
    }

    /* ============================================================
     *  ADMIN-ONLY — properly gated
     * ============================================================ */

    public function test_employee_cannot_cancel_booking(): void
    {
        $this->actAs($this->admin);
        $booking = $this->createVisaBooking();

        $this->actAs($this->normalEmployee);
        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'employee audit',
        ]);
        $response->assertStatus(403, 'Employee must NOT be able to cancel a visa booking (admin-only)');
    }

    public function test_restricted_employee_cannot_refund_booking_without_manage_refunds(): void
    {
        // restrictedEmployee deliberately lacks manage_refunds → must still get 403
        // (normal employees now CAN refund, see EmployeeRefundAuditTest for that path)
        $this->actAs($this->admin);
        $booking = $this->createVisaBooking();

        $this->actAs($this->restrictedEmployee);
        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'amount' => 100.0,
            'reason' => 'restricted employee audit',
        ]);
        $response->assertStatus(403, 'Restricted employee (no manage_refunds) must NOT be able to refund');
    }

    public function test_employee_cannot_delete_booking(): void
    {
        $this->actAs($this->admin);
        $booking = $this->createVisaBooking();

        $this->actAs($this->normalEmployee);
        $response = $this->deleteJson("/api/v1/visa/bookings/{$booking->id}");
        $response->assertStatus(403, 'Employee must NOT be able to delete a visa booking (admin-only)');
    }

    public function test_employee_cannot_withdraw_from_visa_agent(): void
    {
        $agent = $this->createVisaAgent();

        $this->actAs($this->normalEmployee);
        $response = $this->postJson("/api/v1/visa/agents/{$agent->id}/withdraw", [
            'amount' => 100.0,
            'account_id' => $this->vaultEgp->id,
        ]);
        $response->assertStatus(403, 'Employee must NOT be able to withdraw (admin-only)');
    }

    public function test_employee_cannot_repay_visa_agent(): void
    {
        $agent = $this->createVisaAgent();

        $this->actAs($this->normalEmployee);
        $response = $this->postJson("/api/v1/visa/agents/{$agent->id}/repay", [
            'amount' => 100.0,
            'account_id' => $this->vaultEgp->id,
        ]);
        $response->assertStatus(403, 'Employee must NOT be able to repay (admin-only)');
    }

    public function test_employee_cannot_pay_customer_visa_debt(): void
    {
        $this->actAs($this->normalEmployee);
        $response = $this->postJson("/api/v1/visa/customers/{$this->customer->id}/pay-debt", [
            'amount' => 100.0,
            'account_id' => $this->vaultEgp->id,
        ]);
        $response->assertStatus(403, 'Employee must NOT be able to pay visa customer debt (admin-only)');
    }

    /* ============================================================
     *  ADMIN HAPPY PATH (control)
     * ============================================================ */

    public function test_admin_can_cancel_visa_booking(): void
    {
        $this->actAs($this->admin);
        $booking = $this->createVisaBooking();

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'admin control',
        ]);
        $this->assertContains(
            $response->status(),
            [200, 201],
            'Admin must be able to cancel'
        );
    }

    public function test_employee_can_view_treasury_overview(): void
    {
        $this->actAs($this->normalEmployee);
        $response = $this->getJson('/api/v1/visa/treasury/overview');
        $response->assertStatus(200);
    }

    /* ============================================================
     *  HELPERS
     * ============================================================ */

    protected function visaBookingPayload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'purchase_price' => 1000.0,
            'selling_price' => 1500.0,
            'service_fee' => 100.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
            'agent_name' => 'EMP_AUDIT_20260817',
            'notes' => 'Employee audit booking',
            'visa_details' => [
                'visa_type' => 'tourist',
                'country' => 'EG',
                'duration' => '30 days',
            ],
        ], $overrides);
    }

    protected function createVisaBooking(): VisaBooking
    {
        $payload = $this->visaBookingPayload();
        $response = $this->postJson('/api/v1/visa/bookings', $payload);

        return VisaBooking::findOrFail($response->json('data.id'));
    }

    protected function createVisaAgent(): \App\Models\HajjUmra\VisaAgent
    {
        return \App\Models\HajjUmra\VisaAgent::query()->create([
            'company_name' => 'EMP_AUDIT_20260817_VisaAgent',
            'contact_person' => 'EMP Audit',
            'phone' => '01000000999',
            'email' => 'agent@audit.local',
            'country' => 'EG',
            'visa_type' => 'tourist',
            'account_id' => $this->vaultEgp->id,
            'is_active' => true,
        ]);
    }
}