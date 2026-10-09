<?php

namespace Tests\Feature\HajjUmra;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\HajjUmraBooking;
use App\Models\Program;
use App\Models\Transaction;
use App\Models\User;
use App\Services\HajjUmra\HajjUmraBookingService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Hajj/Umrah — Delete Accounting Deep Test (T0/T1/T2 Snapshot Pattern)
 *
 * Date: 2026-08-29
 * Purpose: Prove that HajjUmraBookingService::deleteBookingWithReversal()
 *          restores EVERY account back to its pre-booking (T0) state.
 *          Complements HajjUmraDeleteDeepAuditTest (12 tests) and
 *          HajjUmraBalanceRestoreOnDelete20260829Test (16 tests) with
 *          focused T0==T2 invariants on fresh scenarios.
 */
class HajjUmraDeleteT012DeepTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Account $vaultEgp;
    protected Account $bankEgp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::query()->create([
            'name' => 'Delete Admin',
            'email' => 'delete-'.uniqid('', true).'@test.local',
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
     *  T0/T1/T2 — Vault-direct booking with full payment
     * ===================================================================== */

    public function test_T012_vault_direct_full_pay_delete_T0_eq_T2(): void
    {
        $program = $this->makeVaultDirectProgram();
        $t0 = $this->snapshotAll();

        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP', $this->vaultEgp->id);
        $this->addPay($booking->id, 50000.0, 'cash', $this->vaultEgp->id);

        $t1 = $this->snapshotAll();

        $this->deleteBooking($booking->id);

        $t2 = $this->snapshotAll();
        $this->assertT0EqT2($t0, $t2);
        $this->assertSoftDeleted('hajj_umra_bookings', ['id' => $booking->id]);
        $this->assertLedgerGloballyBalanced();
    }

    /* =====================================================================
     *  T0/T1/T2 — Partial payment booking
     * ===================================================================== */

    public function test_T012_partial_payment_delete_T0_eq_T2(): void
    {
        $program = $this->makeVaultDirectProgram();
        $t0 = $this->snapshotAll();

        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP', $this->vaultEgp->id);
        $this->addPay($booking->id, 20000.0, 'cash', $this->vaultEgp->id);

        $this->deleteBooking($booking->id);

        $t2 = $this->snapshotAll();
        $this->assertT0EqT2($t0, $t2);
        $this->assertLedgerGloballyBalanced();
    }

    /* =====================================================================
     *  T0/T1/T2 — Zero-payment (unpaid) booking
     * ===================================================================== */

    public function test_T012_unpaid_booking_delete_T0_eq_T2(): void
    {
        $program = $this->makeVaultDirectProgram();
        $t0 = $this->snapshotAll();

        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP', $this->vaultEgp->id);
        // no payments

        $this->deleteBooking($booking->id);

        $t2 = $this->snapshotAll();
        $this->assertT0EqT2($t0, $t2);
        $this->assertLedgerGloballyBalanced();
    }

    /* =====================================================================
     *  T0/T1/T2 — Booking with companion
     * ===================================================================== */

    public function test_T012_companion_booking_delete_T0_eq_T2(): void
    {
        $program = $this->makeVaultDirectProgram();
        $customer = Customer::query()->create(['full_name' => 'C1', 'phone' => '01010000001', 'is_active' => true]);
        $companion = Customer::query()->create(['full_name' => 'C2', 'phone' => '01010000002', 'is_active' => true]);

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

        $this->deleteBooking($bookingId);

        $t2 = $this->snapshotAll();
        $this->assertT0EqT2($t0, $t2);
        $this->assertLedgerGloballyBalanced();
    }

    /* =====================================================================
     *  T0/T1/T2 — EC-based booking
     * ===================================================================== */

    public function test_T012_ec_booking_delete_restores_ec_ap(): void
    {
        $program = $this->makeProgram();
        $ec = \App\Models\HajjUmra\HajjUmraExecutingCompany::find($program->executing_company_id);
        $ecAcct = Account::find($ec->fresh()->account_id);
        $t0Ec = $this->netFor($ecAcct->id);

        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP', $this->vaultEgp->id);
        $this->addPay($booking->id, 50000.0, 'cash', $this->vaultEgp->id);

        $t1Ec = $this->netFor($ecAcct->id);
        $this->assertLessThan($t0Ec, $t1Ec, 'EC AP must be more negative after booking');

        $this->deleteBooking($booking->id);

        $t2Ec = $this->netFor($ecAcct->id);
        $this->assertEqualsWithDelta($t0Ec, $t2Ec, 0.02, 'EC AP must return to T0 after delete');
        $this->assertLedgerGloballyBalanced();
    }

    /* =====================================================================
     *  T0/T1/T2 — Mixed payment methods
     * ===================================================================== */

    public function test_T012_mixed_payment_methods_delete_T0_eq_T2(): void
    {
        $program = $this->makeVaultDirectProgram();
        $t0 = $this->snapshotAll();

        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP', $this->vaultEgp->id);
        $this->addPay($booking->id, 20000.0, 'cash', $this->vaultEgp->id);
        $this->addPay($booking->id, 20000.0, 'bank_transfer', $this->bankEgp->id);
        $this->addPay($booking->id, 10000.0, 'cash_wallet', $this->vaultEgp->id);

        $this->deleteBooking($booking->id);

        $t2 = $this->snapshotAll();
        $this->assertT0EqT2($t0, $t2);
        $this->assertLedgerGloballyBalanced();
    }

    /* =====================================================================
     *  Double-delete is idempotent
     * ===================================================================== */

    public function test_T012_double_delete_idempotent(): void
    {
        $program = $this->makeVaultDirectProgram();
        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP', $this->vaultEgp->id);
        $this->addPay($booking->id, 25000.0, 'cash', $this->vaultEgp->id);

        $this->deleteBooking($booking->id);
        $tAfterFirst = $this->snapshotAll();

        // second delete is rejected with 422
        $resp = $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking->id}");
        $resp->assertStatus(422);

        $tAfterSecond = $this->snapshotAll();
        $this->assertSame($tAfterFirst, $tAfterSecond, 'second rejected delete must not change ledger');
    }

    /* =====================================================================
     *  Delete after cancel is allowed (cancellations stay visible, then admin delete removes them)
     * ===================================================================== */

    public function test_T012_delete_after_cancel_still_restores_T0(): void
    {
        $program = $this->makeVaultDirectProgram();
        $t0 = $this->snapshotAll();

        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP', $this->vaultEgp->id);
        $this->addPay($booking->id, 30000.0, 'cash', $this->vaultEgp->id);

        app(HajjUmraBookingService::class)->cancel($booking, 'cancel-then-delete');

        // cancel reverses all → back to zero. Then admin delete also "restores" (no-op since already zero).
        $this->deleteBooking($booking->id);

        $t2 = $this->snapshotAll();
        $this->assertT0EqT2($t0, $t2);
        $this->assertLedgerGloballyBalanced();
    }

    /* =====================================================================
     *  Original transactions preserved after delete (additive reversal)
     * ===================================================================== */

    public function test_T012_original_transactions_preserved_after_delete(): void
    {
        $program = $this->makeVaultDirectProgram();
        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP', $this->vaultEgp->id);
        $this->addPay($booking->id, 50000.0, 'cash', $this->vaultEgp->id);

        $originalCount = Transaction::query()->where('module', 'hajj_umra')
            ->where('related_type', HajjUmraBooking::class)
            ->where('related_id', $booking->id)->count();
        $this->assertSame(3, $originalCount, 'expect 3 original txs: expense + income + payment');

        $this->deleteBooking($booking->id);

        $afterCount = Transaction::query()->where('module', 'hajj_umra')
            ->where('related_type', HajjUmraBooking::class)
            ->where('related_id', $booking->id)->count();
        $this->assertSame(3, $afterCount, 'original transactions must NOT be deleted (additive)');
    }

    /* =====================================================================
     *  Per-account ledger-balanced after delete
     * ===================================================================== */

    public function test_T012_every_account_ledger_balanced_after_delete(): void
    {
        $program = $this->makeVaultDirectProgram();
        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP', $this->vaultEgp->id);
        $this->addPay($booking->id, 50000.0, 'cash', $this->vaultEgp->id);

        $this->deleteBooking($booking->id);

        // sum entries per account, must equal expected (but Account::balance field may diverge — we check ledger only)
        $accountIds = DB::table('account_entries')
            ->whereIn('transaction_id', function ($q) use ($booking) {
                $q->select('id')->from('transactions')
                    ->where('module', 'hajj_umra')
                    ->where('related_type', HajjUmraBooking::class)
                    ->where('related_id', $booking->id);
            })
            ->distinct()
            ->pluck('account_id');

        foreach ($accountIds as $aid) {
            $credit = (float) AccountEntry::where('account_id', $aid)
                ->whereIn('transaction_id', function ($q) use ($booking) {
                    $q->select('id')->from('transactions')
                        ->where('module', 'hajj_umra')
                        ->where('related_type', HajjUmraBooking::class)
                        ->where('related_id', $booking->id);
                })
                ->sum('credit');
            $debit = (float) AccountEntry::where('account_id', $aid)
                ->whereIn('transaction_id', function ($q) use ($booking) {
                    $q->select('id')->from('transactions')
                        ->where('module', 'hajj_umra')
                        ->where('related_type', HajjUmraBooking::class)
                        ->where('related_id', $booking->id);
                })
                ->sum('debit');
            $this->assertEqualsWithDelta($credit, $debit, 0.02,
                "account #$aid must be ledger-balanced: credit=$credit debit=$debit");
        }
    }

    /* =====================================================================
     *  Reverse entries carry "عكس" prefix
     * ===================================================================== */

    public function test_T012_reverse_entries_carry_عكس_prefix(): void
    {
        $program = $this->makeVaultDirectProgram();
        $booking = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP', $this->vaultEgp->id);
        $this->addPay($booking->id, 25000.0, 'cash', $this->vaultEgp->id);

        $this->deleteBooking($booking->id);

        $reverseCount = Transaction::query()
            ->where('module', 'hajj_umra')
            ->where('notes', 'like', 'عكس%')
            ->count();
        $this->assertGreaterThan(0, $reverseCount);
    }

    /* =====================================================================
     *  Global ledger balanced after bulk delete
     * ===================================================================== */

    public function test_T012_5_bookings_paid_then_deleted_ledger_balanced(): void
    {
        $program = $this->makeVaultDirectProgram();

        for ($i = 0; $i < 5; $i++) {
            $b = $this->makeBooking($program->id, 42000.0, 50000.0, 'EGP', $this->vaultEgp->id);
            $this->addPay($b->id, 25000.0, 'cash', $this->vaultEgp->id);
            $this->deleteBooking($b->id);
        }

        $this->assertLedgerGloballyBalanced();
        $this->assertSame(0, HajjUmraBooking::count(), 'all bookings must be soft-deleted');
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

    protected function deleteBooking(int $bookingId): void
    {
        $resp = $this->deleteJson("/api/v1/hajj-umra/bookings/{$bookingId}");
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
}
