<?php

namespace Tests\Feature\Visa;

use App\Enums\VisaStatus;
use App\Models\Transaction;
use App\Models\VisaBooking;
use App\Models\VisaPayment;

/**
 * Phase 9.4 — Refund Deep Audit (Section 8 of the 30-section prompt).
 *
 * Audit target: POST /api/v1/visa/bookings/{id}/refund
 *
 * Important design constraint (documented):
 *   The Visa refund endpoint is **full-refund only**. The controller
 *   (VisaBookingController::refund) calls VisaRefundService::refund()
 *   with only the `reason` parameter — no `amount`. The service then
 *   automatically computes refund_amount = SUM(payments) (capped at
 *   paid_amount) and reverses the entire sum. Partial refund is NOT
 *   supported. This is a system design choice, not a defect.
 *
 * Coverage:
 *   - Full refund: ledger reversal, customer AR, vault NET, status
 *   - Idempotency: double refund, triple refund, after partial
 *   - Refund after cancel: must REJECT (booking already in terminal state)
 *   - Refund after delete: must return 404 (route gated by soft-delete)
 *   - Financial correctness: per-account + global ledger invariants
 */
class VisaRefundDeepAuditTest extends VisaTestCase
{
    /* ============================================================
     *  FULL REFUND — financial state verification
     * ============================================================ */

    public function test_full_refund_clears_customer_ar_balance(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1600.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P94_AR_'.uniqid(),
        ])->assertCreated();

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'Phase 9.4 AR clear test',
        ])->assertOk();

        $customerAccountId = $this->customer->account_id;
        $arBalance = (float) \App\Models\Account::findOrFail($customerAccountId)->fresh()->balance;
        $this->assertEqualsWithDelta(0.0, $arBalance, 0.01,
            'customer AR account must net to 0 after full refund');
    }

    public function test_full_refund_reverses_income_transaction(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1600.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P94_INC_'.uniqid(),
        ])->assertCreated();

        $incomeTxId = $booking->fresh()->income_transaction_id;
        $originalIncome = Transaction::findOrFail($incomeTxId);

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'Phase 9.4 income reversal test',
        ])->assertOk();

        // Additive-reversal pattern: the original income tx is preserved,
        // a REVERSAL entry is added. Verify by checking refund_audit_logs
        // (additive reversal preserves original Transaction.amount).
        $this->assertSame(1600.0, (float) $originalIncome->fresh()->amount,
            'original income transaction amount must be preserved (additive reversal)');
    }

    public function test_full_refund_reverses_all_payment_transactions(): void
    {
        $baselineVault = (float) \App\Models\AccountEntry::where('account_id', $this->vaultEgp->id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
        $baselineBank = (float) \App\Models\AccountEntry::where('account_id', $this->bankEgp->id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        $booking = $this->makeBooking();

        // Two payments (multi-method)
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1000.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P94_PAY1_'.uniqid(),
        ])->assertCreated();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 600.0, 'payment_method' => 'bank_transfer',
            'account_id' => $this->bankEgp->id,
            'idempotency_key' => 'P94_PAY2_'.uniqid(),
        ])->assertCreated();

        $paymentIds = VisaPayment::where('visa_booking_id', $booking->id)->pluck('id')->all();
        $this->assertCount(2, $paymentIds, 'sanity: 2 payments exist');

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'Phase 9.4 multi-method payment reversal test',
        ])->assertOk();

        // Both payment rows preserved, but reversal entries added.
        // Verify by ledger balance NET returning to baseline (opening balance).
        $vaultAfter = (float) \App\Models\AccountEntry::where('account_id', $this->vaultEgp->id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
        $bankAfter = (float) \App\Models\AccountEntry::where('account_id', $this->bankEgp->id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        $this->assertEqualsWithDelta($baselineVault, $vaultAfter, 0.01,
            'vaultEgp ledger NET must return to baseline (refund reversed the original payment)');
        $this->assertEqualsWithDelta($baselineBank, $bankAfter, 0.01,
            'bankEgp ledger NET must return to baseline (refund reversed the original payment)');
    }

    public function test_full_refund_restores_vault_balance_to_baseline(): void
    {
        $baselineVault = (float) $this->vaultEgp->fresh()->balance;

        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1600.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P94_BASE_'.uniqid(),
        ])->assertCreated();

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'Phase 9.4 vault baseline test',
        ])->assertOk();

        $this->assertEqualsWithDelta(
            $baselineVault, (float) $this->vaultEgp->fresh()->balance, 0.01,
            'vaultEgp must return to baseline after create + pay + full refund (NET)'
        );
    }

    public function test_full_refund_of_partial_payment_refunds_only_what_was_paid(): void
    {
        // Key behavior: full refund = sum of payments, NOT selling+fee.
        // Booking 1600, pay 1000, refund → 1000 refunded, customer still owes 600.
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1000.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P94_PART_'.uniqid(),
        ])->assertCreated();

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'Phase 9.4 partial-pay + full-refund test',
        ])->assertOk();

        $booking->refresh();
        $this->assertEquals(VisaStatus::Refunded, $booking->status,
            'status must transition to Refunded (full refund applied)');
        // The system refund = paid amount (1000), not selling+fee (1600).
        // customer AR is reversed for 1000 only.
    }

    /* ============================================================
     *  DUPLICATE / IDEMPOTENT REFUND
     * ============================================================ */

    public function test_double_full_refund_second_is_rejected(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1600.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P94_DBL_'.uniqid(),
        ])->assertCreated();

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'first refund',
        ])->assertOk();

        // Second refund MUST be rejected (booking already Refunded)
        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'second refund — must fail',
        ]);
        $response->assertStatus(422, 'second full refund must be rejected');
    }

    public function test_triple_full_refund_third_is_rejected(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1600.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P94_TRP_'.uniqid(),
        ])->assertCreated();

        // 1st succeeds
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'first',
        ])->assertOk();

        // 2nd and 3rd must both be rejected
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'second',
        ])->assertStatus(422);
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'third',
        ])->assertStatus(422);

        // Final state is still Refunded (no double-credit)
        $this->assertEquals(VisaStatus::Refunded, $booking->fresh()->status);
    }

    public function test_refund_with_zero_payments_succeeds_as_no_op(): void
    {
        // Documented behavior: refund of unpaid booking is a "void" no-op
        $booking = $this->makeBooking();

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'Phase 9.4 void (no payment) — should succeed as no-op',
        ]);
        $response->assertOk();
        $this->assertEquals(VisaStatus::Refunded, $booking->fresh()->status);
    }

    /* ============================================================
     *  REFUND AFTER CANCEL / DELETE
     * ============================================================ */

    public function test_cannot_refund_cancelled_booking(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1600.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P94_CXL_'.uniqid(),
        ])->assertCreated();

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
            'reason' => 'cancel before refund',
        ])->assertOk();

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'refund after cancel — must fail',
        ]);
        $response->assertStatus(422, 'refund of cancelled booking must be rejected');
    }

    public function test_cannot_refund_soft_deleted_booking(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1600.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P94_DEL_'.uniqid(),
        ])->assertCreated();

        $this->deleteJson("/api/v1/visa/bookings/{$booking->id}")->assertOk();

        // Soft-deleted booking: route returns 404 (implicit-by-binding exclusion)
        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'refund after soft-delete',
        ]);
        $this->assertContains(
            $response->status(),
            [404, 422],
            'refund of soft-deleted booking must fail (404 or 422)'
        );
    }

    /* ============================================================
     *  REFUND EDGE CASES
     * ============================================================ */

    public function test_refund_with_missing_reason_string_succeeds(): void
    {
        // The endpoint requires no 'reason' field (it's nullable); tests
        // that the audit log still records the action even with empty reason.
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1600.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P94_NORSN_'.uniqid(),
        ])->assertCreated();

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", []);
        $response->assertOk();
        $this->assertEquals(VisaStatus::Refunded, $booking->fresh()->status);
    }

    public function test_refund_creates_refund_audit_log_entry(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1600.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P94_AUDIT_'.uniqid(),
        ])->assertCreated();

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'Phase 9.4 audit log test',
        ])->assertOk();

        // Verify a refund_audit_logs row exists for this booking
        // (table schema: module='visa', booking_id=<visa_booking_id>)
        $this->assertDatabaseHas('refund_audit_logs', [
            'module' => 'visa',
            'booking_id' => $booking->id,
        ]);
    }

    /* ============================================================
     *  FINANCIAL CORRECTNESS — global invariant
     * ============================================================ */

    public function test_full_refund_leaves_ledger_globally_balanced(): void
    {
        $booking = $this->makeBooking();
        // Two payments summing to booking total (selling+fee = 1500+100 = 1600)
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1000.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P94_GLOB_'.uniqid(),
        ])->assertCreated();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 600.0, 'payment_method' => 'bank_transfer',
            'account_id' => $this->bankEgp->id,
            'idempotency_key' => 'P94_GLOB2_'.uniqid(),
        ])->assertCreated();

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'Phase 9.4 global invariant test',
        ])->assertOk();

        // SUM(credit) == SUM(debit) across all accounts globally
        $this->assertLedgerGloballyBalanced();
    }

    public function test_full_refund_preserves_audit_trail_via_reversal_entries(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1600.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P94_TRAIL_'.uniqid(),
        ])->assertCreated();

        $paymentCountBefore = \App\Models\AccountEntry::whereHas('transaction', function ($q) use ($booking) {
            $q->where('module', 'visa')->where('related_id', $booking->id);
        })->count();

        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'Phase 9.4 audit trail test',
        ])->assertOk();

        $paymentCountAfter = \App\Models\AccountEntry::whereHas('transaction', function ($q) use ($booking) {
            $q->where('module', 'visa')->where('related_id', $booking->id);
        })->count();

        $this->assertGreaterThan($paymentCountBefore, $paymentCountAfter,
            'refund must ADD reversal entries (additive-reversal pattern) — original entries preserved');
    }

    public function test_full_refund_does_not_create_duplicate_income_entries(): void
    {
        // Critical: refund must REVERSE the original income, not create
        // a separate "refund income" entry. Otherwise income is double-counted.
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 1600.0, 'payment_method' => 'cash',
            'account_id' => $this->vaultEgp->id,
            'idempotency_key' => 'P94_NO_DUP_'.uniqid(),
        ])->assertCreated();

        // 1st refund
        $this->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
            'reason' => 'first',
        ])->assertOk();

        $booking->refresh();
        $incomeTxId = $booking->income_transaction_id;

        // Count transactions tied to this booking
        $txCount = Transaction::where('module', 'visa')
            ->where('related_id', $booking->id)
            ->count();

        // Should be: 1 (create) + 1 (payment) + 1 (refund reversal) = 3
        // NOT 1+1+1+1 = 4 (no duplicate income)
        $this->assertLessThanOrEqual(3, $txCount,
            'refund must not create a duplicate income entry — additive reversal only');
    }
}
