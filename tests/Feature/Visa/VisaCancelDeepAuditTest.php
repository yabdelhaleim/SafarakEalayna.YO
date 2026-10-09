<?php

namespace Tests\Feature\Visa;

use App\Enums\VisaStatus;
use App\Models\Account;
use App\Models\Transaction;

/**
 * Phase 9.5b — Cancel Deep Audit (Section 9 of the 30-section prompt).
 *
 * Audit target: POST /api/v1/visa/bookings/{id}/cancel
 *
 * Cancel behavior (per VisaRefundService::cancel):
 *   - Flips status → Cancelled
 *   - Reverses all payments (additive-reversal pattern)
 *   - Reverses income + expense transactions (additive-reversal pattern)
 *   - Updates visaDetail.status → Cancelled
 *   - Appends cancel reason to booking notes
 *
 * Guards (rejects with 422):
 *   - Already cancelled (idempotency)
 *   - Already refunded (terminal state)
 *   - Soft-deleted (use deleteWithReversal instead)
 *
 * Coverage:
 *   - State transitions: unpaid, partial, full
 *   - Financial invariants: agent AP (THE GAP), customer AR, vault NET, income
 *   - State machine: double-cancel, cancel-after-refund, cancel-after-delete
 *   - Audit trail: notes updated with reason
 *   - Edge cases: missing reason, multi-currency
 */
class VisaCancelDeepAuditTest extends VisaTestCase
{
    /* ============================================================
     *  CANCEL — Basic State Transitions
     * ============================================================ */

    public function test_cancel_unpaid_booking_succeeds(): void
    {
        $booking = $this->makeBooking();

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'Phase 9.5b unpaid cancel',
        ]);
        $response->assertOk();

        $this->assertEquals(VisaStatus::Cancelled, $booking->fresh()->status);
    }

    public function test_cancel_partial_paid_booking_reverses_payments(): void
    {
        $baselineVault = (float) \App\Models\AccountEntry::where('account_id', $this->vaultEgp->id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1000.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P95B_PART1_'.uniqid(),
        ])->assertCreated();

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'Phase 9.5b partial paid cancel',
        ]);
        $response->assertOk();

        $this->assertEquals(VisaStatus::Cancelled, $booking->fresh()->status);

        $vaultAfter = (float) \App\Models\AccountEntry::where('account_id', $this->vaultEgp->id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
        $this->assertEqualsWithDelta($baselineVault, $vaultAfter, 0.01,
            'vaultEgp ledger must return to baseline after partial pay + cancel');
    }

    public function test_cancel_full_paid_booking_reverses_all_payments(): void
    {
        $baselineVault = (float) \App\Models\AccountEntry::where('account_id', $this->vaultEgp->id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1600.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P95B_FULL_'.uniqid(),
        ])->assertCreated();

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'Phase 9.5b full paid cancel',
        ]);
        $response->assertOk();

        $this->assertEquals(VisaStatus::Cancelled, $booking->fresh()->status);

        $vaultAfter = (float) \App\Models\AccountEntry::where('account_id', $this->vaultEgp->id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
        $this->assertEqualsWithDelta($baselineVault, $vaultAfter, 0.01,
            'vaultEgp ledger must return to baseline after full pay + cancel');
    }

    /* ============================================================
     *  CANCEL — Financial Invariants (THE GAP: agent AP)
     * ============================================================ */

    public function test_cancel_restores_agent_ap_balance_to_baseline(): void
    {
        // THE KEY GAP from the audit: only soft-delete tested previously.
        // Booking with agent has -purchase_price on agent's supplier account.
        // After cancel, agent AP must net back to 0.
        $agentAccountId = $this->agent->account_id;
        $baselineAgentAP = (float) \App\Models\AccountEntry::where('account_id', $agentAccountId)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        $booking = $this->makeBooking();

        // Confirm agent went AP (-1000) after booking creation
        $agentAPAfterCreate = (float) \App\Models\AccountEntry::where('account_id', $agentAccountId)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
        $this->assertLessThan(0, $agentAPAfterCreate,
            'sanity: agent AP must be negative after booking create (purchase_price route)');

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'Phase 9.5b agent AP restore',
        ])->assertOk();

        $agentAPAfterCancel = (float) \App\Models\AccountEntry::where('account_id', $agentAccountId)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        $this->assertEqualsWithDelta($baselineAgentAP, $agentAPAfterCancel, 0.01,
            'agent AP balance must return to baseline after cancel');
    }

    public function test_cancel_restores_agent_ap_after_partial_pay(): void
    {
        // Customer payment does NOT affect agent AP (payment goes to vault).
        // Cancel still reverses the booking expense → agent AP returns to baseline.
        $agentAccountId = $this->agent->account_id;
        $baselineAgentAP = (float) \App\Models\AccountEntry::where('account_id', $agentAccountId)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 800.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P95B_AGENT_PAY_'.uniqid(),
        ])->assertCreated();

        $agentAPAfterPay = (float) \App\Models\AccountEntry::where('account_id', $agentAccountId)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
        $this->assertEqualsWithDelta($baselineAgentAP - 1000.0, $agentAPAfterPay, 0.01,
            'sanity: customer payment does NOT change agent AP (purchase_price stays as AP until reversed)');

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'Phase 9.5b agent AP after partial pay',
        ])->assertOk();

        $agentAPAfterCancel = (float) \App\Models\AccountEntry::where('account_id', $agentAccountId)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        $this->assertEqualsWithDelta($baselineAgentAP, $agentAPAfterCancel, 0.01,
            'cancel after partial pay must still restore agent AP to baseline (reversal of expense)');
    }

    public function test_cancel_clears_customer_ar_balance(): void
    {
        // Full cancel semantics: reverses both income (-1600 to AR) AND payment (+1000 to AR)
        // → AR nets back to 0 (no remaining debt after cancellation)
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1000.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P95B_AR_'.uniqid(),
        ])->assertCreated();

        $customerAccountId = $this->customer->account_id;
        $customerARAfterPay = (float) \App\Models\AccountEntry::where('account_id', $customerAccountId)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
        // After booking create + pay 1000: AR = (1600 income) - 1000 (payment) = 600 (still owes 600)
        $this->assertEqualsWithDelta(600.0, $customerARAfterPay, 0.01,
            'sanity: customer AR should be 600 after booking create + pay 1000 of 1600 total');

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'Phase 9.5b customer AR clear',
        ])->assertOk();

        $customerARAfter = (float) \App\Models\AccountEntry::where('account_id', $customerAccountId)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        // Full cancel removes the booking entirely → AR must be 0 (no debt remains)
        $this->assertEqualsWithDelta(0.0, $customerARAfter, 0.01,
            'customer AR must net to 0 after full cancel (income reversed + payment reversed)');
    }

    public function test_cancel_reverses_income_transaction(): void
    {
        // Additive-reversal pattern: original income tx preserved, reversal entry added
        $booking = $this->makeBooking();
        $originalIncome = $booking->fresh()->incomeTransaction;

        $this->assertNotNull($originalIncome, 'sanity: income tx exists');
        $originalAmount = (float) $originalIncome->amount;

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'Phase 9.5b income reversal',
        ])->assertOk();

        // Original income tx is preserved (additive pattern)
        $this->assertEquals($originalAmount, (float) $originalIncome->fresh()->amount,
            'original income tx amount must be preserved after cancel');

        // And reversal entries were added
        $this->assertGreaterThan(0, \App\Models\AccountEntry::where('transaction_id', $originalIncome->id)
            ->where('notes', 'like', '%عكس%')
            ->count(),
            'cancel must add reversal entries (additive pattern, not destructive)');
    }

    public function test_cancel_leaves_ledger_globally_balanced(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1000.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P95B_GLOB_'.uniqid(),
        ])->assertCreated();

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'Phase 9.5b global invariant',
        ])->assertOk();

        $this->assertLedgerGloballyBalanced();
    }

    /* ============================================================
     *  CANCEL — State Machine Guards
     * ============================================================ */

    public function test_cancel_after_cancel_is_rejected(): void
    {
        // Idempotency guard: second cancel must throw 422
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'first cancel',
        ])->assertOk();

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'second cancel — must fail',
        ]);
        $response->assertStatus(422, 'second cancel must be rejected (already cancelled)');
    }

    public function test_cancel_after_refund_is_rejected(): void
    {
        // Refund is terminal — cancel must NOT follow it
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1600.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P95B_AFT_REF_PAY_'.uniqid(),
        ])->assertCreated();

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'refund first',
        ])->assertOk();

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'cancel after refund — must fail',
        ]);
        $response->assertStatus(422, 'cancel after refund must be rejected (terminal state)');
    }

    public function test_cancel_after_delete_returns_404(): void
    {
        // Soft-delete makes route binding fail
        $booking = $this->makeBooking();
        $this->deleteJson("/api/v1/visa/bookings/{$booking->id}")->assertOk();

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'cancel after delete — must fail',
        ]);
        $this->assertContains($response->status(), [404, 422],
            'cancel after soft-delete must fail (404 binding or 422 trashed guard)');
    }

    /* ============================================================
     *  CANCEL — Audit Trail + Edge Cases
     * ============================================================ */

    public function test_cancel_appends_reason_to_booking_notes(): void
    {
        $booking = $this->makeBooking();
        $originalNotes = $booking->notes;

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'Phase 9.5b notes append test',
        ])->assertOk();

        $booking->refresh();
        $this->assertStringContainsString('Phase 9.5b notes append test', $booking->notes,
            'cancel reason must be appended to booking notes');
        $this->assertStringContainsString('سبب الإلغاء:', $booking->notes,
            'cancel reason must be prefixed with the canonical Arabic marker');
        $this->assertStringStartsWith($originalNotes, $booking->notes,
            'original notes must be preserved at the start');
    }

    public function test_cancel_with_missing_reason_succeeds(): void
    {
        // reason is nullable per the FormRequest
        $booking = $this->makeBooking();

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", []);
        $response->assertOk();
        $this->assertEquals(VisaStatus::Cancelled, $booking->fresh()->status);
    }

    public function test_cancel_with_usd_booking_restores_usd_vault(): void
    {
        // Multi-currency path: USD booking with USD payment + cancel
        $baselineUsdVault = (float) \App\Models\AccountEntry::where('account_id', $this->vaultUsd->id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        $booking = $this->makeBooking([
            'currency' => 'USD',
            'account_id' => $this->vaultUsd->id,
            'purchase_price' => 500.0,
            'selling_price' => 700.0,
            'service_fee' => 50.0,
        ]);

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 750.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultUsd->id,
            'idempotency_key' => 'P95B_USD_'.uniqid(),
        ])->assertCreated();

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'Phase 9.5b USD cancel',
        ])->assertOk();

        $usdVaultAfter = (float) \App\Models\AccountEntry::where('account_id', $this->vaultUsd->id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
        $this->assertEqualsWithDelta($baselineUsdVault, $usdVaultAfter, 0.01,
            'USD vault must return to baseline after USD booking pay + cancel');
    }

    /* ============================================================
     *  CANCEL — visaDetail status propagation
     * ============================================================ */

    public function test_cancel_propagates_status_to_visa_detail(): void
    {
        $booking = $this->makeBooking();
        $detailId = $booking->visaDetail->id;

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'Phase 9.5b detail status propagation',
        ])->assertOk();

        $detail = \App\Models\VisaDetail::findOrFail($detailId);
        $this->assertEquals(VisaStatus::Cancelled, $detail->status,
            'visaDetail.status must also transition to Cancelled (parent booking cancellation propagates)');
    }
}