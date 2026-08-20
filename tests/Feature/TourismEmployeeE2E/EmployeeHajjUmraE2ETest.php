<?php

namespace Tests\Feature\TourismEmployeeE2E;

use App\Models\HajjUmraBooking;
use App\Models\Program;

/**
 * Hajj/Umrah Employee E2E — exercises the full /api/v1/hajj-umra/* surface
 * as different employee personas.
 *
 * Per-route expected outcomes (from routes/api.php L534-582):
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
 * | GET programs / show / create / update    | 200/201  | 200/201    | 200/201|
 * | DELETE programs                          | 403      | 403        | 403    |
 * | GET treasury/overview                    | 200      | 200        | 200    |
 * | POST executing-companies/{id}/withdraw   | 403      | 403        | 403    |
 * | POST executing-companies/{id}/repay      | 403      | 403        | 403    |
 */
class EmployeeHajjUmraE2ETest extends EmployeeTestCase
{
    /* ============================================================
     *  READ paths
     * ============================================================ */

    public function test_employee_can_list_bookings(): void
    {
        $program = $this->createHajjProgram();
        $this->actAs($this->admin);
        $booking = $this->createHajjBooking($program);

        $this->actAs($this->normalEmployee);
        $response = $this->getJson('/api/v1/hajj-umra/bookings');
        $response->assertStatus(200);
        $this->assertTrue($response->json('success'));
    }

    public function test_employee_can_show_booking(): void
    {
        $program = $this->createHajjProgram();
        $this->actAs($this->admin);
        $booking = $this->createHajjBooking($program);

        $this->actAs($this->normalEmployee);
        $response = $this->getJson("/api/v1/hajj-umra/bookings/{$booking->id}");
        $response->assertStatus(200);
        $this->assertSame($booking->id, $response->json('data.id'));
    }

    public function test_employee_can_list_programs(): void
    {
        $program = $this->createHajjProgram();
        $this->actAs($this->normalEmployee);

        $response = $this->getJson('/api/v1/hajj-umra/programs');
        $response->assertStatus(200);
    }

    public function test_employee_cannot_create_program(): void
    {
        // Note: Hajj-Umra programs are managed via the Filament admin panel which
        // is admin-only by business rule. Phase 8.5 A1.5 gates POST /programs
        // with `middleware('admin')` → non-admin employees must get 403.
        $this->actAs($this->normalEmployee);
        $payload = [
            'program_name' => 'EMP_AUDIT_20260817_Hajj_Program',
            'program_type' => 'hajj',
            'total_nights' => 14,
            'airline' => 'Saudi Airlines',
            'departure_point' => 'Cairo',
            'executing_company' => 'EMP_AUDIT_20260817_Executing',
            'mecca_hotel_name' => 'EMP_AUDIT_20260817_Mekka_Hotel',
            'mecca_nights' => 7,
            'medina_hotel_name' => 'EMP_AUDIT_20260817_Medina_Hotel',
            'medina_nights' => 7,
            'departure_date' => now()->addDays(30)->toDateString(),
            'return_date' => now()->addDays(45)->toDateString(),
            'default_purchase_price' => 25000.0,
            'default_selling_price' => 30000.0,
            'is_active' => true,
        ];
        $response = $this->postJson('/api/v1/hajj-umra/programs', $payload);
        $response->assertStatus(403, 'Employee must NOT be able to create a Hajj program (Filament admin panel is admin-only)');
    }

    /* ============================================================
     *  CREATE / UPDATE — employee allowed (correct)
     * ============================================================ */

    public function test_employee_can_create_booking(): void
    {
        $program = $this->createHajjProgram();
        $this->actAs($this->normalEmployee);

        $payload = $this->hajjBookingPayload($program);
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);
        $response->assertStatus(201);
        $this->assertTrue($response->json('success'));
        $this->assertDatabaseHas('hajj_umra_bookings', [
            'customer_id' => $this->customer->id,
            'program_id' => $program->id,
        ]);
    }

    public function test_restricted_employee_can_create_booking(): void
    {
        $program = $this->createHajjProgram();
        $this->actAs($this->restrictedEmployee);

        $payload = $this->hajjBookingPayload($program);
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);
        $response->assertStatus(201);
    }

    public function test_employee_cannot_update_booking_via_put(): void
    {
        // Phase 10.1 / Phase 8.5 No-Edit Contract — Tourism PUT/PATCH was removed
        // from hajj-umra (and visa) bookings. The OLD test asserted 200; the
        // NEW contract returns 405 Method Not Allowed. Cancellation is the
        // supported correction path.
        $program = $this->createHajjProgram();
        $this->actAs($this->admin);
        $booking = $this->createHajjBooking($program);

        $this->actAs($this->normalEmployee);
        $response = $this->putJson("/api/v1/hajj-umra/bookings/{$booking->id}", [
            'notes' => 'Updated by normal employee',
        ]);
        $response->assertStatus(405);
    }

    /* ============================================================
     *  PAYMENTS — employee allowed (correct)
     * ============================================================ */

    public function test_employee_can_record_payment(): void
    {
        $program = $this->createHajjProgram();
        $this->actAs($this->admin);
        $booking = $this->createHajjBooking($program);

        $this->actAs($this->normalEmployee);
        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 5000.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'EMP_AUDIT_HAJJ_PAY_'.uniqid(),
        ]);
        $response->assertStatus(201);
        $this->assertDatabaseHas('hajj_umra_payments', [
            'hajj_umra_booking_id' => $booking->id,
            'amount' => 5000.0,
        ]);
    }

    /* ============================================================
     *  ADMIN-ONLY — properly gated (Hajj/Umrah is correct here)
     * ============================================================ */

    public function test_employee_cannot_cancel_booking(): void
    {
        $program = $this->createHajjProgram();
        $this->actAs($this->admin);
        $booking = $this->createHajjBooking($program);

        $this->actAs($this->normalEmployee);
        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'employee audit',
        ]);
        $response->assertStatus(403, 'Employee must NOT be able to cancel a Hajj booking (admin-only)');
    }

    public function test_restricted_employee_cannot_refund_booking_without_manage_refunds(): void
    {
        // restrictedEmployee deliberately lacks manage_refunds → must still get 403
        // (normal employees now CAN refund, see EmployeeRefundAuditTest for that path)
        $program = $this->createHajjProgram();
        $this->actAs($this->admin);
        $booking = $this->createHajjBooking($program);

        $this->actAs($this->restrictedEmployee);
        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'amount' => 1000.0,
            'reason' => 'restricted employee audit',
        ]);
        $response->assertStatus(403, 'Restricted employee (no manage_refunds) must NOT be able to refund');
    }

    public function test_employee_cannot_delete_booking(): void
    {
        $program = $this->createHajjProgram();
        $this->actAs($this->admin);
        $booking = $this->createHajjBooking($program);

        $this->actAs($this->normalEmployee);
        $response = $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}");
        $response->assertStatus(403, 'Employee must NOT be able to delete a Hajj booking (admin-only)');
    }

    public function test_employee_cannot_delete_program(): void
    {
        $program = $this->createHajjProgram();
        $this->actAs($this->normalEmployee);

        $response = $this->deleteJson("/api/v1/hajj-umra/programs/{$program->id}");
        $response->assertStatus(403, 'Employee must NOT be able to delete a Hajj program (admin-only)');
    }

    public function test_employee_cannot_withdraw_from_executing_company(): void
    {
        $company = $this->createExecutingCompany();

        $this->actAs($this->normalEmployee);
        $response = $this->postJson("/api/v1/hajj-umra/executing-companies/{$company->id}/withdraw", [
            'amount' => 100.0,
            'account_id' => $this->vaultEgp->id,
        ]);
        $response->assertStatus(403, 'Employee must NOT be able to withdraw (admin-only)');
    }

    public function test_employee_cannot_repay_executing_company(): void
    {
        $company = $this->createExecutingCompany();

        $this->actAs($this->normalEmployee);
        $response = $this->postJson("/api/v1/hajj-umra/executing-companies/{$company->id}/repay", [
            'amount' => 100.0,
            'account_id' => $this->vaultEgp->id,
        ]);
        $response->assertStatus(403, 'Employee must NOT be able to repay (admin-only)');
    }

    /* ============================================================
     *  ADMIN HAPPY PATH (control — admin CAN do these)
     * ============================================================ */

    public function test_admin_can_cancel_booking(): void
    {
        $program = $this->createHajjProgram();
        $this->actAs($this->admin);
        $booking = $this->createHajjBooking($program);

        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'admin control test',
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
        $response = $this->getJson('/api/v1/hajj-umra/treasury/overview');
        $response->assertStatus(200);
    }

    /* ============================================================
     *  HELPERS
     * ============================================================ */

    protected function hajjBookingPayload(Program $program, array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'program_id' => $program->id,
            'selling_price' => 30000.0,
            'purchase_price' => 25000.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
            'agent_name' => 'EMP_AUDIT_20260817',
            'notes' => 'Employee audit booking',
        ], $overrides);
    }

    protected function createHajjBooking(Program $program): HajjUmraBooking
    {
        $payload = $this->hajjBookingPayload($program);
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);

        return HajjUmraBooking::findOrFail($response->json('data.id'));
    }

    protected function createExecutingCompany(): \App\Models\HajjUmra\HajjUmraExecutingCompany
    {
        return \App\Models\HajjUmra\HajjUmraExecutingCompany::query()->create([
            'name' => 'EMP_AUDIT_20260817_ExecutingCo',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
    }
}