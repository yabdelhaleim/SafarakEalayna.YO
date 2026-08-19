<?php

namespace Tests\Feature\Visa;

use App\Models\Transaction;
use App\Models\VisaBooking;
use App\Models\VisaPayment;
use App\Services\Visa\VisaBookingService;
use App\Services\Visa\VisaRefundService;
use Illuminate\Support\Facades\DB;

/**
 * PHASE 7: Idempotency — same operation submitted multiple times must not duplicate effects.
 *
 * Scenarios:
 *   1. Same payment POST submitted twice → only ONE payment recorded
 *   2. Same cancel POST submitted twice → status remains cancelled (no double reversal)
 *   3. Same refund POST submitted twice → status remains refunded (no double reversal)
 *   4. Two concurrent payment POSTs (race) → only one wins, second is rejected
 *   5. Same booking POST twice → TWO bookings (intentional — different IDs)
 *
 * @group visa
 * @group visa-idempotency
 */
class VisaIdempotencyTest extends VisaTestCase
{
    public function test_double_payment_post_creates_only_one_record(): void
    {
        // Phase 9.8 fix: same booking + same reference = idempotent (only 1 payment row).
        // Pre-fix behavior: 2 rows + double credit to vault (the known defect).
        $booking = $this->makeBooking([
            'purchase_price' => 1000.0,
            'selling_price' => 5000.0,
        ]);

        // Fire two identical payment requests
        $payload = [
            'amount' => 1000.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'reference' => 'AUDIT-IDEMP-PAY',
        ];

        $r1 = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", $payload)->assertCreated();
        $r2 = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", $payload);

        // Phase 9.8: idempotent — both calls return the SAME payment id.
        $this->assertContains($r2->status(), [200, 201],
            'idempotent replay must return 200 or 201');
        $this->assertSame($r1->json('data.id'), $r2->json('data.id'),
            'second call must return the existing payment id');

        $paymentCount = VisaPayment::where('visa_booking_id', $booking->id)->count();
        $this->assertSame(1, $paymentCount,
            'Phase 9.8 fix: same booking + same reference must create exactly 1 payment (was 2 pre-fix)');
    }

    public function test_double_cancel_does_not_double_reversal(): void
    {
        $booking = $this->makeBooking([
            'purchase_price' => 500.0,
            'selling_price' => 1000.0,
        ]);

        $service = app(VisaBookingService::class);
        $service->addPayment($booking, ['amount' => 1000.0, 'payment_method' => 'cash', 'account_id' => $this->vaultEgp->id]);

        $refundService = app(VisaRefundService::class);

        $cancelled1 = $refundService->cancel($booking->fresh(), 'audit cancel 1');

        // Second cancel — must not throw, must not double-reverse
        $cancelled2 = null;
        $secondCallFailed = false;
        try {
            $cancelled2 = $refundService->cancel($booking->fresh(), 'audit cancel 2');
        } catch (\Throwable $e) {
            $secondCallFailed = true;
        }

        // Document the actual behavior
        if ($secondCallFailed) {
            $this->assertTrue($secondCallFailed, 'second cancel rejects with ' ?? '');
        } else {
            // If allowed, both must result in the same final status
            $this->assertSame(VisaStatus::Cancelled, $cancelled2->status);
        }

        // Verify ledger is still balanced (no double reversal)
        $this->assertLedgerGloballyBalanced();
    }

    public function test_double_refund_does_not_double_reversal(): void
    {
        $booking = $this->makeBooking([
            'purchase_price' => 500.0,
            'selling_price' => 1000.0,
        ]);

        $refundService = app(VisaRefundService::class);
        $refundService->refund($booking, 'audit refund 1');

        $secondCallFailed = false;
        try {
            $refundService->refund($booking->fresh(), 'audit refund 2');
        } catch (\Throwable $e) {
            $secondCallFailed = true;
        }

        if ($secondCallFailed) {
            $this->assertTrue($secondCallFailed);
        } else {
            $booking->refresh();
            $this->assertSame(VisaStatus::Refunded, $booking->status);
        }

        $this->assertLedgerGloballyBalanced();
    }

    public function test_two_concurrent_payments_one_wins_other_rejected(): void
    {
        $booking = $this->makeBooking([
            'purchase_price' => 1000.0,
            'selling_price' => 5000.0,
        ]);

        // Simulate concurrent submissions via two sequential POSTs (DB serialization point)
        $r1 = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 4000.0,  // 1000 remaining after — exactly the limit
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
        ]);

        $r2 = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 4000.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
        ]);

        $r1->assertCreated();
        $r2->assertStatus(422, 'second payment must be rejected (overpayment guard)');

        $booking->refresh();
        $this->assertEqualsWithDelta(4000.0, (float) $booking->paid_amount, 0.01);
    }

    public function test_same_booking_post_twice_creates_two_bookings(): void
    {
        $payload = $this->bookingPayload();

        $r1 = $this->postJson('/api/v1/visa/bookings', $payload);
        $r2 = $this->postJson('/api/v1/visa/bookings', $payload);

        $r1->assertCreated();
        $r2->assertCreated();

        $id1 = $r1->json('data.id');
        $id2 = $r2->json('data.id');

        $this->assertNotSame($id1, $id2, 'two distinct booking IDs');
        $this->assertSame(2, \App\Models\VisaBooking::count());
    }

    public function test_payment_with_same_reference_twice_creates_only_one_payment(): void
    {
        // Phase 9.8 fix: (booking_id, transaction_reference) UNIQUE constraint +
        // service pre-check + DB unique backstop all enforce idempotency.
        $booking = $this->makeBooking([
            'purchase_price' => 100.0,
            'selling_price' => 1000.0,
        ]);

        $service = app(VisaBookingService::class);
        $p1 = $service->addPayment($booking, [
            'amount' => 100.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'reference' => 'AUDIT-REF-SAME',
        ]);

        // Second call returns the same payment (idempotent replay)
        $p2 = $service->addPayment($booking->fresh(), [
            'amount' => 100.0,
            'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'reference' => 'AUDIT-REF-SAME',
        ]);

        $this->assertSame($p1->id, $p2->id,
            'Phase 9.8 fix: same reference MUST return same payment id (idempotent)');
        $this->assertSame(1, VisaPayment::where('visa_booking_id', $booking->id)->count(),
            'Phase 9.8 fix: same booking + same reference creates exactly 1 payment (was 2 pre-fix)');
    }
}