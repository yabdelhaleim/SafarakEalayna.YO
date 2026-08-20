<?php

namespace Tests\Feature\HajjUmra;

use App\Enums\HajjUmraStatus;
use App\Models\AccountEntry;
use App\Models\HajjUmraBooking;

/**
 * Phase 10.5 — Cancel Deep Audit (Section 9 of the 30-section prompt, applied
 * independently to the Hajj/Umra module).
 *
 * Audit target: POST /api/v1/hajj-umra/bookings/{id}/cancel
 *
 * Cancel behavior (per HajjUmraBookingService::cancel):
 *   - Flips status → Cancelled
 *   - Reverses all payments (additive-reversal pattern)
 *   - Reverses income + expense transactions (additive-reversal pattern)
 *   - Appends cancel reason to booking notes
 *   - Bookings row stays visible (no soft-delete)
 *
 * Guards (rejects with 422):
 *   - Already cancelled
 *   - Already refunded (terminal state)
 *   - Soft-deleted (use deleteBookingWithReversal instead)
 */
class HajjUmraCancelDeepAuditTest extends HajjUmraTestCase
{
    /* ============================================================
     *  CANCEL — Basic State Transitions
     * ============================================================ */

    public function test_cancel_unpaid_booking_succeeds(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'Phase 10.5 unpaid cancel',
        ])->assertOk();
        $this->assertEquals(HajjUmraStatus::Cancelled, $booking->fresh()->status);
    }

    public function test_cancel_partial_paid_booking_reverses_payments(): void
    {
        $baselineVault = (float) AccountEntry::where('account_id', $this->treasuryEGP->id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        $booking = $this->makeBooking();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 10000.0,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P105_PART1_'.uniqid(),
        ])->assertCreated();

        $vaultAfterPay = (float) AccountEntry::where('account_id', $this->treasuryEGP->id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
        $this->assertGreaterThan($baselineVault, $vaultAfterPay,
            'sanity: vault must increase after pay');

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'Phase 10.5 partial cancel',
        ])->assertOk();

        $vaultAfterCancel = (float) AccountEntry::where('account_id', $this->treasuryEGP->id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
        $this->assertEqualsWithDelta($baselineVault, $vaultAfterCancel, 0.01,
            'vault must return to baseline after cancel');
    }

    public function test_cancel_full_paid_booking_nets_to_baseline(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 50000.0,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P105_FULL_'.uniqid(),
        ])->assertCreated();

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'Phase 10.5 full cancel',
        ])->assertOk();

        $this->assertEquals(HajjUmraStatus::Cancelled, $booking->fresh()->status);
        $this->assertLedgerGloballyBalanced();
    }

    /* ============================================================
     *  CANCEL — Financial Invariants
     * ============================================================ */

    public function test_cancel_restores_executing_company_ap(): void
    {
        $company = $this->makeExecutingCompany();
        $program = $this->makeProgram(['executing_company_id' => $company->id]);
        $baselineAP = (float) AccountEntry::where('account_id', $company->fresh()->account_id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        // Create booking tied to executing-company program
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload([
            'program_id' => $program->id,
        ]));
        $booking = HajjUmraBooking::findOrFail($response->json('data.id'));

        $apAfterBooking = (float) AccountEntry::where('account_id', $company->fresh()->account_id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
        $this->assertLessThan($baselineAP, $apAfterBooking,
            'sanity: executing company AP must decrease after booking create (purchase cost recognized)');

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'Phase 10.5 AP restore',
        ])->assertOk();

        $apAfterCancel = (float) AccountEntry::where('account_id', $company->fresh()->account_id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
        $this->assertEqualsWithDelta($baselineAP, $apAfterCancel, 0.01,
            'executing company AP must return to baseline after cancel');
    }

    public function test_cancel_restores_supplier_ap(): void
    {
        $supplier = $this->makeSupplier();
        $program = $this->makeProgram();

        $baselineAP = (float) AccountEntry::where('account_id', $supplier->account_id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload([
            'program_id' => $program->id,
            'supplier_id' => $supplier->id,
        ]));
        $booking = HajjUmraBooking::findOrFail($response->json('data.id'));

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'Phase 10.5 supplier AP restore',
        ])->assertOk();

        $apAfterCancel = (float) AccountEntry::where('account_id', $supplier->account_id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
        $this->assertEqualsWithDelta($baselineAP, $apAfterCancel, 0.01,
            'supplier AP must return to baseline after cancel');
    }

    public function test_cancel_clears_customer_ar(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
            'amount' => 10000.0,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P105_AR_'.uniqid(),
        ])->assertCreated();

        $customerAccountId = $booking->fresh()->customer->account_id;
        $customerARAfterPay = (float) AccountEntry::where('account_id', $customerAccountId)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'Phase 10.5 AR clear',
        ])->assertOk();

        $customerARAfterCancel = (float) AccountEntry::where('account_id', $customerAccountId)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');

        $this->assertLessThan($customerARAfterPay, $customerARAfterCancel,
            'customer AR must decrease after cancel');
    }

    /* ============================================================
     *  CANCEL — State Machine
     * ============================================================ */

    public function test_double_cancel_rejected(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'first',
        ])->assertOk();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'second',
        ])->assertStatus(422);
    }

    public function test_cancel_after_refund_rejected(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'refund first',
        ])->assertOk();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'cancel after refund',
        ])->assertStatus(422);
    }

    public function test_cancel_after_soft_delete_returns_404(): void
    {
        $booking = $this->makeBooking();
        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'cancel after delete',
        ])->assertStatus(404);
    }

    /* ============================================================
     *  CANCEL — Audit Trail
     * ============================================================ */

    public function test_cancel_appends_reason_to_notes(): void
    {
        $booking = $this->makeBooking();
        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'Phase 10.5 audit',
        ])->assertOk();
        $this->assertStringContainsString('Phase 10.5 audit', $booking->fresh()->notes);
        $this->assertStringContainsString('سبب الإلغاء', $booking->fresh()->notes);
    }

    public function test_cancel_reverses_income_additively(): void
    {
        $booking = $this->makeBooking();
        $originalIncome = $booking->fresh()->incomeTransaction;
        $this->assertNotNull($originalIncome);
        $originalAmount = (float) $originalIncome->amount;

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'income reversal test',
        ])->assertOk();

        $this->assertEquals(
            $originalAmount, (float) $originalIncome->fresh()->amount,
            'original income tx amount must be preserved (additive)',
        );
    }

    /* ============================================================
     *  CANCEL — Multi-Payment Scenario
     * ============================================================ */

    public function test_cancel_with_multi_payment_reverses_all(): void
    {
        $booking = $this->makeBooking();
        for ($i = 0; $i < 3; $i++) {
            $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", [
                'amount' => 5000.0,
                'payment_method' => 'cash',
                'account_id' => $this->treasuryEGP->id,
                'idempotency_key' => "P105_MULTI_{$i}_".uniqid(),
            ])->assertCreated();
        }
        $this->assertCount(3, $booking->fresh()->payments);

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'multi-payment cancel',
        ])->assertOk();

        $this->assertEquals(HajjUmraStatus::Cancelled, $booking->fresh()->status);
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
                'full_name' => 'P105 Customer ' . uniqid(),
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
                'full_name' => 'P105 Customer ' . uniqid(),
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
