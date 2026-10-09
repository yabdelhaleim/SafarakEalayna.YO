<?php

namespace Tests\Feature\HajjUmra;

use App\Enums\AccountType;
use App\Enums\HajjUmraPaymentMethod;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\HajjUmraBooking;
use App\Models\Program;
use App\Models\Transaction;
use App\Models\User;
use App\Services\HajjUmra\HajjUmraBookingService;
use App\Services\HajjUmra\HajjUmraRefundService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Hajj/Umrah — Refund Accounting Deep Test (T0/T1/T2 Snapshot Pattern)
 *
 * Date: 2026-08-29
 * Purpose: Prove that HajjUmraRefundService::refund() restores EVERY account
 *          (vault, bank, wallet, EC AP, customer AR, supplier AP) back to its
 *          pre-booking (T0) state, every time, regardless of the booking
 *          shape (currency, EC/supplier, mixed payments, companion, etc.).
 *
 * Pattern: T0 = pre-booking snapshot, T1 = post-booking+payments snapshot,
 *          T2 = post-refund snapshot. T0 == T2 is the invariant.
 *
 * Complements the prior HajjUmraRefundDeepAuditTest (15 tests) and
 * HajjUmraRefundBalanceRestore20260829Test (16 tests) with focused T0==T2
 * invariants and fresh edge cases the prior suite didn't cover.
 */
class HajjUmraRefundT012DeepTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Account $vaultEgp;
    protected Account $bankEgp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::query()->create([
            'name' => 'Refund Admin',
            'email' => 'refund-'.uniqid('', true).'@test.local',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        LedgerBalanceMutationGuard::run(function () {
            $this->vaultEgp = Account::query()->create(['name' => 'V-EGP', 'type' => AccountType::Cashbox->value, 'currency' => 'EGP', 'balance' => 5_000_000.00, 'is_active' => true, 'owner_type' => Account::OWNER_TYPE_OFFICE, 'module_type' => 'tourism', 'module' => 'hajj_umra', 'is_module_vault' => true, 'created_by' => $this->admin->id]);
            $this->bankEgp  = Account::query()->create(['name' => 'B-EGP', 'type' => AccountType::Bank->value,    'currency' => 'EGP', 'balance' => 2_000_000.00, 'is_active' => true, 'owner_type' => Account::OWNER_TYPE_OFFICE, 'module_type' => 'tourism', 'module' => 'hajj_umra', 'is_module_vault' => true, 'created_by' => $this->admin->id]);
        });
    }

    /* =====================================================================
     *  T0/T1/T2 — Vault-direct booking (no EC) with full payment
     * ===================================================================== */

    public function test_T012_vault_direct_booking_full_pay_refund_T0_eq_T2(): void
    {
        $program = $this->makeVaultDirectProgram();

        $t0 = $this->snapshotAll();

        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP', $this->vaultEgp->id);
        $this->addPay($booking->id, 50000.0, 'cash', $this->vaultEgp->id);

        $t1 = $this->snapshotAll();
        $t0Vault = (float) ($t0[$this->vaultEgp->id] ?? 0);
        $t1Vault = (float) ($t1[$this->vaultEgp->id] ?? 0);
        $this->assertNotEquals($t0Vault, $t1Vault, 'T0 != T1 (booking + payment must move vault)');

        $this->refund($booking->id, 'T0_eq_T2');

        $t2 = $this->snapshotAll();
        $this->assertT0EqT2($t0, $t2);
        $this->assertLedgerGloballyBalanced();
    }

    /* =====================================================================
     *  T0/T1/T2 — Partial payment refund (only paid amount refunded)
     * ===================================================================== */

    public function test_T012_partial_payment_refund_only_reverses_paid(): void
    {
        $program = $this->makeVaultDirectProgram();

        $t0 = $this->snapshotAll();

        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP', $this->vaultEgp->id);
        $this->addPay($booking->id, 20000.0, 'cash', $this->vaultEgp->id);

        app(HajjUmraRefundService::class)->refund($booking->fresh(), 'partial-refund');

        $t2 = $this->snapshotAll();

        // refund caps at paid → reverses full payment (vault -20k back) + full booking (vault +42k back) + income (-50k back)
        // net vault effect from refund: -(-20k) + (-(-42k)) = +62k... wait, refund REVERSES:
        //   payment: vault was +20k → reversal makes vault -20k
        //   income: customer AR was +50k → reversal makes -50k
        //   expense: vault was -42k → reversal makes +42k
        // Net vault change from refund: -20k + 42k = +22k
        // After refund vault net = -42k + 20k + 22k = 0
        // That matches T0 (vault at T0 has 0 net from operational entries)
        $this->assertT0EqT2($t0, $t2);
        $this->assertLedgerGloballyBalanced();
    }

    /* =====================================================================
     *  T0/T1/T2 — Zero-payment refund (void)
     * ===================================================================== */

    public function test_T012_zero_payment_refund_is_pure_void(): void
    {
        $program = $this->makeVaultDirectProgram();

        $t0 = $this->snapshotAll();

        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP', $this->vaultEgp->id);
        // no payments!

        app(HajjUmraRefundService::class)->refund($booking->fresh(), 'void-refund');

        $t2 = $this->snapshotAll();

        // zero-payment refund: still reverses booking income + expense → T0==T2
        $this->assertT0EqT2($t0, $t2);
        $this->assertLedgerGloballyBalanced();
    }

    /* =====================================================================
     *  T0/T1/T2 — Mixed payment methods (vault + bank + wallet)
     * ===================================================================== */

    public function test_T012_mixed_payment_methods_refund_T0_eq_T2(): void
    {
        $program = $this->makeVaultDirectProgram();
        $wallet = $this->makeWalletAccount();

        $t0 = $this->snapshotAll();

        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP', $this->vaultEgp->id);
        $this->addPay($booking->id, 20000.0, 'cash', $this->vaultEgp->id);
        $this->addPay($booking->id, 20000.0, 'bank_transfer', $this->bankEgp->id);
        $this->addPay($booking->id, 10000.0, 'cash_wallet', $wallet->id);

        $this->refund($booking->id, 'mixed-refund');

        $t2 = $this->snapshotAll();
        $this->assertT0EqT2($t0, $t2);
        $this->assertLedgerGloballyBalanced();
    }

    /* =====================================================================
     *  T0/T1/T2 — EC-based booking (program.executing_company_id set)
     * ===================================================================== */

    public function test_T012_ec_booking_refund_restores_ec_ap(): void
    {
        $program = $this->makeProgram(); // auto-creates EC
        $ec = \App\Models\HajjUmra\HajjUmraExecutingCompany::find($program->executing_company_id);
        $this->assertNotNull($ec);
        $ecAcct = Account::find($ec->fresh()->account_id);
        $this->assertNotNull($ecAcct);

        // T0: EC AP is 0 (auto-create doesn't post any entry)
        $t0Ec = $this->netFor($ecAcct->id);
        $this->assertEqualsWithDelta(0.0, $t0Ec, 0.02, 'sanity: EC AP at T0 must be 0');

        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP', $this->vaultEgp->id);
        $this->addPay($booking->id, 50000.0, 'cash', $this->vaultEgp->id);

        // after booking, EC AP must be negative (we owe supplier)
        $t1Ec = $this->netFor($ecAcct->id);
        $this->assertEqualsWithDelta(-42000.0, $t1Ec, 0.02, 'EC AP must be -42000 after booking');

        $this->refund($booking->id, 'ec-refund');

        // EC AP must return to T0 (zero)
        $t2Ec = $this->netFor($ecAcct->id);
        $this->assertEqualsWithDelta($t0Ec, $t2Ec, 0.02, 'EC AP must return to T0 after refund');
        $this->assertLedgerGloballyBalanced();
    }

    /* =====================================================================
     *  T0/T1/T2 — Booking with companion → refund restores companion purchase too
     * ===================================================================== */

    public function test_T012_companion_booking_refund_T0_eq_T2(): void
    {
        $program = $this->makeVaultDirectProgram();
        $customer = Customer::query()->create(['full_name' => 'C1', 'phone' => '01012000001', 'is_active' => true]);
        $companion = Customer::query()->create(['full_name' => 'C2', 'phone' => '01012000002', 'is_active' => true]);

        $t0 = $this->snapshotAll();

        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer' => ['full_name' => $customer->full_name, 'phone' => $customer->phone],
            'companion_customer_id' => $companion->id,
            'program_id' => $program->id,
            'purchase_price' => 42000.0,
            'selling_price' => 50000.0,
            'companion_purchase_price' => 30000.0,
            'companion_selling_price' => 35000.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
        ]);
        $resp->assertCreated();
        $bookingId = $resp->json('data.id');
        $this->addPay($bookingId, 85000.0, 'cash', $this->vaultEgp->id);

        $this->refund($bookingId, 'companion-refund');

        $t2 = $this->snapshotAll();
        $this->assertT0EqT2($t0, $t2);
        $this->assertLedgerGloballyBalanced();
    }

    /* =====================================================================
     *  Global Σ debit = Σ credit invariant after refund
     * ===================================================================== */

    public function test_T012_global_debit_eq_credit_after_refund(): void
    {
        $program = $this->makeVaultDirectProgram();
        for ($i = 0; $i < 5; $i++) {
            $b = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP', $this->vaultEgp->id);
            $this->addPay($b->id, 25000.0, 'cash', $this->vaultEgp->id);
            $this->refund($b->id, "bulk-$i");
        }

        $this->assertLedgerGloballyBalanced();
    }

    /* =====================================================================
     *  Reverse entries carry "عكس" prefix
     * ===================================================================== */

    public function test_T012_reverse_entries_carry_عكس_prefix(): void
    {
        $program = $this->makeVaultDirectProgram();
        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP', $this->vaultEgp->id);
        $this->addPay($booking->id, 25000.0, 'cash', $this->vaultEgp->id);

        $this->refund($booking->id, 'prefix-check');

        // count transactions prefixed with "عكس"
        $reverseNotes = Transaction::query()
            ->where('module', 'hajj_umra')
            ->where('notes', 'like', 'عكس%')
            ->count();
        $this->assertGreaterThan(0, $reverseNotes, 'at least one transaction must carry "عكس" prefix after refund');
    }

    /* =====================================================================
     *  Refund after cancel is rejected
     * ===================================================================== */

    public function test_T012_refund_after_cancel_rejected(): void
    {
        $program = $this->makeVaultDirectProgram();
        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP', $this->vaultEgp->id);
        $this->addPay($booking->id, 20000.0, 'cash', $this->vaultEgp->id);

        app(HajjUmraBookingService::class)->cancel($booking, 'cancel-first');

        $this->expectException(\RuntimeException::class);
        app(HajjUmraRefundService::class)->refund(HajjUmraBooking::find($booking->id), 'refund-after-cancel');
    }

    /* =====================================================================
     *  Refund after soft-delete is rejected
     * ===================================================================== */

    public function test_T012_refund_after_soft_delete_rejected(): void
    {
        $program = $this->makeVaultDirectProgram();
        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP', $this->vaultEgp->id);

        app(HajjUmraBookingService::class)->deleteBookingWithReversal($booking->id, $this->admin);

        $this->expectException(\RuntimeException::class);
        app(HajjUmraRefundService::class)->refund(HajjUmraBooking::withTrashed()->find($booking->id), 'refund-after-delete');
    }

    /* =====================================================================
     *  Double-refund idempotency
     * ===================================================================== */

    public function test_T012_double_refund_idempotent(): void
    {
        $program = $this->makeVaultDirectProgram();
        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP', $this->vaultEgp->id);
        $this->addPay($booking->id, 25000.0, 'cash', $this->vaultEgp->id);

        app(HajjUmraRefundService::class)->refund($booking->fresh(), 'first');

        $tAfterFirst = $this->snapshotAll();

        // second refund throws
        try {
            app(HajjUmraRefundService::class)->refund(HajjUmraBooking::find($booking->id), 'second');
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            // expected
        }

        // ledger state unchanged after rejected second refund
        $tAfterSecond = $this->snapshotAll();
        $this->assertSame($tAfterFirst, $tAfterSecond);
    }

    /* =====================================================================
     *  Audit log row count after refund
     * ===================================================================== */

    public function test_T012_refund_writes_exactly_one_audit_log_row(): void
    {
        $program = $this->makeVaultDirectProgram();
        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP', $this->vaultEgp->id);
        $this->addPay($booking->id, 25000.0, 'cash', $this->vaultEgp->id);

        app(HajjUmraRefundService::class)->refund($booking->fresh(), 'audit-test');

        $count = DB::table('refund_audit_logs')
            ->where('booking_id', $booking->id)
            ->where('module', 'hajj_umra')
            ->count();
        $this->assertSame(1, $count, 'exactly one refund.processed audit row must exist');
    }

    /* =====================================================================
     *  Helpers
     * ===================================================================== */

    protected function makeVaultDirectProgram(): Program
    {
        $program = Program::query()->create([
            'program_name' => 'P-'.uniqid(),
            'program_type' => 'hajj',
            'total_nights' => 14, 'mecca_nights' => 8, 'medina_nights' => 6,
            'accommodation_type' => 'DOUBLE',
            'mecca_hotel_name' => 'فندق مكة', 'medina_hotel_name' => 'فندق المدينة',
            'departure_date' => now()->addDays(60)->toDateString(),
            'return_date' => now()->addDays(74)->toDateString(),
            'airline' => 'Test Air', 'executing_company' => 'NONE-'.uniqid(),
            'departure_point' => 'CAI',
            'default_selling_price' => 50000.0, 'default_purchase_price' => 42000.0,
            'is_active' => true, 'created_by' => $this->admin->id,
        ]);
        // detach auto-created EC
        DB::table('programs')->where('id', $program->id)->update([
            'executing_company_id' => null, 'executing_company' => 'NONE',
        ]);
        return $program->fresh();
    }

    protected function makeProgram(): Program
    {
        return Program::query()->create([
            'program_name' => 'P-'.uniqid(),
            'program_type' => 'hajj',
            'total_nights' => 14, 'mecca_nights' => 8, 'medina_nights' => 6,
            'accommodation_type' => 'DOUBLE',
            'mecca_hotel_name' => 'فندق مكة', 'medina_hotel_name' => 'فندق المدينة',
            'departure_date' => now()->addDays(60)->toDateString(),
            'return_date' => now()->addDays(74)->toDateString(),
            'airline' => 'Test Air',
            'executing_company' => 'EC-'.uniqid(),
            'departure_point' => 'CAI',
            'default_selling_price' => 50000.0, 'default_purchase_price' => 42000.0,
            'is_active' => true, 'created_by' => $this->admin->id,
        ]);
    }

    protected function makeBooking(int $programId, float $purchase, float $selling, string $currency, int $vaultId): HajjUmraBooking
    {
        $customer = Customer::query()->create([
            'full_name' => 'C-'.uniqid(),
            'phone' => '01'.str_pad((string) random_int(100000000, 999999999), 9, '0', STR_PAD_LEFT),
            'is_active' => true,
        ]);
        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer' => ['full_name' => $customer->full_name, 'phone' => $customer->phone],
            'program_id' => $programId,
            'purchase_price' => $purchase,
            'selling_price' => $selling,
            'currency' => $currency,
            'account_id' => $vaultId,
        ]);
        $resp->assertCreated();
        return HajjUmraBooking::findOrFail($resp->json('data.id'));
    }

    protected function addPay(int $bookingId, float $amount, string $method, int $accountId): void
    {
        $resp = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => $amount,
            'payment_method' => $method,
            'account_id' => $accountId,
            'idempotency_key' => 'k-'.uniqid('', true),
        ]);
        $resp->assertCreated();
    }

    protected function refund(int $bookingId, string $reason): void
    {
        $resp = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/refund", [
            'reason' => $reason,
        ]);
        $resp->assertOk();
    }

    protected function snapshotAll(): array
    {
        return AccountEntry::query()
            ->where(function ($w) {
                $w->whereNull('is_opening')->orWhere('is_opening', '!=', 1);
            })
            ->get()
            ->groupBy('account_id')
            ->map(fn ($entries) => (float) $entries->sum('credit') - (float) $entries->sum('debit'))
            ->toArray();
    }

    protected function netFor(int $accountId): float
    {
        return (float) AccountEntry::where('account_id', $accountId)
            ->where(function ($w) {
                $w->whereNull('is_opening')->orWhere('is_opening', '!=', 1);
            })
            ->sum('credit')
            - (float) AccountEntry::where('account_id', $accountId)
            ->where(function ($w) {
                $w->whereNull('is_opening')->orWhere('is_opening', '!=', 1);
            })
            ->sum('debit');
    }

    protected function assertT0EqT2(array $t0, array $t2): void
    {
        // compare only accounts that appear in either
        $accounts = array_unique(array_merge(array_keys($t0), array_keys($t2)));
        foreach ($accounts as $aid) {
            $v0 = (float) ($t0[$aid] ?? 0);
            $v2 = (float) ($t2[$aid] ?? 0);
            $this->assertEqualsWithDelta($v0, $v2, 0.02,
                "Account #$aid must return to T0 (T0=$v0 T2=$v2)");
        }
    }

    protected function assertLedgerGloballyBalanced(): void
    {
        $credit = (float) AccountEntry::where(function ($w) {
            $w->whereNull('is_opening')->orWhere('is_opening', '!=', 1);
        })->sum('credit');
        $debit = (float) AccountEntry::where(function ($w) {
            $w->whereNull('is_opening')->orWhere('is_opening', '!=', 1);
        })->sum('debit');
        $this->assertEqualsWithDelta($credit, $debit, 0.02,
            "ledger must be globally balanced: credit=$credit debit=$debit");
    }

    protected function makeWalletAccount(): Account
    {
        return LedgerBalanceMutationGuard::run(function () {
            return Account::query()->create([
                'name' => 'W-EGP', 'type' => AccountType::Wallet->value, 'currency' => 'EGP',
                'balance' => 500_000.0, 'is_active' => true, 'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism', 'module' => 'hajj_umra', 'is_module_vault' => true,
                'wallet_provider' => 'vodafone_cash', 'wallet_number' => '01000000000',
                'created_by' => $this->admin->id,
            ]);
        });
    }
}
