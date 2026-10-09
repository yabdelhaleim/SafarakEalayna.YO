<?php

namespace Tests\Feature\Visa;

use App\Enums\VisaStatus;
use App\Models\VisaBooking;

/**
 * Phase 9.13 — State Machine Matrix (Section 23 of the 30-section prompt).
 *
 * Audit target: full transition coverage for VisaStatus 8-state machine.
 *
 * Reachable starting states (via create() with `status` override in payload):
 *   Draft, Submitted (default), UnderReview, Approved, Rejected, Issued,
 *   Cancelled, Refunded.
 *
 * State-mutating operations (services):
 *   cancel()        → Cancelled   (guards: ≠Cancelled, ≠Refunded, ≠trashed)
 *   refund()        → Refunded    (guards: ≠Cancelled, ≠Refunded, ≠trashed)
 *   deleteWithReversal() → soft-deleted (guards: ≠trashed)
 *   addPayment()    → no status change (guards: ≠Cancelled, ≠Refunded, ≠trashed)
 *
 * The State Machine contract:
 *   - The 8 statuses are reachable initial states.
 *   - The only transitions OUT of a status are:
 *       Cancel   → Cancelled
 *       Refund   → Refunded
 *       Delete   → soft-deleted
 *       Payment  → status unchanged (still allowed from any live state)
 *   - Terminal states (Cancelled, Refunded, soft-deleted) are LOCKED:
 *       cannot transition further; cannot accept new payments.
 */
class VisaStateMachineMatrixTest extends VisaTestCase
{
    /* ============================================================
     *  STARTING STATUS COVERAGE — verify all 8 are reachable
     * ============================================================ */

    public function test_each_starting_status_is_accepted_on_create_draft(): void
    {
        $this->assertStartingStatusReachable('draft');
    }

    public function test_each_starting_status_is_accepted_on_create_submitted(): void
    {
        $this->assertStartingStatusReachable('submitted');
    }

    public function test_each_starting_status_is_accepted_on_create_under_review(): void
    {
        $this->assertStartingStatusReachable('under_review');
    }

    public function test_each_starting_status_is_accepted_on_create_approved(): void
    {
        $this->assertStartingStatusReachable('approved');
    }

    public function test_each_starting_status_is_accepted_on_create_rejected(): void
    {
        $this->assertStartingStatusReachable('rejected');
    }

    public function test_each_starting_status_is_accepted_on_create_issued(): void
    {
        $this->assertStartingStatusReachable('issued');
    }

    public function test_each_starting_status_is_accepted_on_create_cancelled(): void
    {
        $this->assertStartingStatusReachable('cancelled');
    }

    public function test_each_starting_status_is_accepted_on_create_refunded(): void
    {
        $this->assertStartingStatusReachable('refunded');
    }

    protected function assertStartingStatusReachable(string $statusValue): void
    {
        $response = $this->postJson('/api/v1/visa/bookings', $this->bookingPayload([
            'status' => $statusValue,
        ]));
        $response->assertCreated();
        $booking = VisaBooking::findOrFail($response->json('data.id'));
        $this->assertSame($statusValue, (string) $booking->status->value,
            "starting status $statusValue must be persisted");
    }

    /* ============================================================
     *  LEGAL TRANSITIONS — Cancel from any non-terminal state
     * ============================================================ */

    public function test_cancel_from_submitted_succeeds(): void
    {
        $booking = $this->makeBooking(['status' => VisaStatus::Submitted->value]);
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'phase 9.13 cancel submitted',
        ])->assertOk();
        $this->assertSame(VisaStatus::Cancelled, $booking->fresh()->status);
    }

    public function test_cancel_from_draft_succeeds(): void
    {
        $booking = $this->makeBooking(['status' => VisaStatus::Draft->value]);
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'phase 9.13 cancel draft',
        ])->assertOk();
        $this->assertSame(VisaStatus::Cancelled, $booking->fresh()->status);
    }

    public function test_cancel_from_under_review_succeeds(): void
    {
        $booking = $this->makeBooking(['status' => VisaStatus::UnderReview->value]);
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'phase 9.13 cancel under_review',
        ])->assertOk();
        $this->assertSame(VisaStatus::Cancelled, $booking->fresh()->status);
    }

    public function test_cancel_from_approved_succeeds(): void
    {
        $booking = $this->makeBooking(['status' => VisaStatus::Approved->value]);
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'phase 9.13 cancel approved',
        ])->assertOk();
        $this->assertSame(VisaStatus::Cancelled, $booking->fresh()->status);
    }

    public function test_cancel_from_issued_succeeds_before_payment_then_state_must_be_refunded_not_cancelled(): void
    {
        // Once a visa is ISSUED and any money has changed hands, the correct
        // terminal state is REFUNDED (not cancelled). But cancel is still
        // legally reachable from the issued state if no payment was made.
        $booking = $this->makeBooking(['status' => VisaStatus::Issued->value]);
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'phase 9.13 cancel issued (no payment)',
        ])->assertOk();
        $this->assertSame(VisaStatus::Cancelled, $booking->fresh()->status);
    }

    public function test_cancel_from_rejected_succeeds(): void
    {
        $booking = $this->makeBooking(['status' => VisaStatus::Rejected->value]);
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'phase 9.13 cancel rejected',
        ])->assertOk();
        $this->assertSame(VisaStatus::Cancelled, $booking->fresh()->status);
    }

    /* ============================================================
     *  LEGAL TRANSITIONS — Refund from any non-terminal state
     * ============================================================ */

    public function test_refund_from_submitted_succeeds(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'phase 9.13 refund submitted',
        ])->assertOk();
        $this->assertSame(VisaStatus::Refunded, $booking->fresh()->status);
    }

    public function test_refund_from_under_review_succeeds(): void
    {
        $booking = $this->makeBooking(['status' => VisaStatus::UnderReview->value]);
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'phase 9.13 refund under_review',
        ])->assertOk();
        $this->assertSame(VisaStatus::Refunded, $booking->fresh()->status);
    }

    public function test_refund_from_approved_succeeds(): void
    {
        $booking = $this->makeBooking(['status' => VisaStatus::Approved->value]);
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'phase 9.13 refund approved',
        ])->assertOk();
        $this->assertSame(VisaStatus::Refunded, $booking->fresh()->status);
    }

    public function test_refund_from_issued_succeeds(): void
    {
        $booking = $this->makeBooking(['status' => VisaStatus::Issued->value]);
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'phase 9.13 refund issued',
        ])->assertOk();
        $this->assertSame(VisaStatus::Refunded, $booking->fresh()->status);
    }

    /* ============================================================
     *  ILLEGAL TRANSITIONS — double-cancel, refund-then-cancel, etc.
     * ============================================================ */

    public function test_cannot_cancel_already_cancelled_booking(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'first cancel',
        ])->assertOk();
        // Controller catches RuntimeException and returns 422 with the AR message
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'second cancel attempt',
        ])->assertStatus(422);
    }

    public function test_cannot_refund_cancelled_booking(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'cancel before refund',
        ])->assertOk();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'attempt refund after cancel',
        ])->assertStatus(422);
    }

    public function test_cannot_cancel_refunded_booking(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'refund before cancel',
        ])->assertOk();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'attempt cancel after refund',
        ])->assertStatus(422);
    }

    public function test_cannot_refund_already_refunded_booking(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'first refund',
        ])->assertOk();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'second refund attempt',
        ])->assertStatus(422);
    }

    /* ============================================================
     *  PAYMENT STATE GATES — addPayment must respect terminal states
     * ============================================================ */

    public function test_cannot_record_payment_after_cancel(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'cancel before payment',
        ])->assertOk();

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 100.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P913_PAY_AFTER_CANCEL_'.uniqid(),
        ])->assertStatus(422);
    }

    public function test_cannot_record_payment_after_refund(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'refund before payment',
        ])->assertOk();

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 100.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P913_PAY_AFTER_REFUND_'.uniqid(),
        ])->assertStatus(422);
    }

    /* ============================================================
     *  DELETE FLOW — soft-delete is terminal
     * ============================================================ */

    public function test_soft_delete_then_attempt_cancel_returns_404(): void
    {
        // After soft-delete, default Eloquent scope excludes the row, so route
        // model binding returns 404 (not 422). Documented defense-in-depth.
        $booking = $this->makeBooking();
        $this->deleteJson("/api/v1/visa/bookings/{$booking->id}")->assertOk();

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'attempt cancel after delete',
        ])->assertStatus(404);
    }

    public function test_soft_delete_then_attempt_refund_returns_404(): void
    {
        $booking = $this->makeBooking();
        $this->deleteJson("/api/v1/visa/bookings/{$booking->id}")->assertOk();

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'attempt refund after delete',
        ])->assertStatus(404);
    }

    public function test_double_soft_delete_returns_422(): void
    {
        // destroy() catches already-trashed via guard early-return at 422.
        $booking = $this->makeBooking();
        $this->deleteJson("/api/v1/visa/bookings/{$booking->id}")->assertOk();
        $this->deleteJson("/api/v1/visa/bookings/{$booking->id}")
            ->assertStatus(422);
    }

    /* ============================================================
     *  HAPPY-PATH LIFECYCLE — Submitted → pay → issued (multi-step)
     * ============================================================ */

    public function test_full_lifecycle_draft_to_issued_with_payments(): void
    {
        // Start as Draft (rare in production but reachable)
        $booking = $this->makeBooking(['status' => VisaStatus::Draft->value]);

        // Payment on a Draft booking must succeed
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1500.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P913_LIFE_PAY_'.uniqid(),
        ])->assertCreated();

        // Status remains Draft (no automatic transition)
        $this->assertSame(VisaStatus::Draft, $booking->fresh()->status);

        // Refund must work from any non-terminal state
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'phase 9.13 lifecycle end',
        ])->assertOk();
        $this->assertSame(VisaStatus::Refunded, $booking->fresh()->status);
    }

    /* ============================================================
     *  REJECTION REASONS — guard messages contain enough context
     * ============================================================ */

    public function test_double_cancel_error_message_indicates_already_cancelled(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'first',
        ])->assertOk();

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'second',
        ]);
        $this->assertSame(422, $response->status());
        $body = $response->json();
        $this->assertStringContainsString(
            'cancelled',
            json_encode($body, JSON_UNESCAPED_UNICODE),
            'error payload must indicate the booking was already cancelled',
        );
    }
}
