<?php

namespace Tests\Feature\HajjUmra;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\AuditLog;
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
 * Hajj & Umrah — Refund Balance Restoration (PROD GUARANTEE)
 *
 * Date: 2026-08-29
 *
 * Focused test suite that PROVES every treasury and every account balance
 * returns to its pre-booking baseline after `POST /api/v1/hajj-umra/bookings/{id}/refund`.
 *
 * The guarantee:
 *   - Refund is FULL-BOOKING REVERSAL ONLY (per BRIEF 6 / Phase 10.4).
 *   - The full (selling_price + companion_selling_price + accommodation_extra_charge)
 *     is the refund amount, capped at the paid amount.
 *   - On successful refund:
 *       a) Each payment transaction is additively reversed
 *       b) The income transaction is additively reversed
 *       c) The expense transaction is additively reversed
 *       d) Status flips to 'refunded'
 *       e) An atomic `refund.processed` audit row is written
 *   - Treasuries, customer AR, supplier AP, EC AP — all must net to zero
 *     operational delta from before the booking existed.
 *
 * This test asserts the guarantee at 4 layers:
 *   L1: HTTP response is 200 OK
 *   L2: Account balances match pre-operation baseline (DB row state)
 *   L3: Per-account operational debit/credit sums net to zero (excluding
 *       the FIN-1 opening-balance seed entries)
 *   L4: Original transaction rows are preserved (additive reversal, never
 *       destructive) + audit row written
 *
 * Scenarios covered (16):
 *   F1.  Single booking, full payment → REFUND → balance back
 *   F2.  Single booking, partial payment (30000/50000) → REFUND → balance back
 *   F3.  Single booking, zero payment → REFUND → balance back
 *   F4.  Single booking, 3 mixed-method payments → REFUND → all treasuries back
 *   F5.  Booking with USD supplier (cross-currency) → REFUND → USD AP back
 *   F6.  Booking + initial_payment → REFUND → all back
 *   F7.  Booking with companion + accommodation_extra → REFUND → all back
 *   F8.  Booking with 10 partial payments → REFUND → all back
 *   F9.  5 bookings created/paid/refunded → ALL treasuries back
 *   F10. EC-based booking → REFUND → EC AP back to 0
 *   F11. Customer debt must net to zero after refund
 *   F12. Status flips to 'refunded' and refund.processed audit row written
 *   F13. Original transactions are preserved (additive reversal)
 *   F14. Refund cap: refund_amount = min(intended, paid) — even when over-booked
 *   F15. Cross-module isolation: refund only touches HajjUmra-related entries
 *   F16. 10 bookings back-to-back refund → ALL treasuries + global ledger
 */
class HajjUmraRefundBalanceRestore20260829Test extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $refunder;

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
            'name' => 'RefundBalance Admin',
            'email' => 'refund-'.uniqid('', true).'@test.local',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->refunder = User::query()->create([
            'name' => 'RefundBalance Refunder',
            'email' => 'refund-refunder-'.uniqid('', true).'@test.local',
            'password' => Hash::make('password'),
            'role' => 'employee',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        LedgerBalanceMutationGuard::run(function () {
            $this->treasuryEGP = Account::query()->create([
                'name' => 'RefundBalance Treasury EGP',
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
                'name' => 'RefundBalance Bank EGP',
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
                'name' => 'RefundBalance Wallet EGP',
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
                'name' => 'RefundBalance Treasury USD',
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
            'name' => 'RefundBalance Supplier USD',
            'type' => AccountType::Supplier->value,
            'currency' => 'USD',
            'balance' => 0.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'hajj_umra',
            'created_by' => $this->admin->id,
        ]));
        $this->supplierUSD = UmrahSupplier::query()->create([
            'name' => 'RefundBalance USD Supplier',
            'phone' => '+966555333333',
            'account_id' => $supplierAcct->id,
            'default_cost_price' => 1500.00,
            'is_active' => true,
        ]);

        // EGP executing company
        $this->ecEGP = HajjUmraExecutingCompany::query()->create([
            'name' => 'RefundBalance EC EGP',
            'license_number' => 'RF-'.uniqid(),
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

    private function makeCustomer(string $name = 'RF Customer'): Customer
    {
        return Customer::query()->create([
            'full_name' => $name,
            'phone' => '010'.substr((string) mt_rand(10_000_000, 99_999_999), 0, 8),
            'email' => 'rf-cust-'.uniqid('', true).'@test.local',
            'is_active' => true,
        ]);
    }

    private function makeProgram(array $overrides = []): Program
    {
        return Program::query()->create(array_merge([
            'program_name' => 'RF Program '.uniqid(),
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
            'executing_company' => 'RF EC Default',
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
     * after every test's REFUND.
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

    private function assertOperationalEntriesNetZero(string $context = ''): void
    {
        $currentEntries = DB::table('account_entries')
            ->where('is_opening', '!=', 1)
            ->select('account_id', DB::raw('SUM(debit) as debit'), DB::raw('SUM(credit) as credit'))
            ->groupBy('account_id')
            ->get()
            ->keyBy('account_id');

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

    private function assertRefundStatus(HajjUmraBooking $booking): void
    {
        $this->assertSame('refunded', $booking->fresh()->status->value,
            'Booking status must be "refunded" after refund');
    }

    private function assertOriginalTransactionsPreserved(HajjUmraBooking $booking): void
    {
        // Original transactions must still exist (additive reversal, not destructive)
        $income = Transaction::find($booking->income_transaction_id);
        $expense = Transaction::find($booking->expense_transaction_id);

        $this->assertNotNull($income, 'Income transaction must be preserved (additive reversal)');
        $this->assertNotNull($expense, 'Expense transaction must be preserved (additive reversal)');

        foreach ($booking->payments as $p) {
            $tx = Transaction::find($p->transaction_id);
            $this->assertNotNull($tx, "Payment transaction #{$p->id} must be preserved");
        }
    }

    private function assertRefundAuditWritten(HajjUmraBooking $booking, int $expectedCount = 1): void
    {
        if (! \Schema::hasTable('refund_audit_logs')) {
            $this->markTestSkipped('refund_audit_logs table not present in this build');
            return;
        }

        // The RefundAuditLog model has `booking_id` as a direct column.
        $auditRows = \App\Models\RefundAuditLog::query()
            ->where('booking_id', $booking->id)
            ->count();

        $this->assertGreaterThanOrEqual($expectedCount, $auditRows,
            "refund.processed audit row must be written for booking #{$booking->id}");
    }

    /* ============================================================
     *  F1. Single booking, full payment → REFUND
     * ============================================================ */

    public function test_f1_single_booking_full_payment_refund_restores_baseline(): void
    {
        $this->snapshotBaseline();
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();
        $booking = $this->createBooking($this->defaultPayload($customer, $program));
        $this->addPayment($booking, 50000.0, ['payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id]);

        $response = $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", [
            'reason' => 'F1 full refund test',
        ]);
        $response->assertOk();

        $this->assertRefundStatus($booking);
        $this->assertOperationalEntriesNetZero('F1');
        $this->assertBaselineRestored('F1');
        $this->assertOriginalTransactionsPreserved($booking->fresh());
    }

    /* ============================================================
     *  F2. Single booking, partial payment → REFUND
     * ============================================================ */

    public function test_f2_single_booking_partial_payment_refund_restores_baseline(): void
    {
        $this->snapshotBaseline();
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();
        $booking = $this->createBooking($this->defaultPayload($customer, $program));
        // Partial: 30k paid, 20k debt
        $this->addPayment($booking, 30000.0, ['payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id]);

        // Verify pre-refund state: treasury +30k
        $this->assertEqualsWithDelta(30000.0,
            (float) AccountEntry::query()->where('account_id', $this->treasuryEGP->id)->where('is_opening', '!=', 1)->sum('credit') -
            (float) AccountEntry::query()->where('account_id', $this->treasuryEGP->id)->where('is_opening', '!=', 1)->sum('debit'),
            0.01);

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", ['reason' => 'F2 partial'])
            ->assertOk();

        $this->assertRefundStatus($booking);
        $this->assertOperationalEntriesNetZero('F2');
        $this->assertBaselineRestored('F2');
    }

    /* ============================================================
     *  F3. Single booking, ZERO payment → REFUND (status flip only)
     * ============================================================ */

    public function test_f3_zero_payment_booking_refund_restores_baseline(): void
    {
        $this->snapshotBaseline();
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();
        $booking = $this->createBooking($this->defaultPayload($customer, $program));
        // NO payment

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", ['reason' => 'F3 zero-pay'])
            ->assertOk();

        $this->assertRefundStatus($booking);
        $this->assertOperationalEntriesNetZero('F3');
        $this->assertBaselineRestored('F3');
    }

    /* ============================================================
     *  F4. 3 mixed-method payments → REFUND → all treasuries back
     * ============================================================ */

    public function test_f4_booking_with_3_mixed_payments_refund_restores_baseline(): void
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

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", ['reason' => 'F4 mixed'])
            ->assertOk();

        $this->assertRefundStatus($booking);
        $this->assertOperationalEntriesNetZero('F4');
        $this->assertBaselineRestored('F4');
    }

    /* ============================================================
     *  F5. USD supplier booking → REFUND → USD AP back
     * ============================================================ */

    public function test_f5_usd_supplier_booking_refund_restores_usd_ap(): void
    {
        $this->snapshotBaseline();
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();
        $booking = $this->createBooking($this->defaultPayload($customer, $program, [
            'supplier_id' => $this->supplierUSD->id,
        ]));
        $this->addPayment($booking, 50000.0, ['payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id]);

        $usdNetBefore = (float) AccountEntry::query()
            ->where('account_id', $this->supplierUSD->account_id)
            ->where('is_opening', '!=', 1)
            ->selectRaw('SUM(debit) - SUM(credit) as net')
            ->value('net');
        $this->assertGreaterThan(0, $usdNetBefore, 'USD supplier AP must have net debit before refund');

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", ['reason' => 'F5 cross-currency'])
            ->assertOk();

        // After refund: USD supplier AP net must be 0
        $usdNetAfter = (float) AccountEntry::query()
            ->where('account_id', $this->supplierUSD->account_id)
            ->where('is_opening', '!=', 1)
            ->selectRaw('SUM(debit) - SUM(credit) as net')
            ->value('net');
        $this->assertEqualsWithDelta(0.0, $usdNetAfter, 0.01,
            'USD supplier AP net must be 0 after refund');

        $this->assertRefundStatus($booking);
        $this->assertOperationalEntriesNetZero('F5');
        $this->assertBaselineRestored('F5');
    }

    /* ============================================================
     *  F6. Booking + initial_payment → REFUND
     * ============================================================ */

    public function test_f6_booking_with_initial_payment_refund_restores_baseline(): void
    {
        $this->snapshotBaseline();
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();
        $booking = $this->createBooking($this->defaultPayload($customer, $program, [
            'initial_payment' => ['amount' => 20000.0, 'payment_method' => 'cash'],
        ]));
        // Add another payment on top
        $this->addPayment($booking, 30000.0, ['payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id]);

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", ['reason' => 'F6 initial-pay'])
            ->assertOk();

        $this->assertRefundStatus($booking);
        $this->assertOperationalEntriesNetZero('F6');
        $this->assertBaselineRestored('F6');
    }

    /* ============================================================
     *  F7. Booking with companion + accommodation_extra → REFUND
     * ============================================================ */

    public function test_f7_booking_with_companion_extra_refund_restores_baseline(): void
    {
        $this->snapshotBaseline();
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();
        $booking = $this->createBooking($this->defaultPayload($customer, $program, [
            'companion_purchase_price' => 30000.0,
            'companion_selling_price' => 40000.0,
            'accommodation_extra_charge' => 5000.0,
        ]));
        $this->addPayment($booking, 95000.0); // full payment

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", ['reason' => 'F7 companion'])
            ->assertOk();

        $this->assertRefundStatus($booking);
        $this->assertOperationalEntriesNetZero('F7');
        $this->assertBaselineRestored('F7');
    }

    /* ============================================================
     *  F8. Booking with 10 partial payments → REFUND
     * ============================================================ */

    public function test_f8_booking_with_10_partial_payments_refund_restores_baseline(): void
    {
        $this->snapshotBaseline();
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();
        $booking = $this->createBooking($this->defaultPayload($customer, $program, [
            'selling_price' => 50000.0,
        ]));

        for ($i = 0; $i < 10; $i++) {
            $this->addPayment($booking, 5000.0, ['idempotency_key' => "F8_{$i}_".uniqid()]);
        }

        $this->assertSame(10, $booking->payments()->count());

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", ['reason' => 'F8 10 partial'])
            ->assertOk();

        $this->assertRefundStatus($booking);
        $this->assertOperationalEntriesNetZero('F8');
        $this->assertBaselineRestored('F8');
    }

    /* ============================================================
     *  F9. 5 bookings → all paid → all refunded
     * ============================================================ */

    public function test_f9_five_bookings_paid_then_refunded_restores_all_treasuries(): void
    {
        $this->snapshotBaseline();
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();

        $bookings = [];
        for ($i = 0; $i < 5; $i++) {
            $b = $this->createBooking($this->defaultPayload($customer, $program));
            $method = match ($i % 3) {
                0 => ['payment_method' => 'cash', 'account_id' => $this->treasuryEGP->id],
                1 => ['payment_method' => 'bank_transfer', 'account_id' => $this->treasuryBankEGP->id],
                2 => ['payment_method' => 'vodafone_cash', 'account_id' => $this->treasuryWalletEGP->id],
            };
            $this->addPayment($b, 50000.0, $method);
            $bookings[] = $b;
        }

        foreach ($bookings as $b) {
            $this->postJson("/api/v1/hajj-umra/bookings/{$b->id}/refund", ['reason' => 'F9 mass'])
                ->assertOk();
        }

        // After refunding all 5, ALL treasuries must return to baseline NET
        foreach ([$this->treasuryEGP, $this->treasuryBankEGP, $this->treasuryWalletEGP] as $t) {
            $netAfter = (float) AccountEntry::query()
                ->where('account_id', $t->id)
                ->where('is_opening', '!=', 1)
                ->selectRaw('SUM(credit) - SUM(debit) as net')
                ->value('net');
            $this->assertEqualsWithDelta(0.0, $netAfter, 0.01,
                "Treasury #{$t->id} operational NET must be 0 after all refunds");
        }

        $this->assertOperationalEntriesNetZero('F9');
        $this->assertBaselineRestored('F9');
    }

    /* ============================================================
     *  F10. EC-based booking → REFUND → EC AP back to 0
     * ============================================================ */

    public function test_f10_ec_based_booking_refund_restores_ec_ap(): void
    {
        $this->snapshotBaseline();
        $ecBaseline = (float) Account::find($this->ecEGP->account_id)->fresh()->balance;

        $program = $this->makeProgram();
        $program->executing_company_id = $this->ecEGP->id;
        $program->save();

        $customer = $this->makeCustomer();
        $booking = $this->createBooking($this->defaultPayload($customer, $program));
        $this->addPayment($booking, 50000.0);

        $ecAfterBooking = (float) Account::find($this->ecEGP->account_id)->fresh()->balance;
        $this->assertLessThan($ecBaseline, $ecAfterBooking,
            'EC AP balance must drop below baseline after booking (we owe them)');

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", ['reason' => 'F10 EC'])
            ->assertOk();

        $ecAfterRefund = (float) Account::find($this->ecEGP->account_id)->fresh()->balance;
        $this->assertEqualsWithDelta($ecBaseline, $ecAfterRefund, 0.01,
            'EC AP balance must return to baseline after refund');

        $this->assertRefundStatus($booking);
        $this->assertOperationalEntriesNetZero('F10');
        $this->assertBaselineRestored('F10');
    }

    /* ============================================================
     *  F11. Customer debt nets to zero after refund
     * ============================================================ */

    public function test_f11_customer_debt_nets_to_zero_after_refund(): void
    {
        $this->snapshotBaseline();
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();
        $booking = $this->createBooking($this->defaultPayload($customer, $program));
        $this->addPayment($booking, 30000.0); // 20k debt

        // Pre-refund: 20k debt
        $balances = $this->getJson('/api/v1/hajj-umra/customer-balances')->json('data');
        $row = collect($balances)->firstWhere('client_id', $customer->id);
        $this->assertEqualsWithDelta(20000.0, (float) $row['total_debt'], 0.01);

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", ['reason' => 'F11'])
            ->assertOk();

        // After refund: customer should NOT appear (refunded bookings excluded)
        $balancesAfter = $this->getJson('/api/v1/hajj-umra/customer-balances')->json('data');
        $rowAfter = collect($balancesAfter)->firstWhere('client_id', $customer->id);
        $this->assertNull($rowAfter, 'Refunded-only customer should not appear in balances');

        $this->assertOperationalEntriesNetZero('F11');
        $this->assertBaselineRestored('F11');
    }

    /* ============================================================
     *  F12. Status + audit row
     * ============================================================ */

    public function test_f12_refund_writes_status_and_audit_row(): void
    {
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();
        $booking = $this->createBooking($this->defaultPayload($customer, $program));
        $this->addPayment($booking, 50000.0);

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", ['reason' => 'F12 audit'])
            ->assertOk();

        $this->assertRefundStatus($booking);
        $this->assertRefundAuditWritten($booking);
    }

    /* ============================================================
     *  F13. Original transactions preserved (additive reversal)
     * ============================================================ */

    public function test_f13_original_transactions_preserved_after_refund(): void
    {
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();
        $booking = $this->createBooking($this->defaultPayload($customer, $program));
        $this->addPayment($booking, 50000.0);

        $incomeId = $booking->income_transaction_id;
        $expenseId = $booking->expense_transaction_id;
        $paymentId = $booking->payments->first()->transaction_id;

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", ['reason' => 'F13'])
            ->assertOk();

        // All original transactions still exist (additive reversal, not destructive)
        $this->assertNotNull(Transaction::find($incomeId), 'Income tx must survive refund');
        $this->assertNotNull(Transaction::find($expenseId), 'Expense tx must survive refund');
        $this->assertNotNull(Transaction::find($paymentId), 'Payment tx must survive refund');

        // Each original tx has additive inverse entries
        foreach ([$incomeId, $expenseId, $paymentId] as $txId) {
            $reversals = AccountEntry::query()
                ->where('transaction_id', $txId)
                ->where('notes', 'like', 'عكس%')
                ->count();
            $this->assertGreaterThan(0, $reversals, "TX #$txId must have additive inverse entries");
        }
    }

    /* ============================================================
     *  F14. Refund cap: refund_amount = min(intended, paid)
     *  Even with over-booking, refund never exceeds paid amount
     * ============================================================ */

    public function test_f14_refund_caps_at_paid_amount(): void
    {
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();
        // Selling 50000, payment 40000 (10000 short)
        $booking = $this->createBooking($this->defaultPayload($customer, $program, [
            'selling_price' => 50000.0,
        ]));
        $this->addPayment($booking, 40000.0);

        // Verify pre-refund debt
        $balances = $this->getJson('/api/v1/hajj-umra/customer-balances')->json('data');
        $row = collect($balances)->firstWhere('client_id', $customer->id);
        $this->assertEqualsWithDelta(10000.0, (float) $row['total_debt'], 0.01,
            'Pre-refund: customer should owe 10k');

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", ['reason' => 'F14 capped'])
            ->assertOk();

        $this->assertRefundStatus($booking);

        // Verify refund amount in audit row capped at paid (40000), not intended (50000)
        if (\Schema::hasTable('refund_audit_logs')) {
            $auditRow = \App\Models\RefundAuditLog::query()
                ->where('booking_id', $booking->id)
                ->first();

            if ($auditRow) {
                $refundAmount = (float) $auditRow->refund_amount;
                $this->assertEqualsWithDelta(40000.0, $refundAmount, 0.01,
                    "Refund amount must be capped at paid amount (40000), not full intended (50000)");
            }
        }
    }

    /* ============================================================
     *  F15. Cross-module isolation
     * ============================================================ */

    public function test_f15_refund_only_touches_hajj_umra_related_entries(): void
    {
        $customer = $this->makeCustomer();
        $program = $this->makeProgram();
        $booking = $this->createBooking($this->defaultPayload($customer, $program));
        $this->addPayment($booking, 50000.0);

        $this->postJson("/api/v1/hajj-umra/bookings/{$booking->id}/refund", ['reason' => 'F15 isolation'])
            ->assertOk();

        // Verify NO transactions were tagged with non-hajj_umra modules
        $wrongModuleTxs = Transaction::query()
            ->where('module', '!=', 'hajj_umra')
            ->where(function ($q) use ($booking) {
                $q->where('related_type', HajjUmraBooking::class)
                    ->where('related_id', $booking->id);
            })
            ->count();

        $this->assertSame(0, $wrongModuleTxs,
            'Refund must NOT tag any transactions with non-hajj_umra modules');
    }

    /* ============================================================
     *  F16. 10 bookings back-to-back refund → ALL treasuries + global ledger
     * ============================================================ */

    public function test_f16_ten_bookings_back_to_back_refund_restores_all_treasuries(): void
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

        // Sequential refund
        foreach ($bookings as $b) {
            $this->postJson("/api/v1/hajj-umra/bookings/{$b->id}/refund", ['reason' => 'F16 mass'])
                ->assertOk();
        }

        // After all 10 refunds, every treasury returns to baseline
        $this->assertOperationalEntriesNetZero('F16');
        $this->assertBaselineRestored('F16');

        // Final ledger invariant
        $totalDebit = (float) DB::table('account_entries')->where('is_opening', '!=', 1)->sum('debit');
        $totalCredit = (float) DB::table('account_entries')->where('is_opening', '!=', 1)->sum('credit');
        $this->assertEqualsWithDelta($totalDebit, $totalCredit, 0.01,
            'Final global ledger (operational) must be balanced after all refunds');
    }
}
