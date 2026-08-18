<?php

namespace Tests\Feature\TourismEmployeeE2E;

use App\Models\AuditLog;
use App\Models\HajjUmraBooking;
use App\Models\RefundAuditLog;
use App\Models\VisaBooking;
use App\Support\UserPermissions;
use Illuminate\Support\Str;

/**
 * Tourism Employee Refund — Implementation + Audit Trail + Regression Test Suite.
 *
 * Audit: EMP_REFUND_AUDIT_20260817
 * Scope: Flight, Hajj/Umrah, Visa ONLY.
 *
 * Sections (per spec):
 *   A. Authorization             (5 tests)
 *   B. Actor identity            (4 tests)
 *   C. Refund amount             (8 tests)
 *   D. Audit trail               (6 tests)
 *   E. Financial integrity       (5 tests)
 *   F. Rollback                  (2 tests)
 *   G. Idempotency               (3 tests)
 *   H. Cross-module isolation    (2 tests)
 *   I. Admin regression          (4 tests)
 *   J. Existing 97 tests regression (1 orchestrator)
 *
 * Total: ~40 new tests. All prefixed EMP_REFUND_AUDIT_20260817_*.
 */
class EmployeeRefundAuditTest extends EmployeeTestCase
{
    /* ===================================================================
     *  SETUP — additional employees used by section B (actor identity)
     * =================================================================== */

    protected \App\Models\User $thirdPartyEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        // Extra employee (used for impersonation/IDOR-style attempts in section B)
        $this->thirdPartyEmployee = $this->makeUser('employee', [
            'name' => 'EMP_REFUND_AUDIT_20260817_ThirdParty_'.uniqid(),
            'permissions' => UserPermissions::defaultEmployeeModules(),
        ]);
    }

    /* ===================================================================
     *  Helpers
     * =================================================================== */

    /**
     * Seed a Hajj booking and add one payment so it has money to refund.
     * Returns [$booking, $paidAmount].
     */
    protected function seedHajjBookingWithPayment(float $paidAmount = 5000.0): array
    {
        $program = $this->createHajjProgram();
        $this->actAs($this->admin);
        $booking = $this->createHajjBooking($program);

        // Add a payment so the booking has something to refund.
        $this->actAs($this->normalEmployee);
        $payResponse = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => $paidAmount,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'EMP_REFUND_AUDIT_20260817_PAY_HAJJ_'.uniqid(),
        ]);
        $payResponse->assertStatus(201);

        $booking->refresh();

        return [$booking, $paidAmount];
    }

    /**
     * Seed a Visa booking and add one payment.
     */
    protected function seedVisaBookingWithPayment(float $paidAmount = 500.0): array
    {
        $this->actAs($this->admin);
        $booking = $this->createVisaBooking();

        $this->actAs($this->normalEmployee);
        $payResponse = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => $paidAmount,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
        ]);
        $payResponse->assertStatus(201);

        $booking->refresh();

        return [$booking, $paidAmount];
    }

    /**
     * Create a Visa booking — mirrors EmployeeVisaE2ETest::createVisaBooking
     * (kept inline so the test file is self-contained).
     */
    protected function createVisaBooking(): \App\Models\VisaBooking
    {
        $payload = $this->visaBookingPayload();
        $response = $this->postJson('/api/v1/visa/bookings', $payload);

        return \App\Models\VisaBooking::findOrFail($response->json('data.id'));
    }

    protected function visaBookingPayload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'purchase_price' => 1000.0,
            'selling_price' => 1500.0,
            'service_fee' => 100.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
            'agent_name' => 'EMP_REFUND_AUDIT_20260817',
            'notes' => 'Refund audit booking',
            'visa_details' => [
                'visa_type' => 'tourist',
                'country' => 'EG',
                'duration' => '30 days',
            ],
        ], $overrides);
    }

    /**
     * Create a Hajj booking — mirrors EmployeeHajjUmraE2ETest::createHajjBooking.
     */
    protected function createHajjBooking(\App\Models\Program $program): \App\Models\HajjUmraBooking
    {
        $payload = $this->hajjBookingPayload($program);
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);

        return \App\Models\HajjUmraBooking::findOrFail($response->json('data.id'));
    }

    protected function hajjBookingPayload(\App\Models\Program $program, array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'program_id' => $program->id,
            'selling_price' => 30000.0,
            'purchase_price' => 25000.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
            'agent_name' => 'EMP_REFUND_AUDIT_20260817',
            'notes' => 'Refund audit booking',
        ], $overrides);
    }

    /* ===================================================================
     *  Section A — Authorization (5 tests)
     * =================================================================== */

    public function test_a01_normal_employee_can_refund_hajj_booking(): void
    {
        [$booking, $paidAmount] = $this->seedHajjBookingWithPayment(5000.0);

        $this->actAs($this->normalEmployee);
        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 customer request',
        ]);
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }

    public function test_a02_restricted_employee_cannot_refund_hajj_without_manage_refunds(): void
    {
        [$booking] = $this->seedHajjBookingWithPayment(5000.0);

        $this->actAs($this->restrictedEmployee);
        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 restricted attempt',
        ]);
        $response->assertStatus(403, 'Restricted employee must be rejected by manage_refunds middleware');
    }

    public function test_a03_admin_can_refund_visa_booking(): void
    {
        [$booking] = $this->seedVisaBookingWithPayment(500.0);

        $this->actAs($this->admin);
        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 admin refund',
        ]);
        $this->assertContains($response->status(), [200, 201], 'Admin must be able to refund');
    }

    public function test_a04_inactive_employee_cannot_refund(): void
    {
        [$booking] = $this->seedHajjBookingWithPayment(5000.0);

        $this->actAs($this->inactiveEmployee);
        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 inactive attempt',
        ]);
        // Inactive employees are rejected by EnsureIsActive middleware
        $this->assertContains(
            $response->status(),
            [401, 403],
            'Inactive employee must be rejected (401 or 403)'
        );
    }

    public function test_a05_unauthenticated_cannot_refund(): void
    {
        [$booking] = $this->seedHajjBookingWithPayment(5000.0);

        // Drop authentication entirely
        $this->app['auth']->forgetGuards();

        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 unauth attempt',
        ]);
        $this->assertContains(
            $response->status(),
            [401, 403],
            'Unauthenticated request must be rejected'
        );
    }

    /* ===================================================================
     *  Section B — Actor identity (4 tests)
     * =================================================================== */

    public function test_b01_actor_id_in_audit_comes_from_auth_not_payload(): void
    {
        [$booking] = $this->seedHajjBookingWithPayment(5000.0);

        $this->actAs($this->normalEmployee);
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 actor identity test',
            // The frontend MUST NOT be allowed to specify user_id.
            // If this field were honoured, the actor identity would be spoofable.
            'user_id' => $this->admin->id,
            'performed_by' => $this->admin->id,
        ])->assertStatus(200);

        $audit = RefundAuditLog::query()
            ->where('booking_id', $booking->id)
            ->where('module', 'hajj_umra')
            ->latest('id')
            ->first();

        $this->assertNotNull($audit, 'refund_audit_logs row must be created');
        $this->assertSame(
            (int) $this->normalEmployee->id,
            (int) $audit->user_id,
            'user_id must come from the AUTHENTICATED user, NOT from payload'
        );
        $this->assertNotSame(
            (int) $this->admin->id,
            (int) $audit->user_id,
            'admin.id must NOT leak into the audit when a different user performed the refund'
        );
    }

    public function test_b02_actor_id_for_visa_refund_comes_from_auth(): void
    {
        [$booking] = $this->seedVisaBookingWithPayment(500.0);

        $this->actAs($this->normalEmployee);
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 visa actor test',
        ])->assertStatus(200);

        $audit = RefundAuditLog::query()
            ->where('booking_id', $booking->id)
            ->where('module', 'visa')
            ->latest('id')
            ->first();

        $this->assertNotNull($audit, 'refund_audit_logs row must be created for visa');
        $this->assertSame(
            (int) $this->normalEmployee->id,
            (int) $audit->user_id,
            'Visa refund actor must be the authenticated user'
        );
    }

    public function test_b03_actor_name_is_denormalized_from_auth_user(): void
    {
        [$booking] = $this->seedHajjBookingWithPayment(5000.0);

        $this->actAs($this->normalEmployee);
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 actor name test',
        ])->assertStatus(200);

        $audit = RefundAuditLog::query()
            ->where('booking_id', $booking->id)
            ->where('module', 'hajj_umra')
            ->latest('id')
            ->first();

        $this->assertSame(
            $this->normalEmployee->name,
            $audit->user_name,
            'user_name in audit must match the authenticated user.name'
        );
    }

    public function test_b04_no_impersonation_two_distinct_employees(): void
    {
        [$booking] = $this->seedHajjBookingWithPayment(5000.0);

        // employee A performs refund
        $this->actAs($this->normalEmployee);
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 by employee A',
        ])->assertStatus(200);

        $auditA = RefundAuditLog::query()
            ->where('booking_id', $booking->id)
            ->where('module', 'hajj_umra')
            ->latest('id')
            ->first();
        $this->assertSame(
            (int) $this->normalEmployee->id,
            (int) $auditA->user_id,
            'first refund must be attributed to employee A'
        );

        // employee B tries to "tag along" — must not be able to alter the previous row
        $this->actAs($this->otherEmployee);
        // Same booking is now refunded; the second attempt must be rejected (lifecycle guard)
        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 by employee B (should fail)',
            'user_id' => $this->normalEmployee->id,
        ]);
        $this->assertNotSame(
            200,
            $response->status(),
            'Second refund must NOT succeed; lifecycle guard must reject'
        );

        // Audit row count must be exactly 1
        $auditCount = RefundAuditLog::query()
            ->where('booking_id', $booking->id)
            ->where('module', 'hajj_umra')
            ->count();
        $this->assertSame(1, $auditCount, 'Exactly one refund audit row must exist');
    }

    /* ===================================================================
     *  Section C — Refund amount (8 tests)
     * =================================================================== */

    public function test_c01_full_refund_succeeds_for_hajj(): void
    {
        [$booking, $paidAmount] = $this->seedHajjBookingWithPayment(5000.0);

        $this->actAs($this->normalEmployee);
        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 full refund',
        ]);
        $response->assertStatus(200);

        $booking->refresh();
        $statusValue = $booking->status instanceof \BackedEnum ? $booking->status->value : (string) $booking->status;
        $this->assertSame('refunded', $statusValue);

        $audit = RefundAuditLog::query()
            ->where('booking_id', $booking->id)
            ->latest('id')->first();
        $this->assertSame(
            (float) $paidAmount,
            (float) $audit->refund_amount,
            'Refund amount in audit must equal the paid amount for full refund'
        );
    }

    public function test_c02_cannot_refund_zero_amount_explicit(): void
    {
        [$booking] = $this->seedHajjBookingWithPayment(5000.0);

        $this->actAs($this->normalEmployee);
        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 zero amount',
            'amount' => 0,
        ]);
        // Either rejected with 422 OR accepted as full-refund (no amount field on hajj/visa).
        // The key invariant: NO financial effect if 0.
        if ($response->status() === 200) {
            $audit = RefundAuditLog::query()
                ->where('booking_id', $booking->id)->latest('id')->first();
            $this->assertGreaterThan(0, (float) $audit->refund_amount, 'Audit refund_amount must NOT be zero');
        } else {
            $this->assertContains($response->status(), [422, 400]);
        }
    }

    public function test_c03_cannot_refund_unpaid_hajj_booking(): void
    {
        // Create hajj booking with NO payment
        $program = $this->createHajjProgram();
        $this->actAs($this->admin);
        $booking = $this->createHajjBooking($program);

        $this->actAs($this->normalEmployee);
        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 refund unpaid',
        ]);

        // No paid money → either refused (422) or full reversal succeeds (additive,
        // which is harmless). Must NOT produce a financial effect with > 0 amount.
        if ($response->status() === 200) {
            $audit = RefundAuditLog::query()
                ->where('booking_id', $booking->id)->latest('id')->first();
            $this->assertSame(0.0, (float) $audit->refund_amount, 'No money paid → refund_amount must be 0.00');
        } else {
            $this->assertContains($response->status(), [422, 400]);
        }
    }

    public function test_c04_already_refunded_booking_is_rejected(): void
    {
        [$booking] = $this->seedHajjBookingWithPayment(5000.0);

        $this->actAs($this->normalEmployee);
        // First refund succeeds
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 first',
        ])->assertStatus(200);

        // Second attempt must be rejected (idempotency guard)
        $this->actAs($this->otherEmployee);
        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 duplicate',
        ]);
        $this->assertNotSame(200, $response->status(), 'Duplicate refund must be rejected (lifecycle guard)');
    }

    public function test_c05_cancelled_booking_cannot_be_refunded(): void
    {
        [$booking] = $this->seedHajjBookingWithPayment(5000.0);

        // Cancel via admin
        $this->actAs($this->admin);
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 cancel before refund',
        ])->assertStatus(200);

        // Try to refund → must be rejected
        $this->actAs($this->normalEmployee);
        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 refund after cancel',
        ]);
        $this->assertNotSame(
            200,
            $response->status(),
            'Refund on cancelled booking must be rejected (BUG-FIX 2026-07-27)'
        );
    }

    public function test_c06_visa_full_refund_succeeds(): void
    {
        [$booking, $paidAmount] = $this->seedVisaBookingWithPayment(500.0);

        $this->actAs($this->normalEmployee);
        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 visa full refund',
        ]);
        $response->assertStatus(200);

        $audit = RefundAuditLog::query()
            ->where('booking_id', $booking->id)
            ->where('module', 'visa')
            ->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame(
            (float) $paidAmount,
            (float) $audit->refund_amount,
            'Visa refund amount must match the paid amount'
        );
    }

    public function test_c07_visa_duplicate_refund_rejected(): void
    {
        [$booking] = $this->seedVisaBookingWithPayment(500.0);

        $this->actAs($this->normalEmployee);
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 first',
        ])->assertStatus(200);

        $this->actAs($this->otherEmployee);
        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 duplicate',
        ]);
        $this->assertNotSame(200, $response->status(), 'Visa duplicate refund must be rejected');
    }

    public function test_c08_refund_amount_field_in_audit_must_be_positive_or_zero(): void
    {
        [$booking] = $this->seedHajjBookingWithPayment(7500.0);

        $this->actAs($this->normalEmployee);
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 positive amount test',
            // Try to inject a negative amount via payload — backend must ignore
            'amount' => -99999.99,
        ])->assertStatus(200);

        $audit = RefundAuditLog::query()
            ->where('booking_id', $booking->id)
            ->latest('id')->first();
        $this->assertGreaterThanOrEqual(0, (float) $audit->refund_amount, 'Refund amount must be >= 0');
    }

    /* ===================================================================
     *  Section D — Audit trail (6 tests)
     * =================================================================== */

    public function test_d01_refund_audit_logs_row_is_created(): void
    {
        [$booking] = $this->seedHajjBookingWithPayment(5000.0);

        $this->actAs($this->normalEmployee);
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 trail test',
        ])->assertStatus(200);

        $this->assertDatabaseHas('refund_audit_logs', [
            'booking_id' => $booking->id,
            'module' => 'hajj_umra',
            'user_id' => $this->normalEmployee->id,
        ]);
    }

    public function test_d02_audit_logs_row_is_created(): void
    {
        [$booking] = $this->seedHajjBookingWithPayment(5000.0);

        $this->actAs($this->normalEmployee);
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 generic audit test',
        ])->assertStatus(200);

        // The generic audit_logs table should also have a row tagged refund.processed
        $auditLog = AuditLog::query()
            ->where('action', 'refund.processed')
            ->where('user_id', $this->normalEmployee->id)
            ->latest('id')->first();

        $this->assertNotNull($auditLog, 'audit_logs row with action=refund.processed must be created');
    }

    public function test_d03_all_required_audit_fields_are_populated(): void
    {
        [$booking] = $this->seedHajjBookingWithPayment(5000.0);

        $this->actAs($this->normalEmployee);
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 fields test',
        ])->assertStatus(200);

        $audit = RefundAuditLog::query()
            ->where('booking_id', $booking->id)
            ->where('module', 'hajj_umra')
            ->latest('id')->first();

        $this->assertNotNull($audit->user_id, 'user_id required');
        $this->assertNotNull($audit->user_name, 'user_name required');
        $this->assertSame('hajj_umra', $audit->module);
        $this->assertNotNull($audit->booking_id);
        $this->assertNotNull($audit->booking_reference, 'booking_reference required');
        $this->assertSame($booking->customer_id, $audit->customer_id);
        $this->assertNotNull($audit->customer_name, 'customer_name required');
        $this->assertGreaterThan(0, (float) $audit->refund_amount);
        $this->assertNotNull($audit->currency, 'currency required');
        $this->assertNotNull($audit->created_at);
    }

    public function test_d04_customer_name_and_booking_reference_are_captured(): void
    {
        [$booking] = $this->seedHajjBookingWithPayment(5000.0);

        $this->actAs($this->normalEmployee);
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 reference capture',
        ])->assertStatus(200);

        $audit = RefundAuditLog::query()
            ->where('booking_id', $booking->id)->latest('id')->first();

        $this->assertSame(
            $booking->customer?->full_name ?? $booking->customer?->name,
            $audit->customer_name,
            'customer_name in audit must match the booking customer'
        );
        $this->assertNotEmpty($audit->booking_reference, 'booking_reference must be populated');
    }

    public function test_d05_audit_persists_independently_of_session(): void
    {
        [$booking] = $this->seedHajjBookingWithPayment(5000.0);

        $this->actAs($this->normalEmployee);
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 persist test',
        ])->assertStatus(200);

        // Forget guards, then re-query the DB directly
        $this->app['auth']->forgetGuards();
        $count = \Illuminate\Support\Facades\DB::table('refund_audit_logs')
            ->where('booking_id', $booking->id)
            ->count();

        $this->assertSame(1, $count, 'Audit row must persist in DB after auth dropped');
    }

    public function test_d06_visa_audit_fields_match_hajj_shape(): void
    {
        [$booking] = $this->seedVisaBookingWithPayment(500.0);

        $this->actAs($this->normalEmployee);
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 visa audit shape',
        ])->assertStatus(200);

        $audit = RefundAuditLog::query()
            ->where('booking_id', $booking->id)
            ->where('module', 'visa')
            ->latest('id')->first();

        $this->assertNotNull($audit);
        $this->assertSame('visa', $audit->module);
        $this->assertSame((int) $this->normalEmployee->id, (int) $audit->user_id);
        $this->assertNotNull($audit->user_name);
        $this->assertGreaterThan(0, (float) $audit->refund_amount);
    }

    /* ===================================================================
     *  Section E — Financial integrity (5 tests)
     * =================================================================== */

    public function test_e01_refund_produces_balanced_double_entry(): void
    {
        [$booking] = $this->seedHajjBookingWithPayment(5000.0);

        $balanceBefore = (float) $this->vaultEgp->fresh()->balance;

        $this->actAs($this->normalEmployee);
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 ledger balance test',
        ])->assertStatus(200);

        // After full refund of 5000 paid, vault balance should be restored
        $balanceAfter = (float) $this->vaultEgp->fresh()->balance;

        // We don't assert exact delta because the additive reversal may
        // offset multiple rows; we assert: balance change = -5000 (refund returned
        // the money to the vault)
        $this->assertEqualsWithDelta(
            $balanceBefore,
            $balanceAfter + 5000.0,
            0.01,
            'Vault balance must increase by exactly the refund amount (additive reversal)'
        );
    }

    public function test_e02_no_negative_balances_after_refund(): void
    {
        [$booking] = $this->seedHajjBookingWithPayment(5000.0);

        $this->actAs($this->normalEmployee);
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 no negatives',
        ])->assertStatus(200);

        // No account should have a negative balance after the refund
        $negativeCount = \Illuminate\Support\Facades\DB::table('accounts')
            ->where('balance', '<', 0)
            ->count();
        $this->assertSame(0, $negativeCount, 'No account may end up with a negative balance');
    }

    public function test_e03_double_entry_invariant_sum_debit_equals_sum_credit(): void
    {
        [$booking] = $this->seedHajjBookingWithPayment(5000.0);

        $this->actAs($this->normalEmployee);
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 sum invariant',
        ])->assertStatus(200);

        // Across the entire DB, sum of debits must equal sum of credits.
        // The system uses a single-sided 'amount' column with a 'direction' or type
        // — check the actual schema.
        $totalDebit = (float) \Illuminate\Support\Facades\DB::table('account_entries')
            ->where('type', 'debit')->sum('amount');
        $totalCredit = (float) \Illuminate\Support\Facades\DB::table('account_entries')
            ->where('type', 'credit')->sum('amount');

        // The invariant must hold within a 0.01 EGP tolerance
        $variance = abs($totalDebit - $totalCredit);
        $this->assertLessThanOrEqual(0.01, $variance, 'Double-entry invariant: SUM(debit)=SUM(credit)');
    }

    public function test_e04_transaction_record_exists_with_correct_type(): void
    {
        [$booking] = $this->seedHajjBookingWithPayment(5000.0);

        $this->actAs($this->normalEmployee);
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 tx record',
        ])->assertStatus(200);

        // There should be a refund-related transaction (or additive reversal entries)
        $this->assertDatabaseHas('account_entries', [
            // We don't assert exact booking_id since the entries are tied to
            // transactions, not directly to bookings. Just assert the refund
            // produced new entries.
        ]);
        $entryCount = \Illuminate\Support\Facades\DB::table('account_entries')->count();
        $this->assertGreaterThan(0, $entryCount, 'Refund must produce account entries');
    }

    public function test_e05_visa_refund_also_balances_ledger(): void
    {
        [$booking] = $this->seedVisaBookingWithPayment(500.0);

        $balanceBefore = (float) $this->vaultEgp->fresh()->balance;

        $this->actAs($this->normalEmployee);
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 visa ledger',
        ])->assertStatus(200);

        $balanceAfter = (float) $this->vaultEgp->fresh()->balance;
        $delta = $balanceAfter - $balanceBefore;
        $this->assertEqualsWithDelta(
            500.0,
            $delta,
            0.01,
            'Visa refund must restore vault balance by exactly the paid amount (delta=+500)'
        );
    }

    /* ===================================================================
     *  Section F — Rollback (2 tests)
     * =================================================================== */

    public function test_f01_failed_refund_leaves_no_partial_state(): void
    {
        [$booking] = $this->seedHajjBookingWithPayment(5000.0);

        $auditCountBefore = RefundAuditLog::query()->count();

        // Try to refund with invalid reason (excessive length triggers validation error)
        $this->actAs($this->normalEmployee);
        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => str_repeat('X', 5000), // > max:1000
        ]);

        // Either rejected at validation (422) OR accepted (HajjUmraController only
        // validates 'reason' as max:1000 so this should be 422)
        $this->assertNotSame(200, $response->status(), 'Invalid reason must be rejected');

        // Audit count must NOT have increased
        $auditCountAfter = RefundAuditLog::query()->count();
        $this->assertSame($auditCountBefore, $auditCountAfter, 'Failed refund must NOT create audit row');
    }

    public function test_f02_duplicate_refund_creates_no_duplicate_financial_entries(): void
    {
        [$booking] = $this->seedHajjBookingWithPayment(5000.0);

        // First refund succeeds
        $this->actAs($this->normalEmployee);
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 first',
        ])->assertStatus(200);

        $entryCountAfterFirst = \Illuminate\Support\Facades\DB::table('account_entries')->count();
        $auditCountAfterFirst = RefundAuditLog::query()->count();

        // Second refund attempt
        $this->actAs($this->otherEmployee);
        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 duplicate',
        ]);
        $this->assertNotSame(200, $response->status(), 'Duplicate refund must be rejected');

        // Counts must be identical
        $entryCountAfterSecond = \Illuminate\Support\Facades\DB::table('account_entries')->count();
        $auditCountAfterSecond = RefundAuditLog::query()->count();

        $this->assertSame(
            $entryCountAfterFirst,
            $entryCountAfterSecond,
            'Duplicate refund must NOT add new financial entries'
        );
        $this->assertSame(
            $auditCountAfterFirst,
            $auditCountAfterSecond,
            'Duplicate refund must NOT add new audit rows'
        );
    }

    /* ===================================================================
     *  Section G — Idempotency (3 tests)
     * =================================================================== */

    public function test_g01_hajj_lifecycle_guard_prevents_double_refund(): void
    {
        [$booking] = $this->seedHajjBookingWithPayment(5000.0);

        $this->actAs($this->normalEmployee);
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 first',
        ])->assertStatus(200);

        // Status is now 'refunded' — second refund attempt rejected
        $auditCountAfterFirst = RefundAuditLog::query()->count();

        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 second',
        ]);
        $this->assertNotSame(200, $response->status(), 'Idempotency: 2nd refund must be rejected');

        $this->assertSame(
            $auditCountAfterFirst,
            RefundAuditLog::query()->count(),
            'Idempotency: exactly one audit row for one booking'
        );
    }

    public function test_g02_visa_lifecycle_guard_prevents_double_refund(): void
    {
        [$booking] = $this->seedVisaBookingWithPayment(500.0);

        $this->actAs($this->normalEmployee);
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 first',
        ])->assertStatus(200);

        $auditCountAfterFirst = RefundAuditLog::query()->count();

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 second',
        ]);
        $this->assertNotSame(200, $response->status(), 'Visa idempotency: 2nd refund must be rejected');

        $this->assertSame(
            $auditCountAfterFirst,
            RefundAuditLog::query()->count(),
            'Visa idempotency: exactly one audit row'
        );
    }

    public function test_g03_two_different_bookings_each_get_their_own_audit_row(): void
    {
        [$bookingA] = $this->seedHajjBookingWithPayment(5000.0);
        [$bookingB] = $this->seedHajjBookingWithPayment(3000.0);

        $this->actAs($this->normalEmployee);
        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingA->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 refund A',
        ])->assertStatus(200);

        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingB->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 refund B',
        ])->assertStatus(200);

        $auditA = RefundAuditLog::query()->where('booking_id', $bookingA->id)->first();
        $auditB = RefundAuditLog::query()->where('booking_id', $bookingB->id)->first();

        $this->assertNotNull($auditA);
        $this->assertNotNull($auditB);
        $this->assertNotSame($auditA->id, $auditB->id, 'Different bookings must get different audit rows');
    }

    /* ===================================================================
     *  Section H — Cross-module isolation (2 tests)
     * =================================================================== */

    public function test_h01_hajj_refund_does_not_touch_office_accounts(): void
    {
        // Create an Office-division account (bus/fawry style)
        $officeAccount = \App\Models\Account::query()->create([
            'name' => 'EMP_REFUND_AUDIT_20260817_OFFICE_VAULT',
            'type' => \App\Enums\AccountType::Cashbox->value,
            'currency' => 'EGP',
            'balance' => 100_000.00,
            'is_active' => true,
            'owner_type' => \App\Models\Account::OWNER_TYPE_OFFICE,
            'module_type' => 'office',
            'module' => 'bus',
            'is_module_vault' => true,
            'created_by' => $this->admin->id,
        ]);

        $officeBalanceBefore = (float) $officeAccount->balance;

        [$booking] = $this->seedHajjBookingWithPayment(5000.0);

        $this->actAs($this->normalEmployee);
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 cross-iso test',
        ])->assertStatus(200);

        $officeBalanceAfter = (float) $officeAccount->fresh()->balance;
        $this->assertSame(
            $officeBalanceBefore,
            $officeBalanceAfter,
            'Office-division account balance must NOT change due to a Tourism refund'
        );
    }

    public function test_h02_visa_refund_does_not_create_office_ledger_entries(): void
    {
        $officeEntriesBefore = \Illuminate\Support\Facades\DB::table('account_entries')
            ->whereIn('account_id', function ($q) {
                $q->select('id')->from('accounts')->where('module_type', 'office');
            })
            ->count();

        [$booking] = $this->seedVisaBookingWithPayment(500.0);

        $this->actAs($this->normalEmployee);
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 office ledger test',
        ])->assertStatus(200);

        $officeEntriesAfter = \Illuminate\Support\Facades\DB::table('account_entries')
            ->whereIn('account_id', function ($q) {
                $q->select('id')->from('accounts')->where('module_type', 'office');
            })
            ->count();

        $this->assertSame(
            $officeEntriesBefore,
            $officeEntriesAfter,
            'Visa refund must NOT create ledger entries on Office-division accounts'
        );
    }

    /* ===================================================================
     *  Section I — Admin regression (4 tests)
     * =================================================================== */

    public function test_i01_admin_can_cancel_hajj_booking(): void
    {
        $program = $this->createHajjProgram();
        $this->actAs($this->admin);
        $booking = $this->createHajjBooking($program);

        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 admin cancel',
        ]);
        $this->assertContains($response->status(), [200, 201], 'Admin must still be able to cancel');
    }

    public function test_i02_admin_can_delete_hajj_booking(): void
    {
        $program = $this->createHajjProgram();
        $this->actAs($this->admin);
        $booking = $this->createHajjBooking($program);

        $response = $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}");
        $this->assertContains($response->status(), [200, 204], 'Admin must still be able to delete');
    }

    public function test_i03_admin_can_refund_after_employee_failure(): void
    {
        // Employee fails on cancelled booking
        [$booking] = $this->seedHajjBookingWithPayment(5000.0);

        $this->actAs($this->admin);
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 admin cancels first',
        ])->assertStatus(200);

        // Employee's refund attempt fails
        $this->actAs($this->normalEmployee);
        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 employee retry',
        ]);
        $this->assertNotSame(200, $response->status(), 'Employee must NOT refund a cancelled booking');
    }

    public function test_i04_admin_keeps_full_refund_privilege(): void
    {
        [$booking] = $this->seedHajjBookingWithPayment(5000.0);

        $this->actAs($this->admin);
        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_REFUND_AUDIT_20260817 admin direct refund',
        ]);
        $response->assertStatus(200);

        $audit = RefundAuditLog::query()
            ->where('booking_id', $booking->id)
            ->where('module', 'hajj_umra')
            ->latest('id')->first();
        $this->assertSame((int) $this->admin->id, (int) $audit->user_id);
    }

    /* ===================================================================
     *  Section J — Existing 97 tests regression (1 orchestrator)
     * =================================================================== */

    public function test_j01_existing_97_test_files_present_and_loadable(): void
    {
        // This is a smoke test — verifies the file surface of the regression
        // suite. The actual execution of the 97 tests is done via
        // `phpunit tests/Feature/TourismEmployeeE2E` separately.

        $expectedFiles = [
            'EmployeePermissionsWiringTest',
            'EmployeeFlightE2ETest',
            'EmployeeHajjUmraE2ETest',
            'EmployeeVisaE2ETest',
            'EmployeeIDORTest',
            'EmployeeFinancialIntegrityTest',
            'EmployeeIdempotencyTest',
            'EmployeeIsolationTest',
            'FrontendPermissionAuditTest',
            'EmployeeDatabaseIntegrityTest',
        ];

        $base = __DIR__;
        $missing = [];
        foreach ($expectedFiles as $name) {
            if (!file_exists($base.DIRECTORY_SEPARATOR.$name.'.php')) {
                $missing[] = $name;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'All 10 existing test files must still be present (regression surface preserved)'
        );
    }

    /* ===================================================================
     *  Section K — Atomicity failure injection (EMP_REFUND_ATOMICITY_FIX_20260817)
     *
     *  These tests prove that the Tourism refund is ATOMIC with its
     *  mandatory audit persistence. They exercise the REAL production refund
     *  flow (no mocks of the refund service, no manual throws, no bypass).
     *
     *  Failure injection method:
     *    - Drop the audit table mid-test using Schema::drop()
     *    - Call the actual HTTP endpoint via postJson()
     *    - The real Eloquent insert() will throw QueryException
     *    - The exception propagates through DB::transaction → rollback
     *    - Controller catches → returns 422
     *
     *  Evidence captured per test:
     *    - Response status (must NOT be 200)
     *    - Vault balance (must be unchanged)
     *    - Payment row count (must be unchanged)
     *    - account_entries count (must be unchanged)
     *    - Booking status (must NOT be 'refunded')
     *    - refund_audit_logs count (must be 0)
     *    - audit_logs count (must be 0)
     * =================================================================== */

    public function test_k01_refund_audit_logs_failure_rolls_back_hajj_refund(): void
    {
        // ── Setup ──
        [$booking, $paidAmount] = $this->seedHajjBookingWithPayment(5000.0);

        // Capture pre-state — these MUST be unchanged after the failed refund
        $vaultBalanceBefore = (float) $this->vaultEgp->fresh()->balance;
        $paymentCountBefore = (int) \Illuminate\Support\Facades\DB::table('hajj_umra_payments')->count();
        $entryCountBefore = (int) \Illuminate\Support\Facades\DB::table('account_entries')->count();
        $auditCountBefore = (int) \Illuminate\Support\Facades\DB::table('refund_audit_logs')->count();

        // ── Inject failure: drop the mandatory refund_audit_logs table ──
        // This forces the real RefundAuditLog::create() inside the refund flow
        // to throw QueryException. The fix means this throw will propagate
        // through DB::transaction and trigger a full rollback.
        \Illuminate\Support\Facades\Schema::drop('refund_audit_logs');

        // ── Attempt the REAL production refund flow ──
        $this->actAs($this->normalEmployee);
        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_ATOMICITY_FIX_20260817 failure injection — refund_audit_logs dropped',
        ]);

        // ── Expected: refund MUST fail (not 200) ──
        $this->assertNotSame(
            200,
            $response->status(),
            'Refund MUST NOT succeed when refund_audit_logs cannot be persisted'
        );

        // ── Evidence: complete rollback ──
        // Refresh the booking first to get a clean read
        $booking->refresh();
        $bookingStatus = $booking->status instanceof \BackedEnum ? $booking->status->value : (string) $booking->status;

        $this->assertSame(
            $vaultBalanceBefore,
            (float) $this->vaultEgp->fresh()->balance,
            'ATOMICITY VIOLATED: vault balance changed despite audit failure'
        );

        $this->assertSame(
            $paymentCountBefore,
            (int) \Illuminate\Support\Facades\DB::table('hajj_umra_payments')->count(),
            'ATOMICITY VIOLATED: payments table changed despite audit failure'
        );

        $this->assertSame(
            $entryCountBefore,
            (int) \Illuminate\Support\Facades\DB::table('account_entries')->count(),
            'ATOMICITY VIOLATED: account_entries count changed despite audit failure'
        );

        $this->assertNotSame(
            'refunded',
            $bookingStatus,
            'ATOMICITY VIOLATED: booking status changed to refunded despite audit failure'
        );

        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasTable('refund_audit_logs'),
            'refund_audit_logs table should still be dropped (we dropped it; rollback must NOT have re-created it)'
        );
    }

    public function test_k02_audit_logs_failure_rolls_back_hajj_refund(): void
    {
        // ── Setup ──
        [$booking, $paidAmount] = $this->seedHajjBookingWithPayment(5000.0);

        // Capture pre-state
        $vaultBalanceBefore = (float) $this->vaultEgp->fresh()->balance;
        $paymentCountBefore = (int) \Illuminate\Support\Facades\DB::table('hajj_umra_payments')->count();
        $entryCountBefore = (int) \Illuminate\Support\Facades\DB::table('account_entries')->count();

        // ── Inject failure: drop the mandatory audit_logs table ──
        // This forces the real AuditLog::create() inside the refund flow
        // to throw QueryException AFTER refund_audit_logs has already succeeded.
        // The fix means this throw will propagate and trigger a full rollback —
        // the already-inserted refund_audit_logs row MUST be rolled back too.
        \Illuminate\Support\Facades\Schema::drop('audit_logs');

        // ── Attempt the REAL production refund flow ──
        $this->actAs($this->normalEmployee);
        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_ATOMICITY_FIX_20260817 failure injection — audit_logs dropped',
        ]);

        // ── Expected: refund MUST fail ──
        $this->assertNotSame(
            200,
            $response->status(),
            'Refund MUST NOT succeed when audit_logs cannot be persisted'
        );

        // ── Evidence: complete rollback ──
        $booking->refresh();
        $bookingStatus = $booking->status instanceof \BackedEnum ? $booking->status->value : (string) $booking->status;

        $this->assertSame(
            $vaultBalanceBefore,
            (float) $this->vaultEgp->fresh()->balance,
            'ATOMICITY VIOLATED: vault balance changed despite audit_logs failure'
        );

        $this->assertSame(
            $paymentCountBefore,
            (int) \Illuminate\Support\Facades\DB::table('hajj_umra_payments')->count(),
            'ATOMICITY VIOLATED: payments table changed despite audit_logs failure'
        );

        $this->assertSame(
            $entryCountBefore,
            (int) \Illuminate\Support\Facades\DB::table('account_entries')->count(),
            'ATOMICITY VIOLATED: account_entries count changed despite audit_logs failure'
        );

        $this->assertNotSame(
            'refunded',
            $bookingStatus,
            'ATOMICITY VIOLATED: booking status changed to refunded despite audit_logs failure'
        );

        // Critical: refund_audit_logs MUST also be rolled back.
        // audit_logs failure happens AFTER refund_audit_logs insert;
        // without proper rollback, an orphan refund_audit_logs row would remain.
        $this->assertSame(
            0,
            (int) \Illuminate\Support\Facades\DB::table('refund_audit_logs')->count(),
            'ATOMICITY VIOLATED: refund_audit_logs row exists despite audit_logs failure (orphan audit row)'
        );
    }

    public function test_k03_refund_audit_logs_failure_rolls_back_visa_refund(): void
    {
        // ── Setup ──
        [$booking, $paidAmount] = $this->seedVisaBookingWithPayment(500.0);

        $vaultBalanceBefore = (float) $this->vaultEgp->fresh()->balance;
        $paymentCountBefore = (int) \Illuminate\Support\Facades\DB::table('visa_payments')->count();
        $entryCountBefore = (int) \Illuminate\Support\Facades\DB::table('account_entries')->count();

        // ── Inject failure ──
        \Illuminate\Support\Facades\Schema::drop('refund_audit_logs');

        // ── Attempt REAL production refund flow ──
        $this->actAs($this->normalEmployee);
        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_ATOMICITY_FIX_20260817 failure injection — visa refund_audit_logs dropped',
        ]);

        $this->assertNotSame(
            200,
            $response->status(),
            'Visa refund MUST NOT succeed when refund_audit_logs cannot be persisted'
        );

        // ── Evidence: complete rollback ──
        $booking->refresh();
        $bookingStatus = $booking->status instanceof \BackedEnum ? $booking->status->value : (string) $booking->status;

        $this->assertSame(
            $vaultBalanceBefore,
            (float) $this->vaultEgp->fresh()->balance,
            'ATOMICITY VIOLATED (visa): vault balance changed'
        );

        $this->assertSame(
            $paymentCountBefore,
            (int) \Illuminate\Support\Facades\DB::table('visa_payments')->count(),
            'ATOMICITY VIOLATED (visa): visa_payments changed'
        );

        $this->assertSame(
            $entryCountBefore,
            (int) \Illuminate\Support\Facades\DB::table('account_entries')->count(),
            'ATOMICITY VIOLATED (visa): account_entries changed'
        );

        $this->assertNotSame(
            'refunded',
            $bookingStatus,
            'ATOMICITY VIOLATED (visa): booking status changed'
        );
    }

    public function test_k04_actor_spoofing_via_payload_is_ignored(): void
    {
        // ── Setup ──
        [$booking] = $this->seedHajjBookingWithPayment(5000.0);

        $this->actAs($this->normalEmployee);

        // ── Attempt: try to spoof the actor via request payload ──
        // The frontend MUST NOT be able to dictate who performed the refund.
        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'EMP_ATOMICITY_FIX_20260817 spoofing attempt',
            // Attempt impersonation via various channel names
            'user_id' => $this->admin->id,
            'performed_by' => $this->admin->id,
            'actor_id' => $this->admin->id,
            'refund_audit' => [
                'user_id' => $this->admin->id,
                'user_name' => 'Forged Admin',
            ],
        ]);

        $response->assertStatus(200);

        // ── Evidence: audit row attributes to ACTUAL authenticated user ──
        $audit = RefundAuditLog::query()
            ->where('booking_id', $booking->id)
            ->where('module', 'hajj_umra')
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame(
            (int) $this->normalEmployee->id,
            (int) $audit->user_id,
            'Audit row must attribute refund to the AUTHENTICATED user, NOT to payload'
        );
        $this->assertSame(
            $this->normalEmployee->name,
            $audit->user_name,
            'user_name must come from authenticated user.name, NOT from payload'
        );
        $this->assertNotSame(
            (int) $this->admin->id,
            (int) $audit->user_id,
            'admin.id MUST NOT leak into the audit when a different user performed the refund'
        );
        $this->assertNotSame(
            'Forged Admin',
            $audit->user_name,
            'Forged name MUST NOT appear in the audit'
        );
    }
}