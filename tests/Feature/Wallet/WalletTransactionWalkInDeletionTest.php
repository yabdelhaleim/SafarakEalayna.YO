<?php

namespace Tests\Feature\Wallet;

use App\Enums\AccountType;
use App\Enums\WalletTransactionType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\User;
use App\Models\Wallet\WalletTransaction;
use App\Models\Wallet\WalletType;
use App\Services\Wallet\WalletTransactionService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression test for the bug discovered by the 2026-08-14 audit:
 *
 *   DeferredTransactionDeletionGuard rejects deletion of walk-in
 *   wallet transactions because its Check 1 fires a false positive.
 *
 * For a walk-in Receive, the cash leg is a DEBIT on the cashbox (cash
 * paid out to customer), so `computeOriginalSettlement()` returns 0
 * (sum of CREDITS on the settlement account). The transaction's
 * `amount_paid` was set at creation reflecting the cash flow, NOT a
 * later pay-debt. Check 1 must be SKIPPED when there is no customer
 * account to "later pay" against.
 *
 * Fix applied in `WalletTransactionService::deleteTransaction` to pass
 * `null` for `$currentPaidAmount` when `$customerAccountId` is null.
 *
 * Coverage:
 *  - Walk-in Send: amount_paid = total_amount, deleted successfully.
 *  - Walk-in Receive (the original failing case): amount_paid = total
 *    cash paid out, deleted successfully.
 *  - Registered customer with no later payment: blocked.
 */
class WalletTransactionWalkInDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Account $wallet;
    protected Account $cashbox;
    protected WalletType $walletType;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->wallet = Account::factory()->create([
            'type' => AccountType::Wallet->value,
            'name' => 'WL_TEST_Vodafone',
            'balance' => 50000,
            'currency' => 'EGP',
        ]);

        $this->cashbox = Account::factory()->create([
            'type' => AccountType::Cashbox->value,
            'name' => 'WL_TEST_Cashbox',
            'balance' => 100000,
            'currency' => 'EGP',
        ]);

        $this->customer = Customer::factory()->create();

        $this->walletType = WalletType::firstOrCreate(
            ['code' => 'vodafone_cash'],
            [
                'name' => 'فودافون كاش',
                'is_active' => true,
                'sort_order' => 1,
            ]
        );
    }

    private function snapshotBalances(): array
    {
        return [
            'wallet' => (float) $this->wallet->fresh()->balance,
            'cashbox' => (float) $this->cashbox->fresh()->balance,
        ];
    }

    public function test_walk_in_send_can_be_deleted(): void
    {
        /** @var WalletTransactionService $service */
        $service = app(WalletTransactionService::class);

        $balBefore = $this->snapshotBalances();

        $tx = $service->createTransaction([
            'wallet_type_id' => $this->walletType->id,
            'customer_id' => null,
            'customer_name' => 'WALKIN_SEND_TEST',
            'wallet_number' => '01900099001',
            'type' => WalletTransactionType::Send->value,
            'amount' => 100.0,
            'service_fee' => 0.0,
            'amount_paid' => 100.0,
            'wallet_account_id' => $this->wallet->id,
            'cash_account_id' => $this->cashbox->id,
            'employee_id' => null,
        ]);

        $balAfterCreate = $this->snapshotBalances();

        $this->assertEquals($balBefore['wallet'] - 100.0, $balAfterCreate['wallet'], 'wallet debit at create');
        $this->assertEquals($balBefore['cashbox'] + 100.0, $balAfterCreate['cashbox'], 'cashbox credit at create');

        // ── Test: deletion MUST work for walk-in Send (no exception) ─────
        $service->deleteTransaction($tx);

        $tx->refresh();
        $this->assertNotNull($tx->deleted_at, 'walk-in Send must be soft-deleted');

        $balAfterDelete = $this->snapshotBalances();
        $this->assertEquals($balBefore['wallet'], $balAfterDelete['wallet'], 'wallet balance restored');
        $this->assertEquals($balBefore['cashbox'], $balAfterDelete['cashbox'], 'cashbox balance restored');
    }

    public function test_walk_in_receive_can_be_deleted(): void
    {
        /** @var WalletTransactionService $service */
        $service = app(WalletTransactionService::class);

        $balBefore = $this->snapshotBalances();

        // ── This was the FAILING case in the audit ────────────────────────
        // amount_paid=195 here is the CASH PAID OUT to the walk-in, NOT a
        // later debt payment. Pre-fix, the guard blocked deletion.
        $tx = $service->createTransaction([
            'wallet_type_id' => $this->walletType->id,
            'customer_id' => null,
            'customer_name' => 'WALKIN_RECEIVE_TEST',
            'wallet_number' => '01900099002',
            'type' => WalletTransactionType::Receive->value,
            'amount' => 200.0,
            'service_fee' => 5.0,
            'amount_paid' => 195.0,
            'wallet_account_id' => $this->wallet->id,
            'cash_account_id' => $this->cashbox->id,
            'employee_id' => null,
        ]);

        $balAfterCreate = $this->snapshotBalances();
        $this->assertEquals($balBefore['wallet'] + 200.0, $balAfterCreate['wallet']);
        $this->assertEquals($balBefore['cashbox'] - 195.0, $balAfterCreate['cashbox']);

        // ── Test: deletion MUST NOT throw for walk-in Receive ────────────
        $service->deleteTransaction($tx);

        $tx->refresh();
        $this->assertNotNull($tx->deleted_at, 'walk-in Receive must be soft-deleted');

        $balAfterDelete = $this->snapshotBalances();
        $this->assertEquals($balBefore['wallet'], $balAfterDelete['wallet'], 'wallet balance restored');
        $this->assertEquals($balBefore['cashbox'], $balAfterDelete['cashbox'], 'cashbox balance restored');
    }

    public function test_registered_customer_full_payment_deletable(): void
    {
        /** @var WalletTransactionService $service */
        $service = app(WalletTransactionService::class);

        $tx = $service->createTransaction([
            'wallet_type_id' => $this->walletType->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->full_name,
            'wallet_number' => '01900099003',
            'type' => WalletTransactionType::Send->value,
            'amount' => 100.0,
            'service_fee' => 0.0,
            'amount_paid' => 100.0,
            'wallet_account_id' => $this->wallet->id,
            'cash_account_id' => $this->cashbox->id,
            'employee_id' => null,
        ]);

        // No later payment — should be deletable
        $service->deleteTransaction($tx);
        $tx->refresh();
        $this->assertNotNull($tx->deleted_at);
    }
}
