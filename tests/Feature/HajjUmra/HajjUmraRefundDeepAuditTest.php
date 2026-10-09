<?php

namespace Tests\Feature\HajjUmra;

use App\Enums\HajjUmraStatus;
use App\Models\AccountEntry;
use App\Models\HajjUmraBooking;

/**
 * Phase 10.4 — Refund Deep Audit (Section 8 of the 30-section prompt, applied
 * independently to the Hajj/Umra module).
 *
 * Audit target: POST /api/v1/hajj-umra/bookings/{id}/refund
 *
 * Per audit spec:
 *   - Full refund only (Hajj/Umra has no partial refund endpoint — unlike
 *     generic payments, a refund returns the full booking amount).
 *   - Refund amount = min(intended, paid). Cannot return money that was
 *     never received.
 *   - On successful refund, the booking status flips to Refunded; income +
 *     expense + payment transactions are reversed (additive pattern).
 *   - Refund failure (audit or financial) → DB rollback.
 *
 * Coverage:
 *   - State transitions: unpaid, partial, full
 *   - Financial invariants: customer AR, vault NET, income/expense
 *   - State machine: double-refund, refund-after-cancel, refund-after-delete
 *   - Audit trail: notes updated with reason
 *   - Edge cases: 0-payment refund (Phase 8.6 Gate), partial-pay refund
 */
class HajjUmraRefundDeepAuditTest extends HajjUmraTestCase
{
    /* ============================================================
     *  REFUND — Basic State Transitions
     * ============================================================ */

    public function test_refund_unpaid_booking_succeeds_with_status_change(): void
    {
        $booking = $this->makeBooking();

        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'Phase 10.4 unpaid refund',
        ]);
        $response->assertOk();

        $this->assertEquals(HajjUmraStatus::Refunded, $booking->fresh()->status);
    }

    public function test_refund_partial_paid_booking_reverses_payments(): void
    {
        $baselineVault = (float) AccountEntry::where('account_id', $this->treasuryEGP->id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        $booking = $this->makeBooking();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 25000.0,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P104_PART1_'.uniqid(),
        ])->assertCreated();

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'Phase 10.4 partial refund',
        ])->assertOk();

        $vaultAfterRefund = (float) AccountEntry::where('account_id', $this->treasuryEGP->id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        // Vault should net to baseline (refund reverses the payment)
        $this->assertEqualsWithDelta($baselineVault, $vaultAfterRefund, 0.01,
            'vault must net to baseline after refund');
        $this->assertEquals(HajjUmraStatus::Refunded, $booking->fresh()->status);
    }

    public function test_refund_full_paid_booking_nets_to_baseline(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 50000.0,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P104_FULL_'.uniqid(),
        ])->assertCreated();

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'Phase 10.4 full refund',
        ])->assertOk();

        $this->assertLedgerGloballyBalanced();
        $this->assertEquals(HajjUmraStatus::Refunded, $booking->fresh()->status);
    }

    /* ============================================================
     *  REFUND — Financial Invariants
     * ============================================================ */

    public function test_refund_clears_customer_ar_balance(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 30000.0,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P104_AR_'.uniqid(),
        ])->assertCreated();

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'Phase 10.4 AR clear',
        ])->assertOk();

        $customerAccountId = $booking->fresh()->customer->account_id;
        $customerAR = (float) AccountEntry::where('account_id', $customerAccountId)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        $this->assertEqualsWithDelta(0.0, $customerAR, 0.01,
            'customer AR must net to 0 after full refund');
    }

    public function test_refund_reverses_income_and_expense_additively(): void
    {
        $booking = $this->makeBooking();
        $originalIncome = $booking->fresh()->incomeTransaction;
        $originalExpense = $booking->fresh()->expenseTransaction;

        $this->assertNotNull($originalIncome, 'sanity: income tx exists');
        $this->assertNotNull($originalExpense, 'sanity: expense tx exists');

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'Phase 10.4 income reversal',
        ])->assertOk();

        // Originals preserved (additive pattern)
        $this->assertEquals(
            (float) $originalIncome->amount,
            (float) $originalIncome->fresh()->amount,
            'original income tx amount must be preserved (additive reversal)',
        );
        $this->assertEquals(
            (float) $originalExpense->amount,
            (float) $originalExpense->fresh()->amount,
            'original expense tx amount must be preserved',
        );
    }

    /* ============================================================
     *  REFUND — State Machine
     * ============================================================ */

    public function test_double_refund_rejected(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'first refund',
        ])->assertOk();

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'second refund',
        ])->assertStatus(422);
    }

    public function test_refund_after_cancel_rejected(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'cancel first',
        ])->assertOk();

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'refund after cancel',
        ])->assertStatus(422);
    }

    public function test_refund_after_soft_delete_rejected(): void
    {
        $booking = $this->makeBooking();
        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();

        // soft-deleted bookings are excluded from route-model binding
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'refund after delete',
        ])->assertStatus(404);
    }

    /* ============================================================
     *  REFUND — Audit Trail
     * ============================================================ */

    public function test_refund_appends_reason_to_notes(): void
    {
        $booking = $this->makeBooking();
        $originalNotes = $booking->notes;

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'Phase 10.4 audit trail',
        ])->assertOk();

        $booking->refresh();
        $this->assertStringContainsString('Phase 10.4 audit trail', $booking->notes);
        $this->assertStringContainsString('سبب الاسترداد', $booking->notes);
    }

    /* ============================================================
     *  REFUND — Edge Cases
     * ============================================================ */

    public function test_refund_with_no_payments_succeeds_with_status_only(): void
    {
        // Phase 8.6 Gate — zero-payment refund is SAFE: reverses income/expense
        // but no money is returned to the customer.
        $booking = $this->makeBooking();

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'Phase 10.4 zero-payment refund',
        ])->assertOk();

        $this->assertEquals(HajjUmraStatus::Refunded, $booking->fresh()->status);
        $this->assertLedgerGloballyBalanced();
    }

    public function test_refund_with_multi_payment_reverses_all(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 10000.0,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P104_MULTI1_'.uniqid(),
        ])->assertCreated();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 15000.0,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P104_MULTI2_'.uniqid(),
        ])->assertCreated();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 25000.0,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P104_MULTI3_'.uniqid(),
        ])->assertCreated();

        $this->assertCount(3, $booking->fresh()->payments);

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'Phase 10.4 multi-payment refund',
        ])->assertOk();

        $this->assertEquals(HajjUmraStatus::Refunded, $booking->fresh()->status);
        $this->assertLedgerGloballyBalanced();
    }

    public function test_refund_does_not_deduct_more_than_was_paid(): void
    {
        // Per audit spec: refund_amount = min(intended, paid). Cannot return
        // money that was never received. The full-booking refund returns what
        // was paid; the customer AR after refund must net to 0 (positive or
        // zero) — never below 0.
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 10000.0,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P104_CAP_'.uniqid(),
        ])->assertCreated();

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'Phase 10.4 cap test',
        ])->assertOk();

        $customerAR = (float) AccountEntry::where('account_id', $booking->fresh()->customer->account_id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
        $this->assertGreaterThanOrEqual(-0.01, $customerAR,
            'customer AR must not go negative after refund (refund cap = paid)');
    }

    public function test_refund_on_already_paid_then_cancelled_booking_rejected(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 10000.0,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P104_SEQ_'.uniqid(),
        ])->assertCreated();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'cancel after pay',
        ])->assertOk();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'refund after cancel',
        ])->assertStatus(422);
    }

    /* ============================================================
     *  HELPERS
     * ============================================================ */

    protected function makeBooking(array $overrides = []): HajjUmraBooking
    {
        $program = $this->makeProgram();
        $payload = array_merge([
            'customer' => [
                'full_name' => 'P104 Customer ' . uniqid(),
                'phone' => '010' . substr((string) mt_rand(10_000_000, 99_999_999), 0, 8),
            ],
            'program_id' => $program->id,
            'purchase_price' => 42000.0,
            'selling_price' => 50000.0,
            'currency' => 'EGP',
            'account_id' => $this->treasuryEGP->id,
        ], $overrides);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);
        $response->assertCreated();
        return HajjUmraBooking::findOrFail($response->json('data.id'));
    }

    protected function assertLedgerGloballyBalanced(): void
    {
        $totalCredit = (float) AccountEntry::query()->sum('credit');
        $totalDebit = (float) AccountEntry::query()->sum('debit');
        $this->assertEqualsWithDelta(
            $totalCredit, $totalDebit, 0.01,
            "ledger must be globally balanced: credit=$totalCredit debit=$totalDebit",
        );
    }
}
