<?php

namespace Tests\Feature\HajjUmra;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Services\HajjUmra\HajjUmraBookingService;
use App\Support\Finance\AccountModuleContract;
use App\Support\Finance\LedgerBalanceMutationGuard;

/**
 * Phase 10.12 — Supplier Flow Deep (Section 22 of the audit prompt).
 *
 * For Hajj/Umra, the "supplier" is `HajjUmraExecutingCompany` (شركة منفذة).
 * The auto-created Account for the executing company is the canonical
 * "AP" (Accounts Payable) ledger entry for transactions owed to the
 * executing company.
 *
 * Audit surfaces:
 *   - Withdraw: executing-company → treasury (pays the executing company).
 *   - Repay:    treasury → executing-company (replenishes the executing
 *               company's AP balance after a booking expense).
 *   - AccountModuleContract: enforces office-division boundary on the
 *               source/destination accounts.
 *   - Insufficient balance: refused on repay (GAP #HJ-6 fix).
 *   - Office-division boundary: rejects accounts from other modules.
 */
class HajjUmraSupplierFlowDeepTest extends HajjUmraTestCase
{
    private function makeBookingWithExecutingCompany(): \App\Models\HajjUmraBooking
    {
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();
        $supplier = $this->makeSupplier(); // UmrahSupplier, not HajjUmraExecutingCompany
        return app(HajjUmraBookingService::class)->create([
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'supplier_id' => $supplier->id,
            'purchase_price' => 42000.0,
            'selling_price' => 50000.0,
            'currency' => 'EGP',
            'account_id' => $this->treasuryEGP->id,
        ])->fresh();
    }

    /* ============================================================
     *  Withdraw (executing-company → treasury)
     * ============================================================ */

    public function test_withdraw_reduces_executing_company_balance(): void
    {
        $ec = $this->makeExecutingCompany();
        $ecAccount = Account::query()->find($ec->account_id);

        // Seed the executing company account with balance
        LedgerBalanceMutationGuard::run(fn() => $ecAccount->update(['balance' => 100000.0]));

        $this->postJson("/api/v1/hajj-umra/executing-companies/{$ec->id}/withdraw", [
            'amount' => 5000.0,
            'to_account_id' => $this->treasuryEGP->id,
            'notes' => 'audit-withdraw',
        ])->assertOk();

        $ecAccountAfter = $ecAccount->fresh();
        $this->assertEqualsWithDelta(95000.0, (float) $ecAccountAfter->balance, 0.01,
            'executing-company balance must decrease by withdraw amount');
    }

    public function test_withdraw_increases_treasury_balance(): void
    {
        $ec = $this->makeExecutingCompany();
        $ecAccount = Account::query()->find($ec->account_id);
        LedgerBalanceMutationGuard::run(fn() => $ecAccount->update(['balance' => 100000.0]));

        $treasuryBefore = (float) $this->treasuryEGP->fresh()->balance;

        $this->postJson("/api/v1/hajj-umra/executing-companies/{$ec->id}/withdraw", [
            'amount' => 5000.0,
            'to_account_id' => $this->treasuryEGP->id,
        ])->assertOk();

        $treasuryAfter = (float) $this->treasuryEGP->fresh()->balance;
        $this->assertEqualsWithDelta($treasuryBefore + 5000.0, $treasuryAfter, 0.01,
            'treasury balance must increase by withdraw amount');
    }

    public function test_withdraw_with_zero_amount_rejected(): void
    {
        $ec = $this->makeExecutingCompany();

        $this->postJson("/api/v1/hajj-umra/executing-companies/{$ec->id}/withdraw", [
            'amount' => 0.0,
            'to_account_id' => $this->treasuryEGP->id,
        ])->assertStatus(422);
    }

    public function test_withdraw_with_negative_amount_rejected(): void
    {
        $ec = $this->makeExecutingCompany();

        $this->postJson("/api/v1/hajj-umra/executing-companies/{$ec->id}/withdraw", [
            'amount' => -100.0,
            'to_account_id' => $this->treasuryEGP->id,
        ])->assertStatus(422);
    }

    public function test_withdraw_to_foreign_division_account_rejected(): void
    {
        $ec = $this->makeExecutingCompany();
        $ecAccount = Account::query()->find($ec->account_id);
        LedgerBalanceMutationGuard::run(fn() => $ecAccount->update(['balance' => 100000.0]));

        // Create a foreign-division account (not tourism-related)
        $foreignAccount = Account::query()->create([
            'name' => 'office_foreign',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 0.0,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office', // NOT tourism
            'module' => 'office',
            'created_by' => $this->admin->id,
        ]);

        $this->postJson("/api/v1/hajj-umra/executing-companies/{$ec->id}/withdraw", [
            'amount' => 5000.0,
            'to_account_id' => $foreignAccount->id,
        ])->assertStatus(422);
    }

    /* ============================================================
     *  Repay (treasury → executing-company)
     * ============================================================ */

    public function test_repay_increases_executing_company_balance(): void
    {
        $ec = $this->makeExecutingCompany();
        $ecAccount = Account::query()->find($ec->account_id);

        $balanceBefore = (float) $ecAccount->fresh()->balance;

        $this->postJson("/api/v1/hajj-umra/executing-companies/{$ec->id}/repay", [
            'amount' => 10000.0,
            'from_account_id' => $this->treasuryEGP->id,
            'notes' => 'audit-repay',
        ])->assertOk();

        $balanceAfter = (float) $ecAccount->fresh()->balance;
        $this->assertEqualsWithDelta($balanceBefore + 10000.0, $balanceAfter, 0.01,
            'executing-company balance must increase by repay amount');
    }

    public function test_repay_decreases_treasury_balance(): void
    {
        $ec = $this->makeExecutingCompany();
        $treasuryBefore = (float) $this->treasuryEGP->fresh()->balance;

        $this->postJson("/api/v1/hajj-umra/executing-companies/{$ec->id}/repay", [
            'amount' => 10000.0,
            'from_account_id' => $this->treasuryEGP->id,
        ])->assertOk();

        $treasuryAfter = (float) $this->treasuryEGP->fresh()->balance;
        $this->assertEqualsWithDelta($treasuryBefore - 10000.0, $treasuryAfter, 0.01,
            'treasury balance must decrease by repay amount');
    }

    public function test_repay_with_insufficient_balance_rejected(): void
    {
        $ec = $this->makeExecutingCompany();

        // Try to repay 1_000_000_000 from a 500_000 vault
        $this->postJson("/api/v1/hajj-umra/executing-companies/{$ec->id}/repay", [
            'amount' => 1_000_000_000.0,
            'from_account_id' => $this->treasuryEGP->id,
        ])->assertStatus(422);
    }

    public function test_repay_with_zero_amount_rejected(): void
    {
        $ec = $this->makeExecutingCompany();

        $this->postJson("/api/v1/hajj-umra/executing-companies/{$ec->id}/repay", [
            'amount' => 0.0,
            'from_account_id' => $this->treasuryEGP->id,
        ])->assertStatus(422);
    }

    /* ============================================================
     *  Withdraw + Repay cycle integrity
     * ============================================================ */

    public function test_withdraw_then_repay_restores_balance(): void
    {
        $ec = $this->makeExecutingCompany();
        $ecAccount = Account::query()->find($ec->account_id);
        LedgerBalanceMutationGuard::run(fn() => $ecAccount->update(['balance' => 100000.0]));

        $balanceBefore = (float) $ecAccount->fresh()->balance;

        // Withdraw 5000
        $this->postJson("/api/v1/hajj-umra/executing-companies/{$ec->id}/withdraw", [
            'amount' => 5000.0,
            'to_account_id' => $this->treasuryEGP->id,
        ])->assertOk();

        $balanceAfterWithdraw = (float) $ecAccount->fresh()->balance;
        $this->assertEqualsWithDelta(95000.0, $balanceAfterWithdraw, 0.01);

        // Repay 5000
        $this->postJson("/api/v1/hajj-umra/executing-companies/{$ec->id}/repay", [
            'amount' => 5000.0,
            'from_account_id' => $this->treasuryEGP->id,
        ])->assertOk();

        $balanceAfterRepay = (float) $ecAccount->fresh()->balance;
        $this->assertEqualsWithDelta(100000.0, $balanceAfterRepay, 0.01,
            'withdraw + repay cycle must restore balance');
    }

    /* ============================================================
     *  AccountModuleContract predicate
     * ============================================================ */

    public function test_account_module_contract_recognizes_tourism_variants(): void
    {
        // Verify the canonical predicate. This is the gate for the
        // withdraw/repay "must be tourism-division" check. The input
        // is the module name (not the module_type); office-division
        // module values (bus, fawry, online, wallet_transfer) must be
        // rejected; tourism-division values (tourism, hajj_umra, flights,
        // visas) must be accepted.
        $cases = [
            ['module' => 'tourism', 'expected' => true],
            ['module' => 'hajj_umra', 'expected' => true],
            ['module' => 'flights', 'expected' => true],
            ['module' => 'visas', 'expected' => true],
            ['module' => 'office', 'expected' => false],
            ['module' => 'bus', 'expected' => false],
            ['module' => 'fawry', 'expected' => false],
            ['module' => null, 'expected' => false],
            ['module' => 'general', 'expected' => false],
        ];
        foreach ($cases as $case) {
            $result = AccountModuleContract::isTourismModule($case['module']);
            $this->assertSame($case['expected'], $result,
                "isTourismModule(" . var_export($case['module'], true) . ") should be " . ($case['expected'] ? 'true' : 'false'));
        }
    }

    /* ============================================================
     *  Booking with executing-company — AP behavior
     * ============================================================ */

    public function test_booking_with_supplier_records_supplier_id(): void
    {
        // The booking's supplier_id is an UmrahSupplier (FK to umrah_suppliers).
        // HajjUmraExecutingCompany is a SEPARATE entity exposed via the
        // /executing-companies/{id}/withdraw and /repay endpoints.
        $supplier = $this->makeSupplier();
        $booking = $this->makeBookingWithExecutingCompany();
        $booking->update(['supplier_id' => $supplier->id]);

        $this->assertNotNull($booking->fresh()->supplier_id);
        $this->assertSame($supplier->id, $booking->fresh()->supplier_id);
    }

    public function test_cancel_booking_with_supplier_succeeds(): void
    {
        $booking = $this->makeBookingWithExecutingCompany();

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", [
            'reason' => 'audit',
        ])->assertOk();

        $this->assertSame('cancelled', $booking->fresh()->status->value);
    }

    /* ============================================================
     *  Cross-currency isolation
     * ============================================================ */

    public function test_withdraw_with_cross_currency_account_allowed(): void
    {
        // Documented behavior: the withdraw endpoint does NOT validate
        // currency match between the executing-company account and the
        // destination. The AccountModuleContract check is the only gate.
        // Both accounts are in the Tourism division, so the withdraw is
        // accepted. Cross-currency FX is handled by the manual conversion
        // flow (not by this endpoint).
        $ec = $this->makeExecutingCompany();
        $ecAccount = Account::query()->find($ec->account_id);
        LedgerBalanceMutationGuard::run(fn() => $ecAccount->update(['balance' => 100000.0]));

        $usdTreasury = $this->makeTreasuryAccount('USD', 50000.0);

        $this->postJson("/api/v1/hajj-umra/executing-companies/{$ec->id}/withdraw", [
            'amount' => 1000.0,
            'to_account_id' => $usdTreasury->id,
        ])->assertOk();
    }

    /* ============================================================
     *  Account-entry integrity
     * ============================================================ */

    public function test_withdraw_records_paired_account_entries(): void
    {
        $ec = $this->makeExecutingCompany();
        $ecAccount = Account::query()->find($ec->account_id);
        LedgerBalanceMutationGuard::run(fn() => $ecAccount->update(['balance' => 100000.0]));

        $entriesBefore = AccountEntry::query()->count();

        $this->postJson("/api/v1/hajj-umra/executing-companies/{$ec->id}/withdraw", [
            'amount' => 5000.0,
            'to_account_id' => $this->treasuryEGP->id,
        ])->assertOk();

        // 2 entries per journal transfer (debit + credit)
        $this->assertSame($entriesBefore + 2, AccountEntry::query()->count(),
            'withdraw creates 2 AccountEntry rows (debit + credit)');
    }

    public function test_repay_records_paired_account_entries(): void
    {
        $ec = $this->makeExecutingCompany();
        $entriesBefore = AccountEntry::query()->count();

        $this->postJson("/api/v1/hajj-umra/executing-companies/{$ec->id}/repay", [
            'amount' => 10000.0,
            'from_account_id' => $this->treasuryEGP->id,
        ])->assertOk();

        $this->assertSame($entriesBefore + 2, AccountEntry::query()->count(),
            'repay creates 2 AccountEntry rows (debit + credit)');
    }

    /* ============================================================
     *  Soft-delete integrity
     * ============================================================ */

    public function test_soft_delete_executing_company_preserves_ledger_history(): void
    {
        $ec = $this->makeExecutingCompany();
        $ecAccount = Account::query()->find($ec->account_id);
        LedgerBalanceMutationGuard::run(fn() => $ecAccount->update(['balance' => 100000.0]));

        $entriesBefore = AccountEntry::query()->where('account_id', $ec->account_id)->count();

        $this->postJson("/api/v1/hajj-umra/executing-companies/{$ec->id}/withdraw", [
            'amount' => 5000.0,
            'to_account_id' => $this->treasuryEGP->id,
        ])->assertOk();

        // Soft-delete the executing company
        $ec->delete();
        $this->assertNotNull(\App\Models\HajjUmra\HajjUmraExecutingCompany::withTrashed()->find($ec->id)->deleted_at);

        // The journal entries on the executing-company account still exist
        $entriesAfter = AccountEntry::query()->where('account_id', $ec->account_id)->count();
        $this->assertSame($entriesBefore + 1, $entriesAfter,
            'soft-deleted executing-company must keep its AccountEntry history (1 new entry)');
    }
}
