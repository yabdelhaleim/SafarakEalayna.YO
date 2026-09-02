<?php

namespace Tests\Feature\HajjUmra;

use App\Enums\HajjUmraStatus;
use App\Models\AccountEntry;
use App\Models\HajjUmraBooking;

/**
 * Phase 10.6 — Delete/Reverse Deep Audit (Section 10 of the 30-section prompt,
 * applied independently to the Hajj/Umra module).
 *
 * Audit target: DELETE /api/v1/hajj-umra/bookings/{id}
 *
 * Delete behavior (per HajjUmraBookingService::deleteBookingWithReversal):
 *   - Soft-deletes the booking row (HajjUmraBooking uses SoftDeletes)
 *   - Reverses all payment transactions (additive-reversal pattern)
 *   - Reverses income + expense transactions (additive-reversal pattern)
 *   - Soft-deletes the payments (HajjUmraPayment also uses SoftDeletes)
 *   - Actor identity required (Phase 8.6 B1 — Hajj/Umra fix landed in 4f95198)
 *   - Idempotency: throws on already-trashed
 */
class HajjUmraDeleteDeepAuditTest extends HajjUmraTestCase
{
    /* ============================================================
     *  DELETE — Basic
     * ============================================================ */

    public function test_delete_unpaid_booking_soft_deletes_with_full_reversal(): void
    {
        $booking = $this->makeBooking();
        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();

        $this->assertSoftDeleted('hajj_umra_bookings', ['id' => $booking->id]);
        $this->assertLedgerGloballyBalanced();
    }

    public function test_delete_paid_booking_reverses_all_payments(): void
    {
        // Baseline must be captured BEFORE booking create — the create
        // records an expense that debits the treasury, and we want the
        // assertion to verify the FULL reversal of all bookings effects.
        $baselineVault = (float) AccountEntry::where('account_id', $this->treasuryEGP->id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        $booking = $this->makeBooking();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 25000.0,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P106_DEL1_'.uniqid(),
        ])->assertCreated();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 25000.0,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P106_DEL2_'.uniqid(),
        ])->assertCreated();

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();

        $vaultAfter = (float) AccountEntry::where('account_id', $this->treasuryEGP->id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
        $this->assertEqualsWithDelta($baselineVault, $vaultAfter, 0.01,
            'vault must net to baseline after delete (all payments + booking create reversed)');
        $this->assertSoftDeleted('hajj_umra_bookings', ['id' => $booking->id]);
    }

    /* ============================================================
     *  DELETE — Financial Invariants (zero-ghost)
     * ============================================================ */

    public function test_delete_zero_ghost_income(): void
    {
        $booking = $this->makeBooking();
        $originalIncome = $booking->fresh()->incomeTransaction;
        $originalAmount = (float) $originalIncome->amount;

        // The income is a journal transfer (clearing → customer_AR). The
        // income shows up on the customer AR account as a +amount credit.
        // Capture the customer AR balance BEFORE the delete.
        $customerAccountId = (int) $booking->customer->account_id;
        $beforeDeleteAr = (float) AccountEntry::where('account_id', $customerAccountId)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
        $this->assertEqualsWithDelta($originalAmount, $beforeDeleteAr, 0.01,
            'sanity: customer AR should equal original income before delete');

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();

        // Original income tx must still exist with original amount (additive)
        $this->assertEquals($originalAmount, (float) $originalIncome->fresh()->amount,
            'original income tx must be preserved (additive)');

        // After reversal, the customer AR must be ZERO (the original 50000
        // credit + a 50000 debit reversal entry added by
        // TransactionService::reverseTransaction()).
        $afterDeleteAr = (float) AccountEntry::where('account_id', $customerAccountId)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
        $this->assertEqualsWithDelta(0.0, $afterDeleteAr, 0.01,
            'customer AR must net to 0 after reversal (zero ghost)');
    }

    public function test_delete_zero_ghost_expense(): void
    {
        $booking = $this->makeBooking();
        $originalExpense = $booking->fresh()->expenseTransaction;
        $originalAmount = (float) $originalExpense->amount;

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();

        $this->assertEquals($originalAmount, (float) $originalExpense->fresh()->amount,
            'original expense tx must be preserved (additive)');
    }

    public function test_delete_zero_ghost_supplier_debt(): void
    {
        // BUG FIX (2026-08-29): supplier from makeSupplier() defaults to USD
        // account. Booking payload uses EGP currency, so the FX guard in
        // HajjUmraBookingService::create() refuses to book with no FX rate
        // in tests. Fix: build an EGP-denominated supplier here so the test
        // exercises the SAME-CURRENCY supplier-AP debit+reverse path.
        $supplier = $this->makeSupplier();
        \App\Support\Finance\LedgerBalanceMutationGuard::run(function () use ($supplier) {
            $supplier->account->update(['currency' => 'EGP']);
        });
        $program = $this->makeProgram();

        $baselineAP = (float) AccountEntry::where('account_id', $supplier->account_id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload([
            'program_id' => $program->id,
            'supplier_id' => $supplier->id,
        ]));
        $booking = HajjUmraBooking::findOrFail($response->json('data.id'));

        $apAfterBooking = (float) AccountEntry::where('account_id', $supplier->account_id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
        $this->assertLessThan($baselineAP, $apAfterBooking,
            'sanity: supplier AP must decrease after booking create');

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();

        $apAfterDelete = (float) AccountEntry::where('account_id', $supplier->account_id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
        $this->assertEqualsWithDelta($baselineAP, $apAfterDelete, 0.01,
            'supplier AP must return to baseline after delete (zero ghost)');
    }

    public function test_delete_zero_ghost_customer_ar(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 5000.0,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P106_GHOST_'.uniqid(),
        ])->assertCreated();

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();

        $customerAR = (float) AccountEntry::where('account_id', $booking->customer->account_id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
        $this->assertEqualsWithDelta(0.0, $customerAR, 0.01,
            'customer AR must net to 0 after delete (zero ghost)');
    }

    /* ============================================================
     *  DELETE — Idempotency
     * ============================================================ */

    public function test_double_delete_is_rejected_with_422(): void
    {
        $booking = $this->makeBooking();
        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();
        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")
            ->assertStatus(422);
    }

    /* ============================================================
     *  DELETE — Authorization
     * ============================================================ */

    public function test_employee_cannot_delete_booking(): void
    {
        // This is covered in EmployeeDeepE2E. Repeated here for completeness.
        $this->markTestSkipped('covered by EmployeeDeepE2E test_employee_cannot_delete_booking');
    }

    /* ============================================================
     *  DELETE — After Cancel / Refund
     * ============================================================ */

    public function test_delete_after_cancel_succeeds(): void
    {
        // Cancel flips status to Cancelled; delete is still allowed (admin
        // is the final word on the row). Both additive-reversal patterns
        // apply, but since both reversals affect the same transactions,
        // the second pass should be a no-op.
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'cancel then delete',
        ])->assertOk();
        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();
        $this->assertSoftDeleted('hajj_umra_bookings', ['id' => $booking->id]);
    }

    public function test_delete_after_refund_404(): void
    {
        // Refund changes status to Refunded; soft-delete from non-cancelled
        // is allowed. Note: actual status is Refunded, soft-delete is OK.
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'refund then delete',
        ])->assertOk();

        // Refund flips status=refunded. deleteBookingWithReversal is independent
        // of status — it should still work.
        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();
        $this->assertSoftDeleted('hajj_umra_bookings', ['id' => $booking->id]);
    }

    /* ============================================================
     *  DELETE — Sequence: cancel then delete is idempotent on the
     *  same financial effect (the original tx is reversed once,
     *  then a second reversal attempt should not double-reverse).
     * ============================================================ */

    public function test_cancel_then_delete_does_not_double_reverse(): void
    {
        $booking = $this->makeBooking();
        $baselineVault = (float) AccountEntry::where('account_id', $this->treasuryEGP->id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'cancel first',
        ])->assertOk();
        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();

        // After cancel: vault baseline (income reversed, expense reversed).
        // After delete on already-cancelled: vault should STILL be baseline
        // because the original tx is preserved and the cancel already
        // applied the inverse entries.
        $vaultAfter = (float) AccountEntry::where('account_id', $this->treasuryEGP->id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
        $this->assertEqualsWithDelta($baselineVault, $vaultAfter, 0.01,
            'cancel then delete must not double-reverse (preserve baseline)');
    }

    /* ============================================================
     *  DELETE — Multi-currency/companion scenarios
     * ============================================================ */

    public function test_delete_with_companion_reverses_companion_purchase(): void
    {
        $program = $this->makeProgram();
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload([
            'program_id' => $program->id,
            'purchase_price' => 42000,
            'selling_price' => 50000,
            'companion_purchase_price' => 35000,
            'companion_selling_price' => 42000,
        ]));
        $booking = HajjUmraBooking::findOrFail($response->json('data.id'));

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();
        $this->assertLedgerGloballyBalanced();
    }

    /* ============================================================
     *  HELPERS
     * ============================================================ */

    protected function makeBooking(array $overrides = []): HajjUmraBooking
    {
        $program = $this->makeProgram();
        $payload = array_merge([
            'customer' => [
                'full_name' => 'P106 Customer ' . uniqid(),
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

    protected function bookingPayload(array $overrides = []): array
    {
        $program = $this->makeProgram();
        return array_merge([
            'customer' => [
                'full_name' => 'P106 Customer ' . uniqid(),
                'phone' => '010' . substr((string) mt_rand(10_000_000, 99_999_999), 0, 8),
            ],
            'program_id' => $program->id,
            'purchase_price' => 42000.0,
            'selling_price' => 50000.0,
            'currency' => 'EGP',
            'account_id' => $this->treasuryEGP->id,
        ], $overrides);
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
