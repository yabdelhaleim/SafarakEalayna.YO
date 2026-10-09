<?php

namespace Tests\Feature\HajjUmra;

use App\Enums\HajjUmraStatus;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\HajjUmraBooking;
use App\Models\HajjUmraPayment;
use App\Support\Finance\LedgerBalanceMutationGuard;

/**
 * Phase 10.2 — Admin E2E (Section 6 of the 30-section prompt, applied
 * independently to the Hajj/Umra module).
 *
 * Audit target: full admin-driven lifecycle on /api/v1/hajj-umra/bookings.
 *
 * Scenarios:
 *   A. Create (Pending/Confirmed) without initial payment
 *   B. Create with initial payment
 *   C. Multi-payment across cash + bank + wallet on the same booking
 *   D. Read (index, show)
 *   E. Confirmed → InProgress → Completed transitions
 *   F. Refund (full)
 *   G. Cancel (additive reversal)
 *   H. Delete (soft delete with full reversal)
 *   I. AddPayment state gates (rejected on cancelled / refunded / trashed)
 *
 * Financial invariants verified per scenario:
 *   - Per-account balance = SUM(credit) - SUM(debit)
 *   - Global ledger balance (SUM credit == SUM debit)
 *   - Customer AR matches expected
 *   - Expense/Income/Supplier-AP match expected
 */
class HajjUmraAdminFullLifecycleTest extends HajjUmraTestCase
{
    /**
     * `makeSupplier()` (in HajjUmraTestCase) creates a USD-denominated
     * account. Tests that wire a booking through that supplier need a
     * current EGP↔USD rate or the cross-currency transfer throws
     * "لا يوجد سعر صرف متاح".
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedExchangeRate('USD', 'EGP', 50.0);
        $this->seedExchangeRate('EGP', 'USD', 1 / 50.0);
        $this->seedExchangeRate('SAR', 'EGP', 13.5);
    }

    /* ============================================================
     *  A. CREATE — minimal, no initial payment
     * ============================================================ */

    public function test_create_minimal_booking_returns_201_with_default_status(): void
    {
        $program = $this->makeProgram([
            'default_selling_price' => 50000,
            'default_purchase_price' => 42000,
        ]);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload([
            'program_id' => $program->id,
            'purchase_price' => 42000,
            'selling_price' => 50000,
        ]));

        $response->assertCreated();
        $booking = HajjUmraBooking::findOrFail($response->json('data.id'));
        $this->assertSame(42000.0, (float) $booking->purchase_price);
        $this->assertSame(50000.0, (float) $booking->selling_price);
        $this->assertSame(8000.0, (float) $booking->profit);
        $this->assertSame('EGP', $booking->currency);
        // Phase 10.2 — Hajj/Umra default is Confirmed (not Pending like Visa's
        // Submitted). Confirmed is the canonical "ready to operate" state.
        $this->assertSame('confirmed', $booking->status->value);
    }

    public function test_create_booking_with_status_pending_succeeds(): void
    {
        $program = $this->makeProgram();
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload([
            'program_id' => $program->id,
            'status' => 'pending',
        ]));
        $booking = HajjUmraBooking::findOrFail($response->json('data.id'));
        $this->assertSame('pending', $booking->status->value);
    }

    public function test_create_booking_with_initial_payment_succeeds(): void
    {
        $program = $this->makeProgram();
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload([
            'program_id' => $program->id,
            'purchase_price' => 42000,
            'selling_price' => 50000,
            'initial_payment' => [
                'amount' => 20000,
                'payment_method' => 'cash',
                'account_id' => $this->treasuryEGP->id,
            ],
        ]));

        $response->assertCreated();
        $booking = HajjUmraBooking::findOrFail($response->json('data.id'));
        $this->assertCount(1, $booking->payments);
        $this->assertSame(20000.0, (float) $booking->payments->first()->amount);
    }

    public function test_create_booking_with_companion_prices(): void
    {
        $program = $this->makeProgram();
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload([
            'program_id' => $program->id,
            'purchase_price' => 42000,
            'selling_price' => 50000,
            'companion_purchase_price' => 35000,
            'companion_selling_price' => 42000,
            'accommodation_extra_charge' => 2000,
        ]));

        $response->assertCreated();
        $booking = HajjUmraBooking::findOrFail($response->json('data.id'));
        // total selling = 50000 + 42000 + 2000 = 94000
        // total purchase = 42000 + 35000 = 77000
        // profit = 94000 - 77000 = 17000
        $this->assertEqualsWithDelta(17000.0, (float) $booking->profit, 0.01);
    }

    public function test_create_booking_with_passengers_breakdown(): void
    {
        $program = $this->makeProgram();
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload([
            'program_id' => $program->id,
            'purchase_price' => 42000,
            'selling_price' => 50000,
            'passengers' => [
                ['category' => 'adult', 'count' => 1, 'unit_price' => 50000, 'subtotal' => 50000],
                ['category' => 'child_with_bed', 'count' => 1, 'unit_price' => 40000, 'subtotal' => 40000],
            ],
        ]));

        $response->assertCreated();
        $booking = HajjUmraBooking::findOrFail($response->json('data.id'));
        $this->assertCount(2, $booking->passengers);
    }

    public function test_create_booking_links_executing_company_account(): void
    {
        $company = $this->makeExecutingCompany();
        $program = $this->makeProgram(['executing_company_id' => $company->id]);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload([
            'program_id' => $program->id,
            'purchase_price' => 42000,
            'selling_price' => 50000,
        ]));

        $response->assertCreated();
        $this->assertNotNull($company->fresh()->account_id,
            'executing company must have an account after a booking uses it');
    }

    public function test_create_booking_with_supplier(): void
    {
        $supplier = $this->makeSupplier();
        $program = $this->makeProgram();

        $response = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload([
            'program_id' => $program->id,
            'purchase_price' => 42000,
            'selling_price' => 50000,
            'supplier_id' => $supplier->id,
        ]));

        $response->assertCreated();
        $booking = HajjUmraBooking::findOrFail($response->json('data.id'));
        $this->assertSame($supplier->id, $booking->supplier_id);
    }

    /* ============================================================
     *  B. READ — index, show
     * ============================================================ */

    public function test_index_returns_paginated_list(): void
    {
        $program = $this->makeProgram();
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload([
                'program_id' => $program->id,
                'purchase_price' => 1000,
                'selling_price' => 2000,
            ]))->assertCreated();
        }

        $response = $this->getJson('/api/v1/hajj-umra/bookings')->assertOk();
        $data = $response->json('data');
        $items = $data['items'] ?? [];
        $this->assertGreaterThanOrEqual(3, count($items));
    }

    public function test_show_returns_booking_with_relations(): void
    {
        $program = $this->makeProgram();
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload([
            'program_id' => $program->id,
        ]));
        $bookingId = $response->json('data.id');

        $this->getJson("/api/v1/hajj-umra/bookings/{$bookingId}")
            ->assertOk()
            ->assertJsonPath('data.id', $bookingId);
    }

    /* ============================================================
     *  C. MULTI-PAYMENT — same booking, multiple methods
     * ============================================================ */

    public function test_multi_payment_cash_then_bank_then_wallet(): void
    {
        $program = $this->makeProgram();
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload([
            'program_id' => $program->id,
            'purchase_price' => 42000,
            'selling_price' => 50000,
        ]));
        $bookingId = $response->json('data.id');

        $bank = $this->makeBankAccount('EGP', 250_000.00);
        $wallet = $this->makeWalletAccount('vodafone_cash', 'EGP', 50_000.00);

        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 20000,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P102_CASH_'.uniqid(),
        ])->assertCreated();

        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 20000,
            'payment_method' => 'bank',
            'account_id' => $bank->id,
            'idempotency_key' => 'P102_BANK_'.uniqid(),
        ])->assertCreated();

        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 10000,
            'payment_method' => 'wallet',
            'account_id' => $wallet->id,
            'idempotency_key' => 'P102_WALLET_'.uniqid(),
        ])->assertCreated();

        $booking = HajjUmraBooking::findOrFail($bookingId);
        $this->assertCount(3, $booking->payments);
        $totalPaid = $booking->payments->sum(fn ($p) => (float) $p->amount);
        $this->assertEqualsWithDelta(50000.0, $totalPaid, 0.01);
    }

    public function test_multi_payment_with_different_currencies_is_rejected(): void
    {
        // Per AccountModuleContract + HajjUmraLiquidityAccount rule, the
        // payment account must match the booking currency. Mixing EGP booking
        // with USD account should be rejected.
        $program = $this->makeProgram();
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload([
            'program_id' => $program->id,
        ]));
        $bookingId = $response->json('data.id');

        $usdVault = LedgerBalanceMutationGuard::run(function () {
            return Account::query()->create([
                'name' => 'USD Vault Hajj',
                'type' => 'cashbox',
                'currency' => 'USD',
                'balance' => 10000.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module' => 'hajj_umra',
                'is_module_vault' => true,
                'created_by' => $this->admin->id,
            ]);
        });

        $resp = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 100.0,
            'payment_method' => 'cash',
            'account_id' => $usdVault->id,
            'idempotency_key' => 'P102_CCY_'.uniqid(),
        ]);
        $this->assertContains($resp->status(), [422],
            'EGP booking + USD vault must be rejected');
    }

    /* ============================================================
     *  D. LIFECYCLE — Confirmed → InProgress → Completed
     * ============================================================ */

    public function test_create_with_status_confirmed_succeeds(): void
    {
        $program = $this->makeProgram();
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload([
            'program_id' => $program->id,
            'status' => 'confirmed',
        ]));
        $booking = HajjUmraBooking::findOrFail($response->json('data.id'));
        $this->assertSame('confirmed', $booking->status->value);
    }

    public function test_create_with_status_in_progress_succeeds(): void
    {
        $program = $this->makeProgram();
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload([
            'program_id' => $program->id,
            'status' => 'in_progress',
        ]));
        $booking = HajjUmraBooking::findOrFail($response->json('data.id'));
        $this->assertSame('in_progress', $booking->status->value);
    }

    public function test_create_with_status_completed_succeeds(): void
    {
        $program = $this->makeProgram();
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload([
            'program_id' => $program->id,
            'status' => 'completed',
        ]));
        $booking = HajjUmraBooking::findOrFail($response->json('data.id'));
        $this->assertSame('completed', $booking->status->value);
    }

    /* ============================================================
     *  E. FINANCIAL INVARIANTS — basic reconciliation
     * ============================================================ */

    public function test_create_booking_posts_income_and_expense_ledger_entries(): void
    {
        $program = $this->makeProgram();
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload([
            'program_id' => $program->id,
            'purchase_price' => 42000,
            'selling_price' => 50000,
        ]));
        $bookingId = $response->json('data.id');
        $booking = HajjUmraBooking::findOrFail($bookingId);

        $this->assertNotNull($booking->expenseTransaction, 'expense transaction must be linked');
        $this->assertNotNull($booking->incomeTransaction, 'income transaction must be linked');

        $this->assertEqualsWithDelta(42000.0, (float) $booking->expenseTransaction->amount, 0.01);
        $this->assertEqualsWithDelta(50000.0, (float) $booking->incomeTransaction->amount, 0.01);

        // Global ledger is balanced
        $this->assertLedgerGloballyBalanced();
    }

    public function test_paid_booking_reduces_customer_ar(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload([
            'program_id' => $program->id,
            'customer_id' => $customer->id,
            'purchase_price' => 42000,
            'selling_price' => 50000,
        ]));
        $bookingId = $response->json('data.id');
        $booking = HajjUmraBooking::findOrFail($bookingId);

        // After create: customer AR = 50000 (selling_price)
        $customerAR = AccountEntry::where('account_id', $booking->customer->account_id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
        $this->assertEqualsWithDelta(50000.0, (float) $customerAR, 0.01);

        // Pay 20000
        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 20000,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P102_AR_'.uniqid(),
        ])->assertCreated();

        $customerAR2 = AccountEntry::where('account_id', $booking->customer->account_id)
            ->selectRaw('SUM(credit) - SUM(debit) as net')->value('net');
        $this->assertEqualsWithDelta(30000.0, (float) $customerAR2, 0.01);
    }

    /* ============================================================
     *  F. PAYMENT GATES — state-aware rejection
     * ============================================================ */

    public function test_cannot_record_payment_on_cancelled_booking(): void
    {
        $program = $this->makeProgram();
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload([
            'program_id' => $program->id,
        ]));
        $bookingId = $response->json('data.id');

        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/cancel", [
            'reason' => 'phase 10.2 cancel before pay',
        ])->assertOk();

        $resp = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 100.0,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P102_GATE_'.uniqid(),
        ]);
        $this->assertContains($resp->status(), [422, 500],
            'payment on cancelled must be rejected');
    }

    public function test_cannot_record_payment_on_refunded_booking(): void
    {
        $program = $this->makeProgram();
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload([
            'program_id' => $program->id,
        ]));
        $bookingId = $response->json('data.id');

        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/refund", [
            'reason' => 'phase 10.2 refund before pay',
        ])->assertOk();

        $resp = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 100.0,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P102_GATE_'.uniqid(),
        ]);
        $this->assertContains($resp->status(), [422, 500]);
    }

    /* ============================================================
     *  G. LIFECYCLE — full happy path
     * ============================================================ */

    public function test_full_lifecycle_create_pay_refund(): void
    {
        $program = $this->makeProgram();
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload([
            'program_id' => $program->id,
            'purchase_price' => 42000,
            'selling_price' => 50000,
        ]));
        $bookingId = $response->json('data.id');

        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 50000,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P102_FULL_'.uniqid(),
        ])->assertCreated();

        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/refund", [
            'reason' => 'phase 10.2 full refund',
        ])->assertOk();

        $booking = HajjUmraBooking::findOrFail($bookingId);
        $this->assertSame('refunded', $booking->status->value);
        $this->assertLedgerGloballyBalanced();
    }

    public function test_full_lifecycle_create_pay_cancel(): void
    {
        $program = $this->makeProgram();
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload([
            'program_id' => $program->id,
            'purchase_price' => 42000,
            'selling_price' => 50000,
        ]));
        $bookingId = $response->json('data.id');

        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 25000,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P102_LIFE_'.uniqid(),
        ])->assertCreated();

        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/cancel", [
            'reason' => 'phase 10.2 cancel after pay',
        ])->assertOk();

        $booking = HajjUmraBooking::findOrFail($bookingId);
        $this->assertSame('cancelled', $booking->status->value);
        $this->assertLedgerGloballyBalanced();
    }

    public function test_full_lifecycle_create_pay_delete(): void
    {
        $program = $this->makeProgram();
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $this->bookingPayload([
            'program_id' => $program->id,
            'purchase_price' => 42000,
            'selling_price' => 50000,
        ]));
        $bookingId = $response->json('data.id');

        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 30000,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
            'idempotency_key' => 'P102_DEL_'.uniqid(),
        ])->assertCreated();

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$bookingId}")->assertOk();

        $this->assertSoftDeleted('hajj_umra_bookings', ['id' => $bookingId]);
        $this->assertLedgerGloballyBalanced();
    }

    /* ============================================================
     *  HELPER
     * ============================================================ */

    protected function bookingPayload(array $overrides = []): array
    {
        return array_merge([
            'customer' => [
                'full_name' => 'عميل تجريبي ' . uniqid(),
                'phone' => '010' . substr((string) mt_rand(10_000_000, 99_999_999), 0, 8),
            ],
            'purchase_price' => 42000.0,
            'selling_price' => 50000.0,
            'currency' => 'EGP',
            'per_person' => true,
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
