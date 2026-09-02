<?php

namespace Tests\Feature\Wallet;

use App\Enums\AccountType;
use App\Enums\WalletTransactionType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet\WalletTransaction;
use App\Models\Wallet\WalletType;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Wallet & Transfers — Delete Accounting Deep Test (T0/T1/T2)
 *
 * Date: 2026-08-29
 * Purpose: Verify that `deleteTransaction` reverses the ledger additively,
 *          so every account (wallet, cashbox, customer AR) returns to its
 *          pre-transaction (T0) state. Mirrors the HajjUmra/Visa T0==T2
 *          pattern with fresh wallet-specific scenarios.
 *
 * Sister to: HajjUmraDeleteT012DeepTest + VisaDeleteAccountingDeepTest
 */
class WalletDeleteT012DeepTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Account $walletEgp;
    protected Account $cashboxEgp;
    protected WalletType $walletType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name' => 'Wallet Delete Admin',
            'email' => 'wdel-'.uniqid('', true).'@test.local',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        LedgerBalanceMutationGuard::run(function () {
            $this->walletEgp = Account::query()->create([
                'name' => 'Wallet EGP',
                'type' => AccountType::Wallet->value,
                'currency' => 'EGP',
                'balance' => 100_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'office',
                'is_module_vault' => false,
                'wallet_provider' => 'vodafone_cash',
                'wallet_number' => '01000000001',
                'created_by' => $this->admin->id,
            ]);
            $this->cashboxEgp = Account::query()->create([
                'name' => 'Cashbox EGP',
                'type' => AccountType::Cashbox->value,
                'currency' => 'EGP',
                'balance' => 50_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'office',
                'is_module_vault' => false,
                'created_by' => $this->admin->id,
            ]);
        });

        $this->walletType = WalletType::firstOrCreate(
            ['code' => 'vodafone_cash'],
            ['name' => 'فودافون كاش', 'is_active' => true, 'sort_order' => 1]
        );
    }

    /* =================================================================
     *  T0/T1/T2 — Send (registered customer)
     * ================================================================= */

    public function test_T012_send_registered_delete_T0_eq_T2(): void
    {
        $customer = $this->makeCustomer();
        $t0 = $this->snapshotAll();

        $tx = $this->createSend($customer, 500.0, 10.0);

        $t1 = $this->snapshotAll();
        $this->assertNotSame($t0, $t1, 'T0 != T1 (send must move money)');

        $this->deleteJson("/api/v1/wallet/transactions/{$tx->id}")->assertOk();

        $t2 = $this->snapshotAll();
        $this->assertT0EqT2($t0, $t2);
        $this->assertLedgerGloballyBalanced();
    }

    /* =================================================================
     *  T0/T1/T2 — Send (walk-in)
     * ================================================================= */

    public function test_T012_send_walk_in_delete_T0_eq_T2(): void
    {
        $t0 = $this->snapshotAll();
        $tx = $this->createSendWalkIn(300.0, 5.0);

        $this->deleteJson("/api/v1/wallet/transactions/{$tx->id}")->assertOk();

        $t2 = $this->snapshotAll();
        $this->assertT0EqT2($t0, $t2);
        $this->assertLedgerGloballyBalanced();
    }

    /* =================================================================
     *  T0/T1/T2 — Receive (registered customer)
     * ================================================================= */

    public function test_T012_receive_registered_delete_T0_eq_T2(): void
    {
        $customer = $this->makeCustomer();
        $t0 = $this->snapshotAll();

        $tx = $this->createReceive($customer, 400.0, 8.0, amountPaid: 0.0);

        $this->deleteJson("/api/v1/wallet/transactions/{$tx->id}")->assertOk();

        $t2 = $this->snapshotAll();
        $this->assertT0EqT2($t0, $t2);
        $this->assertLedgerGloballyBalanced();
    }

    /* =================================================================
     *  T0/T1/T2 — Receive (walk-in)
     * ================================================================= */

    public function test_T012_receive_walk_in_delete_T0_eq_T2(): void
    {
        $t0 = $this->snapshotAll();
        $tx = $this->createReceiveWalkIn(250.0, 5.0);

        $this->deleteJson("/api/v1/wallet/transactions/{$tx->id}")->assertOk();

        $t2 = $this->snapshotAll();
        $this->assertT0EqT2($t0, $t2);
        $this->assertLedgerGloballyBalanced();
    }

    /* =================================================================
     *  Original transactions preserved after delete (additive reversal)
     * ================================================================= */

    public function test_T012_original_transactions_preserved(): void
    {
        $customer = $this->makeCustomer();
        $tx = $this->createSend($customer, 500.0, 10.0);

        // count wallet transactions BEFORE delete
        $txBefore = Transaction::where('module', 'wallet')
            ->where('related_type', WalletTransaction::class)
            ->where('related_id', $tx->id)
            ->count();
        $this->assertGreaterThanOrEqual(2, $txBefore, 'send registered should create at least 2 tx (income + expense)');

        $this->deleteJson("/api/v1/wallet/transactions/{$tx->id}")->assertOk();

        $txAfter = Transaction::where('module', 'wallet')
            ->where('related_type', WalletTransaction::class)
            ->where('related_id', $tx->id)
            ->count();
        $this->assertSame($txBefore, $txAfter, 'original transactions must NOT be deleted');
    }

    /* =================================================================
     *  Per-account ledger balanced after delete
     * ================================================================= */

    public function test_T012_per_account_ledger_balanced_after_delete(): void
    {
        $customer = $this->makeCustomer();
        $tx = $this->createSend($customer, 500.0, 10.0);

        $this->deleteJson("/api/v1/wallet/transactions/{$tx->id}")->assertOk();

        // for every account touched by the WT, sum of credits - debits must be 0
        $accountIds = DB::table('account_entries')
            ->whereIn('transaction_id', function ($q) use ($tx) {
                $q->select('id')->from('transactions')
                    ->where('module', 'wallet')
                    ->where('related_type', WalletTransaction::class)
                    ->where('related_id', $tx->id);
            })
            ->distinct()
            ->pluck('account_id');

        foreach ($accountIds as $aid) {
            $credit = (float) AccountEntry::where('account_id', $aid)
                ->whereIn('transaction_id', function ($q) use ($tx) {
                    $q->select('id')->from('transactions')
                        ->where('module', 'wallet')
                        ->where('related_type', WalletTransaction::class)
                        ->where('related_id', $tx->id);
                })
                ->sum('credit');
            $debit = (float) AccountEntry::where('account_id', $aid)
                ->whereIn('transaction_id', function ($q) use ($tx) {
                    $q->select('id')->from('transactions')
                        ->where('module', 'wallet')
                        ->where('related_type', WalletTransaction::class)
                        ->where('related_id', $tx->id);
                })
                ->sum('debit');
            $this->assertEqualsWithDelta($credit, $debit, 0.02,
                "account #$aid must be ledger-balanced: credit=$credit debit=$debit");
        }
    }

    /* =================================================================
     *  Σ debit = Σ credit globally after delete
     * ================================================================= */

    public function test_T012_global_debit_eq_credit_after_delete(): void
    {
        $customer = $this->makeCustomer();
        $tx = $this->createSend($customer, 500.0, 10.0);

        $this->deleteJson("/api/v1/wallet/transactions/{$tx->id}")->assertOk();

        $this->assertLedgerGloballyBalanced();
    }

    /* =================================================================
     *  Soft-delete row exists, not hard-deleted
     * ================================================================= */

    public function test_T012_soft_delete_preserves_row(): void
    {
        $customer = $this->makeCustomer();
        $tx = $this->createSend($customer, 500.0, 10.0);

        $this->deleteJson("/api/v1/wallet/transactions/{$tx->id}")->assertOk();

        $this->assertSoftDeleted('wallet_transactions', ['id' => $tx->id]);
        $trashed = WalletTransaction::withTrashed()->find($tx->id);
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deleted_at);
    }

    /* =================================================================
     *  5 transactions: create + delete each one → final T0 == T2
     * ================================================================= */

    public function test_T012_5_transactions_create_then_delete_restores(): void
    {
        $customer = $this->makeCustomer();
        $t0 = $this->snapshotAll();

        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $tx = $this->createSend($customer, 100.0, 5.0);
            $ids[] = $tx->id;
        }

        foreach ($ids as $id) {
            $this->deleteJson("/api/v1/wallet/transactions/{$id}")->assertOk();
        }

        $t2 = $this->snapshotAll();
        $this->assertT0EqT2($t0, $t2);
        $this->assertLedgerGloballyBalanced();
    }

    /* =================================================================
     *  Reverse entries carry "عكس" prefix
     * ================================================================= */

    public function test_T012_reverse_entries_carry_عكس_prefix(): void
    {
        $customer = $this->makeCustomer();
        $tx = $this->createSend($customer, 500.0, 10.0);

        $this->deleteJson("/api/v1/wallet/transactions/{$tx->id}")->assertOk();

        $reverseCount = Transaction::where('module', 'wallet')
            ->where('notes', 'like', 'عكس%')
            ->count();
        $this->assertGreaterThan(0, $reverseCount);
    }

    /* =================================================================
     *  Helpers
     * ================================================================= */

    protected function makeCustomer(string $name = 'Wallet Cust'): Customer
    {
        return Customer::query()->create([
            'full_name' => $name,
            'phone' => '+9665'.str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT),
        ]);
    }

    protected function createSend(Customer $customer, float $amount, float $fee): WalletTransaction
    {
        $resp = $this->withHeaders(['Idempotency-Key' => 's-'.uniqid('', true)])
            ->postJson('/api/v1/wallet/transactions', [
                'wallet_type_id' => $this->walletType->id,
                'customer_id' => $customer->id,
                'customer_name' => $customer->full_name,
                'wallet_number' => '01012345678',
                'type' => WalletTransactionType::Send->value,
                'amount' => $amount,
                'service_fee' => $fee,
                'wallet_account_id' => $this->walletEgp->id,
                'cash_account_id' => $this->cashboxEgp->id,
                'notes' => 'send',
            ]);
        $resp->assertCreated();
        return WalletTransaction::findOrFail($resp->json('data.id'));
    }

    protected function createSendWalkIn(float $amount, float $fee): WalletTransaction
    {
        $resp = $this->withHeaders(['Idempotency-Key' => 'sw-'.uniqid('', true)])
            ->postJson('/api/v1/wallet/transactions', [
                'wallet_type_id' => $this->walletType->id,
                'customer_id' => null,
                'customer_name' => 'Walk-in '.uniqid(),
                'wallet_number' => '01011112222',
                'type' => WalletTransactionType::Send->value,
                'amount' => $amount,
                'service_fee' => $fee,
                'wallet_account_id' => $this->walletEgp->id,
                'cash_account_id' => $this->cashboxEgp->id,
                'notes' => 'send walk-in',
            ]);
        $resp->assertCreated();
        return WalletTransaction::findOrFail($resp->json('data.id'));
    }

    protected function createReceive(Customer $customer, float $amount, float $fee, float $amountPaid = 0.0): WalletTransaction
    {
        $resp = $this->withHeaders(['Idempotency-Key' => 'r-'.uniqid('', true)])
            ->postJson('/api/v1/wallet/transactions', [
                'wallet_type_id' => $this->walletType->id,
                'customer_id' => $customer->id,
                'customer_name' => $customer->full_name,
                'wallet_number' => '01098765432',
                'type' => WalletTransactionType::Receive->value,
                'amount' => $amount,
                'service_fee' => $fee,
                'amount_paid' => $amountPaid,
                'wallet_account_id' => $this->walletEgp->id,
                'cash_account_id' => $this->cashboxEgp->id,
                'notes' => 'receive',
            ]);
        $resp->assertCreated();
        return WalletTransaction::findOrFail($resp->json('data.id'));
    }

    protected function createReceiveWalkIn(float $amount, float $fee): WalletTransaction
    {
        $resp = $this->withHeaders(['Idempotency-Key' => 'rw-'.uniqid('', true)])
            ->postJson('/api/v1/wallet/transactions', [
                'wallet_type_id' => $this->walletType->id,
                'customer_id' => null,
                'customer_name' => 'Recv Walk-in '.uniqid(),
                'wallet_number' => '01099998888',
                'type' => WalletTransactionType::Receive->value,
                'amount' => $amount,
                'service_fee' => $fee,
                'wallet_account_id' => $this->walletEgp->id,
                'cash_account_id' => $this->cashboxEgp->id,
                'notes' => 'receive walk-in',
            ]);
        $resp->assertCreated();
        return WalletTransaction::findOrFail($resp->json('data.id'));
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
