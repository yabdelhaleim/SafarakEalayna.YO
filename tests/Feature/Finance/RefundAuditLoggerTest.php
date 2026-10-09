<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\RefundAuditLog;
use App\Models\User;
use App\Services\Finance\RefundAuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase 5 (B-5 fix) — RefundAuditLogger writes BOTH polymorphic pairs.
 *
 * Background:
 *   The `audit_logs` table historically used `model_type` + `model_id`
 *   (legacy polymorphic FK). The rest of the codebase — notably
 *   `transactions` — uses `related_type` + `related_id`. To unify the
 *   naming, Phase 5 adds `related_type` + `related_id` to `audit_logs`
 *   AND updates RefundAuditLogger to write both pairs for new rows.
 *
 *   This test verifies the logger writes all 4 columns with consistent
 *   values (related_type === model_type, related_id === model_id).
 *
 * @see \App\Services\Finance\RefundAuditLogger
 * @see database/migrations/2026_08_19_120000_add_related_columns_to_audit_logs_table.php
 */
class RefundAuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name' => 'Phase5 Tester',
            'email' => 'phase5@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->customer = Customer::query()->create([
            'full_name' => 'Phase5 Customer',
            'phone' => '01000000050',
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * ✅ The mandatory `audit_logs` row writes BOTH `model_type`/`model_id`
     *    AND `related_type`/`related_id` with identical values. The legacy
     *    columns stay for backward compatibility; the new columns align
     *    with the rest of the codebase.
     */
    public function test_refund_audit_log_writes_both_polymorphic_pairs(): void
    {
        Auth::login($this->admin);

        // Capture the count BEFORE — there should be NO audit_logs row yet.
        $auditBefore = AuditLog::query()->count();
        $this->assertEquals(0, $auditBefore, 'No audit_logs row should exist before the refund is logged');

        // Trigger a refund.processed event.
        $refundLog = RefundAuditLogger::log('refund.processed', [
            'module' => 'flight',
            'booking_id' => 9999, // synthetic — no FK so the test stays isolated
            'booking_reference' => 'PHASE5-TEST-001',
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->full_name,
            'refund_amount' => 250.0,
            'currency' => 'EGP',
            'paid_amount_before' => 1000.0,
            'previously_refunded' => 0.0,
            'remaining_refundable' => 1000.0,
            'reason' => 'Phase 5 B-5 test',
        ]);

        // ── 1) The dedicated refund_audit_logs row was written ──
        $this->assertNotNull($refundLog->id);
        $this->assertEquals(250.0, (float) $refundLog->refund_amount);
        $this->assertEquals('flight', $refundLog->module);

        // ── 2) The generic audit_logs row was written ──
        $auditRows = AuditLog::query()->get();
        $this->assertCount(1, $auditRows, 'Exactly one audit_logs row should be created');

        $row = $auditRows->first();

        // ── 3) Legacy polymorphic pair is set (model_type + model_id) ──
        $this->assertNotNull($row->model_type, 'model_type must be set (legacy column)');
        $this->assertEquals(9999, $row->model_id, 'model_id must match booking_id (legacy column)');

        // ── 4) New polymorphic pair is set (related_type + related_id) ──
        $this->assertNotNull($row->related_type, 'related_type must be set (Phase 5 B-5)');
        $this->assertEquals(9999, $row->related_id, 'related_id must match booking_id (Phase 5 B-5)');

        // ── 5) The two pairs are CONSISTENT (same values) ──
        $this->assertEquals($row->model_type, $row->related_type, 'related_type must mirror model_type');
        $this->assertEquals($row->model_id, $row->related_id, 'related_id must mirror model_id');
    }

    /**
     * ✅ When `booking_id` is null in the params, BOTH columns are null —
     *    no spurious default values are inserted.
     */
    public function test_refund_audit_log_handles_null_booking_id(): void
    {
        Auth::login($this->admin);

        RefundAuditLogger::log('refund.processed', [
            'module' => 'hajj_umra',
            // booking_id intentionally OMITTED
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->full_name,
            'refund_amount' => 100.0,
            'currency' => 'EGP',
            'reason' => 'null booking_id test',
        ]);

        $row = AuditLog::query()->latest('id')->first();
        $this->assertNotNull($row);

        // Both model_id AND related_id must be null when no booking_id is supplied.
        $this->assertNull($row->model_id);
        $this->assertNull($row->related_id);

        // The model_type/related_type should still resolve to HajjUmra.
        $this->assertStringContainsString('HajjUmra', $row->model_type);
        $this->assertEquals($row->model_type, $row->related_type);
    }

    /**
     * ✅ Different modules produce different model_type/related_type values
     *    — the resolveModelType() switch works for both pairs.
     */
    public function test_resolve_model_type_covers_all_three_modules(): void
    {
        Auth::login($this->admin);

        foreach (['flight', 'hajj_umra', 'visa'] as $module) {
            RefundAuditLogger::log('refund.processed', [
                'module' => $module,
                'booking_id' => 1,
                'customer_id' => $this->customer->id,
                'customer_name' => $this->customer->full_name,
                'refund_amount' => 10.0,
                'currency' => 'EGP',
            ]);
        }

        $rows = AuditLog::query()->orderBy('id')->get();
        $this->assertCount(3, $rows);

        $expectedTypes = [
            'App\\Models\\Flight\\FlightBooking',
            'App\\Models\\HajjUmraBooking',
            'App\\Models\\VisaBooking',
        ];

        foreach ($rows as $i => $row) {
            $this->assertEquals($expectedTypes[$i], $row->model_type, "row $i model_type");
            $this->assertEquals($expectedTypes[$i], $row->related_type, "row $i related_type");
            $this->assertEquals(1, $row->model_id, "row $i model_id");
            $this->assertEquals(1, $row->related_id, "row $i related_id");
        }
    }
}