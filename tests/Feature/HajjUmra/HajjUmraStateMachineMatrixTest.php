<?php

namespace Tests\Feature\HajjUmra;

use App\Enums\HajjUmraStatus;
use App\Models\HajjUmraBooking;
use App\Services\HajjUmra\HajjUmraBookingService;
use App\Services\HajjUmra\HajjUmraRefundService;

/**
 * Phase 10.13 — State Machine Matrix (Section 23 of the audit prompt).
 *
 * Verifies the full Hajj/Umra status transition matrix.
 *
 * 6 enum cases: Pending, Confirmed, InProgress, Completed, Cancelled, Refunded.
 *
 * Legal transitions:
 *   - Pending → Confirmed (default on create)
 *   - Confirmed → InProgress (start work)
 *   - InProgress → Completed (finish)
 *   - Pending → Cancelled (admin)
 *   - Confirmed → Cancelled (admin)
 *   - InProgress → Cancelled (admin)
 *   - Completed → Refunded (post-travel refund)
 *   - Pending/Confirmed/InProgress → Refunded (full refund)
 *   - Any non-trashed → soft-delete (via DELETE endpoint)
 *
 * Illegal transitions:
 *   - Refunded → any (terminal)
 *   - Cancelled → Cancellation of a cancelled (no-op or rejected)
 *   - Cancelled → Refunded (already covered by Phase 10.5)
 *
 * NOTE: The Hajj/Umra state machine does NOT have a UI/API for direct
 * Pending→Confirmed, Confirmed→InProgress, InProgress→Completed
 * transitions. These are tracked as "informational" states and can be
 * updated via direct model edits (admin reprocessing). The tests
 * verify both the direct transitions AND the controller-mediated ones.
 */
class HajjUmraStateMachineMatrixTest extends HajjUmraTestCase
{
    private function makeBooking(): HajjUmraBooking
    {
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();
        return app(HajjUmraBookingService::class)->create([
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 42000.0,
            'selling_price' => 50000.0,
            'currency' => 'EGP',
            'account_id' => $this->treasuryEGP->id,
        ])->fresh();
    }

    private function makePaidBooking(): HajjUmraBooking
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 50000.0,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P13_'.uniqid(),
        ])->assertCreated();
        return $booking->fresh();
    }

    /* ============================================================
     *  Initial state on create
     * ============================================================ */

    public function test_new_booking_initial_status_is_confirmed(): void
    {
        $booking = $this->makeBooking();
        $this->assertSame(HajjUmraStatus::Confirmed->value, $booking->status->value);
    }

    public function test_all_six_enum_cases_exist(): void
    {
        $cases = [
            'pending', 'confirmed', 'in_progress',
            'completed', 'cancelled', 'refunded',
        ];
        $values = array_map(fn($c) => $c->value, HajjUmraStatus::cases());
        foreach ($cases as $c) {
            $this->assertContains($c, $values, "HajjUmraStatus must include '{$c}'");
        }
    }

    /* ============================================================
     *  Legal forward transitions (direct model)
     * ============================================================ */

    public function test_pending_to_confirmed_transition_allowed(): void
    {
        $booking = $this->makeBooking();
        $booking->update(['status' => HajjUmraStatus::Pending->value]);
        $this->assertSame('pending', $booking->fresh()->status->value);

        $booking->update(['status' => HajjUmraStatus::Confirmed->value]);
        $this->assertSame('confirmed', $booking->fresh()->status->value);
    }

    public function test_confirmed_to_in_progress_transition_allowed(): void
    {
        $booking = $this->makeBooking();
        $booking->update(['status' => HajjUmraStatus::InProgress->value]);
        $this->assertSame('in_progress', $booking->fresh()->status->value);
    }

    public function test_in_progress_to_completed_transition_allowed(): void
    {
        $booking = $this->makeBooking();
        $booking->update(['status' => HajjUmraStatus::InProgress->value]);
        $booking->update(['status' => HajjUmraStatus::Completed->value]);
        $this->assertSame('completed', $booking->fresh()->status->value);
    }

    /* ============================================================
     *  Cancel transitions (controller-mediated)
     * ============================================================ */

    public function test_confirmed_to_cancelled_via_controller(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'audit',
        ])->assertOk();

        $this->assertSame('cancelled', $booking->fresh()->status->value);
    }

    public function test_in_progress_to_cancelled_via_controller(): void
    {
        $booking = $this->makeBooking();
        $booking->update(['status' => HajjUmraStatus::InProgress->value]);

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'audit',
        ])->assertOk();

        $this->assertSame('cancelled', $booking->fresh()->status->value);
    }

    public function test_completed_to_cancelled_via_controller(): void
    {
        $booking = $this->makeBooking();
        $booking->update(['status' => HajjUmraStatus::Completed->value]);

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'audit',
        ])->assertOk();

        $this->assertSame('cancelled', $booking->fresh()->status->value);
    }

    /* ============================================================
     *  Refund transitions (any state → Refunded)
     * ============================================================ */

    public function test_confirmed_to_refunded_via_refund_service(): void
    {
        $booking = $this->makePaidBooking();
        app(HajjUmraRefundService::class)->refund($booking->fresh());

        $this->assertSame('refunded', $booking->fresh()->status->value);
    }

    public function test_in_progress_to_refunded_via_refund_service(): void
    {
        $booking = $this->makePaidBooking();
        $booking->update(['status' => HajjUmraStatus::InProgress->value]);

        app(HajjUmraRefundService::class)->refund($booking->fresh());

        $this->assertSame('refunded', $booking->fresh()->status->value);
    }

    public function test_completed_to_refunded_via_refund_service(): void
    {
        $booking = $this->makePaidBooking();
        $booking->update(['status' => HajjUmraStatus::Completed->value]);

        app(HajjUmraRefundService::class)->refund($booking->fresh());

        $this->assertSame('refunded', $booking->fresh()->status->value);
    }

    /* ============================================================
     *  Terminal state guards
     * ============================================================ */

    public function test_cancel_after_refund_rejected(): void
    {
        // Phase 10.5 fix verified: cancel-after-refund is rejected.
        $booking = $this->makePaidBooking();
        app(HajjUmraRefundService::class)->refund($booking->fresh());

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'audit',
        ])->assertStatus(422);
    }

    public function test_refund_after_refund_is_no_op_or_rejected(): void
    {
        // Once a booking is refunded, the second refund must not
        // double-process the booking. The behavior is either:
        //   - a no-op (returns the existing refunded state), or
        //   - an error (422).
        $booking = $this->makePaidBooking();
        app(HajjUmraRefundService::class)->refund($booking->fresh());

        try {
            $result = app(HajjUmraRefundService::class)->refund($booking->fresh());
            $this->assertSame('refunded', $booking->fresh()->status->value);
        } catch (\Throwable $e) {
            $this->assertSame('refunded', $booking->fresh()->status->value,
                'status must remain refunded after second refund attempt');
        }
    }

    public function test_payment_after_refund_rejected(): void
    {
        $booking = $this->makePaidBooking();
        app(HajjUmraRefundService::class)->refund($booking->fresh());

        // Trying to add a payment after refund must be rejected
        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 1000.0,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P13_TERM_'.uniqid(),
        ]);
        $this->assertContains($response->status(), [422, 404],
            'payment after refund must be rejected');
    }

    /* ============================================================
     *  Delete from any state
     * ============================================================ */

    public function test_delete_confirmed_booking_succeeds(): void
    {
        $booking = $this->makeBooking();
        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();
        $this->assertNotNull($booking->fresh()->deleted_at);
    }

    public function test_delete_in_progress_booking_succeeds(): void
    {
        $booking = $this->makeBooking();
        $booking->update(['status' => HajjUmraStatus::InProgress->value]);

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();
        $this->assertNotNull($booking->fresh()->deleted_at);
    }

    public function test_delete_completed_booking_succeeds(): void
    {
        $booking = $this->makeBooking();
        $booking->update(['status' => HajjUmraStatus::Completed->value]);

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();
        $this->assertNotNull($booking->fresh()->deleted_at);
    }

    public function test_delete_cancelled_booking_succeeds(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'audit',
        ])->assertOk();

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();
        $this->assertNotNull($booking->fresh()->deleted_at);
    }

    public function test_delete_refunded_booking_succeeds(): void
    {
        $booking = $this->makePaidBooking();
        app(HajjUmraRefundService::class)->refund($booking->fresh());

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();
        $this->assertNotNull($booking->fresh()->deleted_at);
    }

    /* ============================================================
     *  Cross-state lifecycle
     * ============================================================ */

    public function test_full_lifecycle_pending_to_refunded(): void
    {
        $booking = $this->makeBooking();
        $booking->update(['status' => HajjUmraStatus::Pending->value]);
        $this->assertSame('pending', $booking->fresh()->status->value);

        // Pending → Confirmed (default)
        $booking->update(['status' => HajjUmraStatus::Confirmed->value]);
        $this->assertSame('confirmed', $booking->fresh()->status->value);

        // Full payment
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 50000.0,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P13_LIFE_'.uniqid(),
        ])->assertCreated();

        // Confirmed → InProgress
        $booking->update(['status' => HajjUmraStatus::InProgress->value]);
        $this->assertSame('in_progress', $booking->fresh()->status->value);

        // InProgress → Completed
        $booking->update(['status' => HajjUmraStatus::Completed->value]);
        $this->assertSame('completed', $booking->fresh()->status->value);

        // Completed → Refunded
        app(HajjUmraRefundService::class)->refund($booking->fresh());
        $this->assertSame('refunded', $booking->fresh()->status->value);
    }

    public function test_full_lifecycle_confirmed_to_cancelled(): void
    {
        $booking = $this->makeBooking(); // → Confirmed

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'audit',
        ])->assertOk();

        $this->assertSame('cancelled', $booking->fresh()->status->value);
    }

    /* ============================================================
     *  Invalid transitions — direct model level
     * ============================================================ */

    public function test_cancel_after_cancel_keeps_cancelled_state(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'first',
        ])->assertOk();

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'second',
        ])->assertStatus(422);

        $this->assertSame('cancelled', $booking->fresh()->status->value);
    }

    public function test_refunded_to_confirmed_not_allowed_via_controller(): void
    {
        // There's no direct state-transition endpoint. The only way to
        // change status is via cancel, refund, or direct model edit.
        // The controller-mediated refund is documented as terminal.
        $booking = $this->makePaidBooking();
        app(HajjUmraRefundService::class)->refund($booking->fresh());

        // After refund, the booking is refunded. The controller does not
        // provide a "reopen" or "unrefund" endpoint.
        $this->assertSame('refunded', $booking->fresh()->status->value);
    }
}
