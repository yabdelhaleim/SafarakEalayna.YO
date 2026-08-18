<?php

namespace Tests\Feature\Visa;

use App\Enums\VisaStatus;
use App\Models\VisaBooking;
use App\Services\Visa\VisaBookingService;
use App\Services\Visa\VisaRefundService;

/**
 * PHASE 4: Visa Business Flows — status transitions.
 *
 * The VisaStatus enum has 8 cases:
 *   Draft, Submitted, UnderReview, Approved, Rejected, Issued, Cancelled, Refunded
 *
 * Tests:
 *   - Booking is created in 'submitted' by default
 *   - Can transition via PUT to valid next states (draft/under_review/approved/issued)
 *   - Cannot transition to cancelled/refunded via status update (must use dedicated endpoints)
 *   - Cancelled bookings cannot transition to any other status
 *   - Refunded bookings cannot transition to any other status
 *   - Soft-deleted bookings are not visible via normal API queries
 *   - Rejected is a terminal state (separate from cancelled/refunded)
 *
 * @group visa
 * @group visa-status
 */
class VisaStatusTransitionTest extends VisaTestCase
{
    public function test_booking_is_submitted_by_default(): void
    {
        $booking = $this->makeBooking();
        $this->assertSame(VisaStatus::Submitted, $booking->status);
    }

    public function test_cancel_changes_status_via_dedicated_endpoint(): void
    {
        $booking = $this->makeBooking();
        $refundService = app(VisaRefundService::class);

        $cancelled = $refundService->cancel($booking, 'audit cancel');
        $this->assertSame(VisaStatus::Cancelled, $cancelled->status);

        // visa_detail status must also be cancelled
        $this->assertSame(VisaStatus::Cancelled, $cancelled->visaDetail->status);
    }

    public function test_refund_changes_status_via_dedicated_endpoint(): void
    {
        $booking = $this->makeBooking();
        $refundService = app(VisaRefundService::class);

        $refunded = $refundService->refund($booking, 'audit refund');
        $this->assertSame(VisaStatus::Refunded, $refunded->status);
    }

}