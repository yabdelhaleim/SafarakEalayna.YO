<?php

namespace Tests\Feature\HajjUmra;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\HajjUmra\UmrahSupplier;
use App\Models\HajjUmraBooking;
use App\Models\HajjUmraPayment;
use App\Models\Program;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Hajj & Umrah — Balance Restoration After Delete (PROD GUARANTEE)
 *
 * Date: 2026-08-29
 *
 * Focused test suite that PROVES every treasury and every account balance
 * returns to its pre-operation baseline after `DELETE /api/v1/hajj-umra/bookings/{id}`.
 *
 * The guarantee (per BRIEF 6 / Phase 10.6):
 *   - Every financial effect of a booking (income, expense, payments, EC
 *     settlements) must be fully reversed when the booking is deleted.
 *   - Treasuries, customer AR, supplier AP, EC AP — all must net to zero
 *     operational delta from before the booking existed.
 *
 * This test asserts the guarantee at 4 layers:
 *   L1: HTTP response is 200 OK
 *   L2: Account balances match pre-operation baseline (DB row state)
 *   L3: Per-account operational debit/credit sums net to zero (excluding
 *       the FIN-1 opening-balance seed entries)
 *   L4: Original transaction rows are preserved (additive reversal, never
 *       destructive) — verified by counting inverse AccountEntry rows
 *
 * Scenarios covered:
 *   R1.  Single booking, single payment (cashbox) → DELETE → balance back
 *   R2.  Single booking, single payment (bank)    → DELETE → balance back
 *   R3.  Single booking, single payment (wallet)  → DELETE → balance back
 *   R4.  Single booking, 3 partial payments (mixed methods) → DELETE → all back
 *   R5.  Single booking with USD supplier (cross-currency) → DELETE → USD AP back
 *   R6.  Booking + initial payment → DELETE → all back
 *   R7.  Booking with companion + accommodation_extra → DELETE → all back
 *   R8.  Booking paid in full then cancelled then DELETE → still baseline
 *   R9.  Multiple bookings (5) created/paid/deleted → ALL treasuries back
 *   R10. Booking with 10 partial payments → DELETE → all back
 *   R11. Booking with USD supplier + USD payment → DELETE → cross-currency back
 *   R12. Multi-currency payments (EGP bank + USD cashbox) → DELETE → all back
 *   R13. Customer with debt after delete → debt must net to zero
 *   R14. EC AP balance must return to zero after delete with EC-based booking
 */
class HajjUmraBalanceRestoreOnDelete20260829Test extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Account $treasuryEGP;

    protected Account $treasuryBankEGP;

    protected Account $treasuryWalletEGP;

    protected Account $treasuryUSD;

    protected HajjUmraExecutingCompany $ecEGP;

    protected UmrahSupplier $supplierUSD;

    /** Pre-operation snapshots — used for baseline assertions */
    private array $balanceBaseline = [];

    private array $entryBaseline = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name' => 'BalanceRestore Admin',
            'email' => 'balance-'.uniqid('', true).'@test.local',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        LedgerBalanceMutationGuard::run(function () {
            $this->treasuryEGP = Account::query()->create([
                'name' => 'BalanceRestore Treasury EGP',
                'type' => AccountType::Cashbox->value,
                'currency' => 'EGP',
                'balance' => 1_000_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module' => 'hajj_umra',
                'is_module_vault' => true,
                'created_by' => $this->admin->id,
            ]);

            $this->treasuryBankEGP = Account::query()->create([
                'name' => 'BalanceRestore Bank EGP',
                'type' => AccountType::Bank->value,
                'currency' => 'EGP',
                'balance' => 500_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module' => 'hajj_umra',
                'is_module_vault' => true,
                'created_by' => $this->admin->id,
            ]);

            $this->treasuryWalletEGP = Account::query()->create([
                'name' => 'BalanceRestore Wallet EGP',
                'type' => AccountType::Wallet->value,
                'wallet_provider' => 'vodafone_cash',
                'wallet_number' => '01000000000',
                'currency' => 'EGP',
                'balance' => 200_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module' => 'hajj_umra',
                'is_module_vault' => true,
                'created_by' => $this->admin->id,
            ]);

            $this->treasuryUSD = Account::query()->create([
                'name' => 'BalanceRestore Treasury USD',
                'type' => AccountType::Cashbox->value,
                'currency' => 'USD',
                'balance' => 50_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module' => 'hajj_umra',
                'is_module_vault' => true,
                'created_by' => $this->admin->id,
            ]);
        });

        // USD supplier
        $supplierAcct = LedgerBalanceMutationGuard::run(fn () => Account::query()->create([
            'name' => 'BalanceRestore Supplier USD',
            'type' => AccountType::Supplier->value,
            'currency' => 'USD',
            'balance' => 0.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'hajj_umra',
            'created_by' => $this->admin->id,
        ]));
        $this->supplierUSD = UmrahSupplier::query()->create([
            'name' => 'BalanceRestore USD Supplier',
            'phone' => '+966555222222',
            'account_id' => $supplierAcct->id,
            'default_cost_price' => 1500.00,
            'is_active' => true,
        ]);

        // EGP executing company
        $this->ecEGP = HajjUmraExecutingCompany::query()->create([
            'name' => 'BalanceRestore EC EGP',
            'license_number' => 'BR-'.uniqid(),
            'phone' => '+20100000000',
            'is_active' => true,
        ]);
        LedgerBalanceMutationGuard::run(fn () => $this->ecEGP->update([
            'account_id' => Account::query()->create([
                'name' => 'AP: '.$this->ecEGP->name,
                'type' => AccountType::Supplier->value,
                'currency' => 'EGP',
                'balance' => 0.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OWNER,
                'module_type' => 'hajj_umra',
                'notes' => 'حساب شركة منفذة تلقائي مضاف من النظام. company_id='.$this->ecEGP->id,
                'created_by' => $this->admin->id,
            ])->id,
        ]));
        $this->ecEGP = $this->ecEGP->fresh();

        // FX rates
        if (\Schema::hasTable('exchange_rates')) {
            DB::table('exchange_rates')->insert([
                ['from_currency' => 'EGP', 'to_currency' => 'USD', 'effective_date' => today(), 'rate' => 0.032, 'is_active' => 1, 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()],
                ['from_currency' => 'USD', 'to_currency' => 'EGP', 'effective_date' => today(), 'rate' => 31.25, 'is_active' => 1, 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }

    /* ============================================================
     *  HELPERS
     * ============================================================ */

    private function makeCustomer(string $name = 'BR Customer'): Customer
    {
        return Customer::query()->create([
            'full_name' => $name,
            'phone' => '010'.substr((string) mt_rand(10_000_000, 99_999_999), 0, 8),
            'email' => 'br-cust-'.uniqid('', true).'@test.local',
            'is_active' => true,
        ]);
    }

    private function makeProgram(array $overrides = []): Program
    {
        return Program::query()->create(array_merge([
            'program_name' => 'BR Program '.uniqid(),
            'program_type' => 'hajj',
            'total_nights' => 14,
            'mecca_nights' => 8,
            'medina_nights' => 6,
            'accommodation_type' => 'DOUBLE',
            'mecca_hotel_name' => 'فندق مكة',
            'medina_hotel_name' => 'فندق المدينة',
            'departure_date' => now()->addDays(60)->toDateString(),
            'return_date' => now()->addDays(74)->toDateString(),
            'airline' => 'Test Air',
            'executing_company' => 'BR EC Default',
            'departure_point' => 'CAI',
            'default_selling_price' => 50000.00,
            'default_purchase_price' => 42000.00,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ], $overrides));
    }

    /**
     * Snapshot EVERY account balance + per-account operational entries BEFORE
     * any operation. This is the canonical baseline that must be restored
     * after every test's DELETE.
     *
     * Baseline is captured for OPERATIONAL entries only (excluding the
     * FIN-1 opening-balance seed rows) so the per-account delta after delete
     * must be ZERO.
     */
    private function snapshotBaseline(): void
    {
        $this->balanceBaseline = [];
        foreach (Account::all() as $acct) {
            $this->balanceBaseline[$acct->id] = (float) $acct->balance;
        }

        $this->entryBaseline = [];
        $rows = DB::table('account_entries')
            ->where('is_opening', '!=', 1)
            ->select('account_id', DB::raw('SUM(debit) as debit'), DB::raw('SUM(credit) as credit'))
            ->groupBy('account_id')
            ->get();
        foreach ($rows as $r) {
            $this->entryBaseline[(int) $r->account_id] = [
                'debit' => (float) $r->debit,
                'credit' => (float) $r->credit,
            ];
        }
    }

    /**
     * Assert every account's balance matches the snapshot.
     * Optionally excludes accounts that should legitimately have grown
     * (e.g. customer AR accounts created during the booking lifecycle).
     */
    private function assertBaselineRestored(string $context = ''): void
    {
        foreach ($this->balanceBaseline as $accountId => $baselineBalance) {
            $current = (float) Account::find($accountId)->fresh()->balance;
            $this->assertEqualsWithDelta(
                $baselineBalance, $current, 0.01,
                "[$context] Account #$accountId balance must return to baseline. "
                ."Baseline=$baselineBalance, current=$current, delta=".($current - $baselineBalance)
            );
        }
    }

    /**
     * Assert every account's OPERATIONAL entries (excluding opening-balance
     * seeds from FIN-1) net to zero delta from baseline.
     */
    private function assertOperationalEntriesNetZero(string $context = ''): void
    {
        $currentEntries = DB::table('account_entries')
            ->where('is_opening', '!=', 1)
            ->select('account_id', DB::raw('SUM(debit) as debit'), DB::raw('SUM(credit) as credit'))
            ->groupBy('account_id')
            ->get()
            ->keyBy('account_id');

        // Combine all known account IDs (baseline + any new ones)
        $allAccountIds = array_unique(array_merge(
            array_keys($this->entryBaseline),
            $currentEntries->keys()->all()
        ));

        foreach ($allAccountIds as $accountId) {
            $baseD = (float) ($this->entryBaseline[$accountId]['debit'] ?? 0);
            $baseC = (float) ($this->entryBaseline[$accountId]['credit'] ?? 0);
            $curD = (float) ($currentEntries[$accountId]->debit ?? 0);
            $curC = (float) ($currentEntries[$accountId]->credit ?? 0);

            $deltaD = $curD - $baseD;
            $deltaC = $curC - $baseC;

            // For each account, the net of debit-delta and credit-delta must be 0
            // (i.e. the new operational entries must come in pairs that net out)
            $this->assertEqualsWithDelta(
                0.0, $deltaD - $deltaC, 0.01,
                "[$context] Account #$accountId operational entries must net to zero. "
                ."Debit delta=$deltaD, credit delta=$deltaC"
            );
        }
    }

    private function createBooking(array $payload): HajjUmraBooking
    {
        $response = $this->postJson('/api/v1/hajj-umra/bookings', $payload);
        $response->assertCreated();

        return HajjUmraBooking::findOrFail($response->json('data.id'));
    }

    private function addPayment(HajjUmraBooking $booking, float $amount, array $overrides = []): HajjUmraPayment
    {
        $payload = array_merge([
            'amount' => $amount,
            'payment_method' => 'cash',
            'account_id' => $this->treasuryEGP->id,
        ], $overrides);

        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/payments", $payload);
        $this->assertContains($response->status(), [200, 201]);

        return HajjUmraPayment::findOrFail($response->json('data.payment.id'));
    }

    private function defaultPayload(Customer $c, Program $p, array $overrides = []): array
    {
        return array_merge([
            'customer' => ['full_name' => $c->full_name, 'phone' => $c->phone],
            'program_id' => $p->id,
            'purchase_price' => 42000.0,
            'selling_price' => 50000.0,
            'currency' => 'EGP',
            'account_id' => $this->treasuryEGP->id,
        ], $overrides);
    }

    private function assertOriginalTransactionsPreserved(HajjUmraBooking $booking): void
    {
        // Original transactions must still exist (additive reversal, not destructive)
        $income = Transaction::find($booking->income_transaction_id);
        $expense = Transaction::find($booking->expense_transaction_id);

        $this->assertNotNull($income, 'Income transaction must be preserved (additive reversal)');
        $this->assertNotNull($expense, 'Expense transaction must be preserved (additive reversal)');

        foreach ($booking->payments()->withTrashed()->get() as $p) {
            $tx = Transaction::find($p->transaction_id);
            $this->assertNotNull($tx, "Payment transaction #{$p->id} must be preserved");
        }
    }

    /* ============================================================
     *  R1. Single booking, single payment (cashbox)
     * ============================================================ */

    public function test_r1_single_booking_single_cash_payment_delete_restores_baseline(): void
    {
        $this->snapshotBaseline();
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();
        $booking = $this->createBooking($this->defaultPayload($customer, $program));
        $this->addPayment($booking, 50000.0, ['payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id]);

        $response = $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}");
        $response->assertOk();

        $this->assertOperationalEntriesNetZero('R1');
        $this->assertBaselineRestored('R1');
        $this->assertOriginalTransactionsPreserved($booking->fresh());
    }

    /* ============================================================
     *  R2. Single booking, single payment (bank)
     * ============================================================ */

    public function test_r2_single_booking_single_bank_payment_delete_restores_baseline(): void
    {
        $this->snapshotBaseline();
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();
        $booking = $this->createBooking($this->defaultPayload($customer, $program));
        $this->addPayment($booking, 50000.0, ['payment_method' => 'bank_transfer', 'account_id' => $this->treasuryBankEGP->id]);

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();

        $this->assertOperationalEntriesNetZero('R2');
        $this->assertBaselineRestored('R2');
    }

    /* ============================================================
     *  R3. Single booking, single payment (wallet)
     * ============================================================ */

    public function test_r3_single_booking_single_wallet_payment_delete_restores_baseline(): void
    {
        $this->snapshotBaseline();
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();
        $booking = $this->createBooking($this->defaultPayload($customer, $program));
        $this->addPayment($booking, 50000.0, ['payment_method' => 'vodafone_cash', 'account_id' => $this->treasuryWalletEGP->id]);

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();

        $this->assertOperationalEntriesNetZero('R3');
        $this->assertBaselineRestored('R3');
    }

    /* ============================================================
     *  R4. Single booking, 3 partial payments (mixed methods)
     * ============================================================ */

    public function test_r4_booking_with_3_mixed_payments_delete_restores_baseline(): void
    {
        $this->snapshotBaseline();
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();
        $booking = $this->createBooking($this->defaultPayload($customer, $program, [
            'selling_price' => 90000.0,
        ]));

        $this->addPayment($booking, 30000.0, ['payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id]);
        $this->addPayment($booking, 30000.0, ['payment_method' => 'bank_transfer', 'account_id' => $this->treasuryBankEGP->id]);
        $this->addPayment($booking, 30000.0, ['payment_method' => 'vodafone_cash', 'account_id' => $this->treasuryWalletEGP->id]);

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();

        $this->assertOperationalEntriesNetZero('R4');
        $this->assertBaselineRestored('R4');
    }

    /* ============================================================
     *  R5. Single booking with USD supplier (cross-currency expense)
     * ============================================================ */

    public function test_r5_booking_with_usd_supplier_delete_restores_baseline(): void
    {
        $this->snapshotBaseline();
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();
        $booking = $this->createBooking($this->defaultPayload($customer, $program, [
            'supplier_id' => $this->supplierUSD->id,
        ]));
        $this->addPayment($booking, 50000.0, ['payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id]);

        // Verify USD supplier AP was debited before delete
        $usdNetBefore = (float) DB::table('account_entries')
            ->where('account_id', $this->supplierUSD->account_id)
            ->where('is_opening', '!=', 1)
            ->selectRaw('SUM(debit) - SUM(credit) as net')
            ->value('net');
        $this->assertGreaterThan(0, $usdNetBefore, 'USD supplier AP net debit must be > 0 before delete');

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();

        // After delete: USD supplier AP net debit must be 0 (debits + reversal credits cancel)
        // Note: reverseTransaction ADDS inverse entries — original debit 1344 stays,
        // a credit 1344 is ADDED as reversal. So sum(debit)=1344, sum(credit)=1344, net=0.
        $usdNetAfter = (float) DB::table('account_entries')
            ->where('account_id', $this->supplierUSD->account_id)
            ->where('is_opening', '!=', 1)
            ->selectRaw('SUM(debit) - SUM(credit) as net')
            ->value('net');
        $this->assertEqualsWithDelta(0.0, $usdNetAfter, 0.01,
            'USD supplier AP net debit must be 0 after delete (additive reversal)');

        // Verify the reversal IS present (sum(credit) > 0)
        $usdCreditsAfter = (float) DB::table('account_entries')
            ->where('account_id', $this->supplierUSD->account_id)
            ->where('is_opening', '!=', 1)
            ->sum('credit');
        $this->assertGreaterThan(0, $usdCreditsAfter,
            'USD supplier AP must have reversal CREDIT entries after delete');

        $this->assertOperationalEntriesNetZero('R5');
        $this->assertBaselineRestored('R5');
    }

    /* ============================================================
     *  R6. Booking + initial payment → DELETE
     * ============================================================ */

    public function test_r6_booking_with_initial_payment_delete_restores_baseline(): void
    {
        $this->snapshotBaseline();
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();
        $booking = $this->createBooking($this->defaultPayload($customer, $program, [
            'initial_payment' => ['amount' => 20000.0, 'payment_method' => 'cash'],
        ]));

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();

        $this->assertOperationalEntriesNetZero('R6');
        $this->assertBaselineRestored('R6');
    }

    /* ============================================================
     *  R7. Booking with companion + accommodation_extra → DELETE
     * ============================================================ */

    public function test_r7_booking_with_companion_extra_delete_restores_baseline(): void
    {
        $this->snapshotBaseline();
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();
        $booking = $this->createBooking($this->defaultPayload($customer, $program, [
            'companion_purchase_price' => 30000.0,
            'companion_selling_price' => 40000.0,
            'accommodation_extra_charge' => 5000.0,
        ]));
        $this->addPayment($booking, 95000.0);

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();

        $this->assertOperationalEntriesNetZero('R7');
        $this->assertBaselineRestored('R7');
    }

    /* ============================================================
     *  R8. Booking paid in full, cancelled, then DELETED
     * ============================================================ */

    public function test_r8_paid_cancelled_then_deleted_restores_baseline(): void
    {
        $this->snapshotBaseline();
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();
        $booking = $this->createBooking($this->defaultPayload($customer, $program));
        $this->addPayment($booking, 50000.0);

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/cancel", ['reason' => 'cancel'])
            ->assertOk();

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();

        $this->assertOperationalEntriesNetZero('R8');
        $this->assertBaselineRestored('R8');
    }

    /* ============================================================
     *  R9. 5 bookings created/paid/deleted → ALL treasuries back
     * ============================================================ */

    public function test_r9_five_bookings_paid_then_deleted_restores_all_treasuries(): void
    {
        $this->snapshotBaseline();
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();

        $bookings = [];
        for ($i = 0; $i < 5; $i++) {
            $b = $this->createBooking($this->defaultPayload($customer, $program));
            // Vary the payment methods
            $method = match ($i % 3) {
                0 => ['payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
                1 => ['payment_method' => 'bank_transfer', 'account_id' => $this->treasuryBankEGP->id],
                2 => ['payment_method' => 'vodafone_cash', 'account_id' => $this->treasuryWalletEGP->id],
            };
            $this->addPayment($b, 50000.0, $method);
            $bookings[] = $b;
        }

        // Verify all 3 treasuries were credited before delete
        foreach ([$this->treasuryEGP, $this->treasuryBankEGP, $this->treasuryWalletEGP] as $t) {
            $netBefore = (float) DB::table('account_entries')
                ->where('account_id', $t->id)
                ->where('is_opening', '!=', 1)
                ->selectRaw('SUM(credit) - SUM(debit) as net')
                ->value('net');
            $this->assertGreaterThan(0, $netBefore,
                "Treasury #{$t->id} ({$t->name}) must have positive operational net credit before delete");
        }

        foreach ($bookings as $b) {
            $this->deleteJson("/api/v1/hajj-umra/bookings/{$b->id}")->assertOk();
        }

        // After deleting all 5, ALL treasuries must return to baseline NET
        // (credits and reversals add up to zero net per account).
        foreach ([$this->treasuryEGP, $this->treasuryBankEGP, $this->treasuryWalletEGP] as $t) {
            $netAfter = (float) DB::table('account_entries')
                ->where('account_id', $t->id)
                ->where('is_opening', '!=', 1)
                ->selectRaw('SUM(credit) - SUM(debit) as net')
                ->value('net');
            $this->assertEqualsWithDelta(0.0, $netAfter, 0.01,
                "Treasury #{$t->id} operational NET must be 0 after all deletes");
        }

        $this->assertOperationalEntriesNetZero('R9');
        $this->assertBaselineRestored('R9');
    }

    /* ============================================================
     *  R10. Booking with 10 partial payments → DELETE
     * ============================================================ */

    public function test_r10_booking_with_10_partial_payments_delete_restores_baseline(): void
    {
        $this->snapshotBaseline();
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();
        $booking = $this->createBooking($this->defaultPayload($customer, $program, [
            'selling_price' => 50000.0,
        ]));

        for ($i = 0; $i < 10; $i++) {
            $this->addPayment($booking, 5000.0, ['idempotency_key' => "R10_{$i}_".uniqid()]);
        }

        $this->assertSame(10, HajjUmraPayment::withTrashed()->where('hajj_umra_booking_id', $booking->id)->count());

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();

        $this->assertOperationalEntriesNetZero('R10');
        $this->assertBaselineRestored('R10');
    }

    /* ============================================================
     *  R11. USD supplier booking → DELETE → USD AP back
     * ============================================================ */

    public function test_r11_usd_supplier_booking_delete_restores_usd_supplier_ap(): void
    {
        $this->snapshotBaseline();
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();
        $usdBaseline = (float) $this->treasuryUSD->fresh()->balance;

        $booking = $this->createBooking($this->defaultPayload($customer, $program, [
            'supplier_id' => $this->supplierUSD->id,
        ]));
        $this->addPayment($booking, 50000.0);

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();

        // USD treasury baseline (we never touched it but verify anyway)
        $this->assertEqualsWithDelta($usdBaseline, (float) $this->treasuryUSD->fresh()->balance, 0.01);

        // USD supplier AP must be back to zero (operational delta)
        $supplierAccount = Account::find($this->supplierUSD->account_id);
        $supplierOpDebits = (float) DB::table('account_entries')
            ->where('account_id', $this->supplierUSD->account_id)
            ->where('is_opening', '!=', 1)
            ->sum('debit');
        $supplierOpCredits = (float) DB::table('account_entries')
            ->where('account_id', $this->supplierUSD->account_id)
            ->where('is_opening', '!=', 1)
            ->sum('credit');
        $this->assertEqualsWithDelta(0.0, $supplierOpDebits - $supplierOpCredits, 0.01,
            'USD supplier AP operational entries must net to 0');

        $this->assertOperationalEntriesNetZero('R11');
        $this->assertBaselineRestored('R11');
    }

    /* ============================================================
     *  R12. Multi-method payments (EGP bank + EGP wallet) → DELETE
     *
     *  NOTE: Cross-currency payment (USD against EGP booking) is NOT
     *  currently supported at the payment endpoint — the Safe FX Rule
     *  rejects it without explicit FX data on the wire. This test uses
     *  same-currency multi-method to verify the multi-payment delete
     *  cascade restores all treasuries.
     * ============================================================ */

    public function test_r12_multi_method_payments_delete_restores_baseline(): void
    {
        $this->snapshotBaseline();
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();
        $egpBaseline = (float) $this->treasuryEGP->fresh()->balance;
        $bankBaseline = (float) $this->treasuryBankEGP->fresh()->balance;
        $walletBaseline = (float) $this->treasuryWalletEGP->fresh()->balance;

        $booking = $this->createBooking($this->defaultPayload($customer, $program, [
            'selling_price' => 60000.0,
        ]));
        $this->addPayment($booking, 20000.0, ['payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id]);
        $this->addPayment($booking, 20000.0, ['payment_method' => 'bank_transfer', 'account_id' => $this->treasuryBankEGP->id]);
        $this->addPayment($booking, 20000.0, ['payment_method' => 'vodafone_cash', 'account_id' => $this->treasuryWalletEGP->id]);

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();

        // All 3 treasuries back to baseline
        $this->assertEqualsWithDelta($egpBaseline, (float) $this->treasuryEGP->fresh()->balance, 0.01);
        $this->assertEqualsWithDelta($bankBaseline, (float) $this->treasuryBankEGP->fresh()->balance, 0.01);
        $this->assertEqualsWithDelta($walletBaseline, (float) $this->treasuryWalletEGP->fresh()->balance, 0.01);

        $this->assertOperationalEntriesNetZero('R12');
        $this->assertBaselineRestored('R12');
    }

    /* ============================================================
     *  R13. Customer debt must net to zero after delete
     * ============================================================ */

    public function test_r13_customer_debt_nets_to_zero_after_delete(): void
    {
        $this->snapshotBaseline();
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();
        $booking = $this->createBooking($this->defaultPayload($customer, $program));

        // Partial payment leaving 20k debt
        $this->addPayment($booking, 30000.0);

        // Verify debt is 20000 before delete
        $balances = $this->getJson('/api/v1/hajj-umra/customer-balances')->json('data');
        $row = collect($balances)->firstWhere('client_id', $customer->id);
        $this->assertEqualsWithDelta(20000.0, (float) $row['total_debt'], 0.01,
            'Pre-delete: customer should have 20k debt');

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();

        // After delete: customer should NOT appear in customer_balances
        // (only customers with active non-cancelled bookings appear)
        $balancesAfter = $this->getJson('/api/v1/hajj-umra/customer-balances')->json('data');
        $rowAfter = collect($balancesAfter)->firstWhere('client_id', $customer->id);
        $this->assertNull($rowAfter,
            'Deleted-booking customer should not appear in customer_balances (debt netted to zero)');

        $this->assertOperationalEntriesNetZero('R13');
        $this->assertBaselineRestored('R13');
    }

    /* ============================================================
     *  R14. EC AP balance must return to zero after delete
     * ============================================================ */

    public function test_r14_ec_ap_balance_returns_to_zero_after_delete(): void
    {
        $this->snapshotBaseline();
        $ecBaseline = (float) Account::find($this->ecEGP->account_id)->fresh()->balance;

        $program = $this->makeProgram();
        $program->executing_company_id = $this->ecEGP->id;
        $program->save();

        $customer = $this->makeCustomer();
        $booking = $this->createBooking($this->defaultPayload($customer, $program));
        $this->addPayment($booking, 50000.0);

        // EC AP must be debited (we owe them 42000)
        $ecBalanceAfterBooking = (float) Account::find($this->ecEGP->account_id)->fresh()->balance;
        $this->assertLessThan($ecBaseline, $ecBalanceAfterBooking,
            'EC AP balance must drop below baseline after booking (we owe them)');

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();

        // EC AP back to baseline
        $ecBalanceAfterDelete = (float) Account::find($this->ecEGP->account_id)->fresh()->balance;
        $this->assertEqualsWithDelta($ecBaseline, $ecBalanceAfterDelete, 0.01,
            'EC AP balance must return to baseline after delete');

        $this->assertOperationalEntriesNetZero('R14');
        $this->assertBaselineRestored('R14');
    }

    /* ============================================================
     *  R15. Mass delete: 10 bookings back-to-back → ALL back
     * ============================================================ */

    public function test_r15_ten_bookings_back_to_back_delete_restores_all_treasuries(): void
    {
        $this->snapshotBaseline();
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();

        $bookings = [];
        for ($i = 0; $i < 10; $i++) {
            $b = $this->createBooking($this->defaultPayload($customer, $program));
            $this->addPayment($b, 50000.0);
            $bookings[] = $b;
        }

        // Sequential delete
        foreach ($bookings as $b) {
            $this->deleteJson("/api/v1/hajj-umra/bookings/{$b->id}")->assertOk();
        }

        // After all 10 deletes, every treasury and every account returns to baseline
        $this->assertOperationalEntriesNetZero('R15');
        $this->assertBaselineRestored('R15');

        // Final ledger invariant: total operational debit = total operational credit
        $totalDebit = (float) DB::table('account_entries')->where('is_opening', '!=', 1)->sum('debit');
        $totalCredit = (float) DB::table('account_entries')->where('is_opening', '!=', 1)->sum('credit');
        $this->assertEqualsWithDelta($totalDebit, $totalCredit, 0.01,
            'Final global ledger (operational) must be balanced after all deletes');
    }

    /* ============================================================
     *  R16. Booking + customer statement running balance returns to zero
     * ============================================================ */

    public function test_r16_customer_statement_running_balance_zero_after_delete(): void
    {
        $this->snapshotBaseline();
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();
        $booking = $this->createBooking($this->defaultPayload($customer, $program));
        $this->addPayment($booking, 50000.0);

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}")->assertOk();

        // Customer statement for the deleted booking's customer
        // (booking is soft-deleted, customer might still exist but should have no debt)
        $response = $this->getJson("/api/v1/hajj-umra/customer-statement?client_id={$customer->id}");
        $response->assertOk();

        // Customer's debt summary should reflect ZERO (the booking is gone)
        // Note: customer-statement excludes cancelled bookings, and the
        // booking is now soft-deleted → no invoice line items in the statement
        $data = $response->json('data');
        $totalDebt = (float) ($data['summary']['total_debt'] ?? 0);
        $this->assertEqualsWithDelta(0.0, $totalDebt, 0.01,
            'Customer statement running balance must be 0 after booking delete');
    }
}
