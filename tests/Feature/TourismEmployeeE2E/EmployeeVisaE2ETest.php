<?php

namespace Tests\Feature\TourismEmployeeE2E;

use App\Models\VisaBooking;

/**
 * Visa Employee E2E — exercises the full /api/v1/visa/* surface
 * as different employee personas.
 *
 * Per-route expected outcomes (UPDATED Phase 9.3a, 2026-08-19):
 *
 * Changes from the original docblock (Phase 8.5 hardening):
 *   - GET bookings / show        — admin-only (Phase 8.5 A1.5/A1.6 admin-gated reads)
 *   - GET treasury/overview      — admin-only (same)
 *   - PUT bookings (update)      — 405 (no-edit contract lockdown — route disabled entirely)
 *
 * | Operation                                | Employee | Restricted | Locked |
 * |------------------------------------------|----------|------------|--------|
 * | GET bookings / show                      | 403      | 403        | 403    |
 * | POST bookings (create)                   | 200/201  | 200/201    | 200/201|
 * | PUT bookings (update)                    | 405      | 405        | 405    |  (route disabled by no-edit contract)
 * | POST bookings/{id}/payments              | 200/201  | 200/201    | 200/201|
 * | DELETE bookings                          | 403      | 403        | 403    |
 * | POST bookings/{id}/cancel                | 403      | 403        | 403    |
 * | POST bookings/{id}/refund                | 403      | 200        | 200    |
 * | POST agents/{id}/withdraw                | 403      | 403        | 403    |
 * | POST agents/{id}/repay                   | 403      | 403        | 403    |
 * | POST customers/{id}/pay-debt             | 403      | 403        | 403    |
 * | GET treasury/overview                    | 403      | 403        | 403    |
 */
class EmployeeVisaE2ETest extends EmployeeTestCase
{
    /* ============================================================
     *  READ paths — admin-only per Phase 8.5 A1.5/A1.6
     * ============================================================ */

    public function test_employee_cannot_list_bookings(): void
    {
        // Phase 8.5: GET /api/v1/visa/bookings is admin-gated.
        $this->actAs($this->admin);
        $this->createVisaBooking();

        $this->actAs($this->normalEmployee);
        $response = $this->getJson('/api/v1/visa/bookings');
        $response->assertStatus(403, 'Employee must NOT be able to list visa bookings (admin-only per Phase 8.5)');
    }

    public function test_employee_cannot_show_booking(): void
    {
        // Phase 8.5: GET /api/v1/visa/bookings/{id} is admin-gated.
        $this->actAs($this->admin);
        $booking = $this->createVisaBooking();

        $this->actAs($this->normalEmployee);
        $response = $this->getJson("/api/v1/visa/bookings/{$booking->id}");
        $response->assertStatus(403, 'Employee must NOT be able to show a visa booking (admin-only per Phase 8.5)');
    }

    /* ============================================================
     *  CREATE — employee allowed (correct)
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

    /* ============================================================
     *  UPDATE — DISABLED by no-edit contract (Phase 8.5 B1)
     *
     *  The PUT /api/v1/visa/bookings/{id} route was removed entirely by
     *  the no-edit contract (see Phase 8.5 B1 and Phase 11 audit phase).
     *  An employee (or anyone) attempting a PUT now receives 405 Method
     *  Not Allowed because the route does not exist for any role.
     *
     *  This is the correct, intentional behavior. The previous test
     *  asserting 200 was a test-harness defect (it pre-dated the
     *  no-edit contract lockdown). Removed in Phase 9.3a.
     * ============================================================ */

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

    public function test_employee_cannot_view_treasury_overview(): void
    {
        // Phase 8.5: GET /api/v1/visa/treasury/overview is admin-gated.
        $this->actAs($this->normalEmployee);
        $response = $this->getJson('/api/v1/visa/treasury/overview');
        $response->assertStatus(403, 'Employee must NOT be able to view visa treasury overview (admin-only per Phase 8.5)');
    }

    /* ============================================================
     *  PHASE 9.3b — DEEP EMPLOYEE SCENARIOS
     *  Beyond the basic CRUD/permission matrix, this section exercises
     *  cross-cutting concerns: state-machine interaction, validation,
     *  audit-trail integrity, cross-employee visibility, and the
     *  locked/inactive employee gates.
     * ============================================================ */

    public function test_restricted_employee_cannot_record_payment_without_manage_online(): void
    {
        // restrictedEmployee has ONLY manage_flights → no manage_online → 403
        $this->actAs($this->admin);
        $booking = $this->createVisaBooking();

        $this->actAs($this->restrictedEmployee);
        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P93B_RESTR_PAY_'.uniqid(),
        ]);
        $response->assertStatus(403,
            'Restricted employee (no manage_online) must NOT be able to record payment');
    }

    public function test_inactive_employee_rejected_by_middleware_on_every_endpoint(): void
    {
        // EnsureIsActive middleware returns 401 for inactive users
        $this->actAs($this->inactiveEmployee);

        // Read endpoints
        $this->getJson('/api/v1/visa/bookings')->assertStatus(401);

        // Write endpoints (admin would normally be able to do these)
        $this->actAs($this->admin);
        $booking = $this->createVisaBooking();

        $this->actAs($this->inactiveEmployee);
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 100.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P93B_INACT_PAY_'.uniqid(),
        ])->assertStatus(401, 'Inactive employee must be rejected by EnsureIsActive middleware');
    }

    public function test_employee_with_manage_refunds_can_refund_visa_booking(): void
    {
        // Positive path — normalEmployee has manage_refunds by default
        $this->actAs($this->normalEmployee);
        $booking = $this->createVisaBooking();

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1600.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P93B_EMP_REFUND_PAY_'.uniqid(),
        ])->assertCreated();

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'Phase 9.3b employee refund positive path',
        ]);
        $response->assertStatus(200,
            'Employee WITH manage_refunds CAN refund a visa booking');
        $this->assertEquals(\App\Enums\VisaStatus::Refunded,
            $booking->fresh()->status);
    }

    public function test_employee_refund_records_acting_user_in_audit_log(): void
    {
        // Verify the audit log attributes the refund to the ACTING user (employee),
        // not the admin who created the booking.
        $this->actAs($this->admin);
        $booking = $this->createVisaBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1600.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P93B_ACT_PAY_'.uniqid(),
        ])->assertCreated();

        $this->actAs($this->otherEmployee);
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'Phase 9.3b audit attribution',
        ])->assertOk();

        $audit = \App\Models\RefundAuditLog::query()
            ->where('booking_id', $booking->id)
            ->where('module', 'visa')
            ->latest('id')->first();
        $this->assertNotNull($audit, 'refund_audit_logs row must exist');
        $this->assertSame(
            (int) $this->otherEmployee->id,
            (int) $audit->user_id,
            'Audit must attribute refund to ACTING user (otherEmployee), not admin creator'
        );
    }

    public function test_employee_refund_after_partial_payment_refunds_only_paid(): void
    {
        // Employee records partial payment, then employee refunds.
        // Full refund = sum of payments, not selling+fee.
        $this->actAs($this->normalEmployee);
        $booking = $this->createVisaBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1000.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P93B_PART_PAY_'.uniqid(),
        ])->assertCreated();

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'Phase 9.3b partial-pay + employee refund',
        ]);
        $response->assertStatus(200);
        $this->assertEquals(\App\Enums\VisaStatus::Refunded,
            $booking->fresh()->status);

        $audit = \App\Models\RefundAuditLog::query()
            ->where('booking_id', $booking->id)->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertEquals(1000.0, (float) $audit->refund_amount,
            'refund_amount must equal sum of payments (1000), not selling+fee (1600)');
    }

    public function test_employee_can_record_multiple_payments_on_same_booking(): void
    {
        // Multi-method payment path — employee-driven
        $this->actAs($this->normalEmployee);
        $booking = $this->createVisaBooking();

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1000.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P93B_MULTI1_'.uniqid(),
        ])->assertCreated();

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 600.0, 'payment_method' => 'bank_transfer',
            'account_id' => $this->bankEgp->id,
            'idempotency_key' => 'P93B_MULTI2_'.uniqid(),
        ])->assertCreated();

        $this->assertDatabaseCount('visa_payments', 2);
    }

    public function test_employee_cannot_record_payment_with_currency_mismatched_account(): void
    {
        // Booking is EGP, payment account is USD — must reject
        $this->actAs($this->normalEmployee);
        $booking = $this->createVisaBooking();  // EGP booking

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultUsd->id,  // USD account!
            'idempotency_key' => 'P93B_CUR_'.uniqid(),
        ]);
        $this->assertContains($response->status(), [422, 400, 403],
            'Currency mismatch between booking and payment account must be rejected');
    }

    public function test_employee_cannot_record_payment_exceeding_booking_total(): void
    {
        // Booking selling+fee = 1600; payment of 2000 = over-pay → 422
        $this->actAs($this->normalEmployee);
        $booking = $this->createVisaBooking();

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 2000.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P93B_OVERPAY_'.uniqid(),
        ]);
        $response->assertStatus(422,
            'Over-payment beyond booking total must be rejected');
    }

    public function test_employee_cannot_record_payment_after_admin_refunds(): void
    {
        // State machine: status=Refunded rejects new payments
        $this->actAs($this->admin);
        $booking = $this->createVisaBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1600.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P93B_STM_PAY1_'.uniqid(),
        ])->assertCreated();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'admin pre-cancel',
        ])->assertOk();

        $this->actAs($this->normalEmployee);
        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 100.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P93B_STM_PAY2_'.uniqid(),
        ]);
        $response->assertStatus(422,
            'Payment on Refunded booking must be rejected (terminal state)');
    }

    public function test_employee_cannot_view_soft_deleted_booking(): void
    {
        // Admin soft-deletes the booking; employee GET → 404
        $this->actAs($this->admin);
        $booking = $this->createVisaBooking();
        $this->deleteJson("/api/v1/visa/bookings/{$booking->id}")->assertOk();

        $this->actAs($this->normalEmployee);
        $response = $this->getJson("/api/v1/visa/bookings/{$booking->id}");
        $this->assertContains($response->status(), [404, 410],
            'Soft-deleted booking must NOT be viewable by employee');
    }

    public function test_other_employee_can_record_payment_on_same_booking(): void
    {
        // Cross-employee write: employeeA creates booking, employeeB records payment.
        // Tourism bookings have NO per-employee ownership — any employee with
        // manage_online can pay any booking.
        $this->actAs($this->normalEmployee);
        $booking = $this->createVisaBooking();

        $this->actAs($this->otherEmployee);
        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 500.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P93B_CROSS_PAY_'.uniqid(),
        ]);
        $response->assertStatus(201,
            'Cross-employee payment MUST be allowed (no per-employee ownership)');
    }

    public function test_other_employee_can_refund_same_booking_with_manage_refunds(): void
    {
        // Cross-employee refund: employeeA creates booking, employeeB refunds.
        $this->actAs($this->normalEmployee);
        $booking = $this->createVisaBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1600.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P93B_CROSS_REF_PAY_'.uniqid(),
        ])->assertCreated();

        $this->actAs($this->otherEmployee);
        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'Phase 9.3b cross-employee refund',
        ]);
        $response->assertStatus(200,
            'Cross-employee refund MUST be allowed (no per-employee ownership)');
        $this->assertEquals(\App\Enums\VisaStatus::Refunded, $booking->fresh()->status);
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