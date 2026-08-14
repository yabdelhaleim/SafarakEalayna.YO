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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression test for the bug discovered by the 2026-08-14 audit:
 *
 *   repostSettlementTransaction() used `->first()` to find the
 *   settlement to reverse, which always returned the OLDEST settlement
 *   (smallest primary key). On cumulative updates, this meant the
 *   first settlement (F.1) got reversed on EVERY update, while
 *   subsequent settlements (F.2, F.3, …) stayed active. The customer's
 *   balance then drifted MORE negative than expected on every update.
 *
 * Example from the audit: paying 1k → 1.5k → 3k → 3.1k → 5.1k → 10k
 * on a 10k debt left the customer at −12.7k (should be 0).
 *
 * Fix applied in WalletTransactionService::repostSettlementTransaction:
 *   - Order by `id` DESC (latest settlement, not first).
 *   - Exclude mirror/reversal rows (notes LIKE 'عكس%') so the "reverse
 *     of reverse" chain does not pollute the match.
 */
class WalletCumulativeDebtRepaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Account $wallet;
    private Account $cashbox;
    private WalletType $walletType;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->wallet = Account::factory()->create([
            'type' => AccountType::Wallet->value,
            'name' => 'WL_TEST_Vodafone',
            'balance' => 100000,
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

    /**
     * Audit Section F scenario verbatim: 6 cumulative payments totalling
     * the full debt amount.
     */
    public function test_six_cumulative_payments_reach_zero_debt(): void
    {
        /** @var WalletTransactionService $service */
        $service = app(WalletTransactionService::class);

        // Create the 10,000 debt (full unpaid Send). This call also creates
        // the customer ledger account via ensureCustomerAccount().
        $tx = $service->createTransaction([
            'wallet_type_id' => $this->walletType->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->full_name,
            'wallet_number' => '01900088001',
            'type' => WalletTransactionType::Send->value,
            'amount' => 10000.0,
            'service_fee' => 0.0,
            'amount_paid' => 0.0,
            'wallet_account_id' => $this->wallet->id,
            'cash_account_id' => $this->cashbox->id,
            'employee_id' => null,
        ]);

        $custAccountId = (int) Customer::find($this->customer->id)->account_id;
        $this->assertNotNull($custAccountId, 'customer ledger account must exist after createTransaction');

        $this->assertEquals(10000.0, (float) Account::find($custAccountId)->fresh()->balance, 'Initial debt should be 10,000');

        // 6 cumulative payments: 1000, 1500, 3000, 3100, 5100, 10000
        $payments = [1000.0, 1500.0, 3000.0, 3100.0, 5100.0, 10000.0];
        $cumulative = 0.0;

        foreach ($payments as $idx => $paid) {
            $cumulative = $paid;
            $service->updateTransaction($tx, ['amount_paid' => $cumulative]);

            $expectedDebt = max(0.0, 10000.0 - $cumulative);
            $expectedCash = 100000.0 + $cumulative;

            $custBalance = (float) Account::find($custAccountId)->fresh()->balance;
            $cashBalance = (float) $this->cashbox->fresh()->balance;

            $this->assertEqualsWithDelta(
                $expectedDebt,
                $custBalance,
                0.05,
                "After payment #{$idx} (cumulative {$cumulative}): customer debt should be {$expectedDebt}, got {$custBalance}"
            );
            $this->assertEqualsWithDelta(
                $expectedCash,
                $cashBalance,
                0.05,
                "After payment #{$idx}: cashbox should be {$expectedCash}, got {$cashBalance}"
            );
        }

        $this->assertEqualsWithDelta(0.0, (float) Account::find($custAccountId)->fresh()->balance, 0.05);
        $this->assertEqualsWithDelta(110000.0, (float) $this->cashbox->fresh()->balance, 0.05);
    }

    /**
     * Audit Section E scenario: 3 payments totalling 10,000.
     */
    public function test_three_partial_payments_reach_zero_debt(): void
    {
        /** @var WalletTransactionService $service */
        $service = app(WalletTransactionService::class);

        $tx = $service->createTransaction([
            'wallet_type_id' => $this->walletType->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->full_name,
            'wallet_number' => '01900088002',
            'type' => WalletTransactionType::Send->value,
            'amount' => 10000.0,
            'service_fee' => 0.0,
            'amount_paid' => 0.0,
            'wallet_account_id' => $this->wallet->id,
            'cash_account_id' => $this->cashbox->id,
            'employee_id' => null,
        ]);

        $custAccountId = (int) Customer::find($this->customer->id)->account_id;

        $service->updateTransaction($tx, ['amount_paid' => 3000.0]);
        $this->assertEqualsWithDelta(7000.0, (float) Account::find($custAccountId)->fresh()->balance, 0.05);

        $service->updateTransaction($tx, ['amount_paid' => 5000.0]);
        $this->assertEqualsWithDelta(5000.0, (float) Account::find($custAccountId)->fresh()->balance, 0.05);

        $service->updateTransaction($tx, ['amount_paid' => 10000.0]);
        $this->assertEqualsWithDelta(0.0, (float) Account::find($custAccountId)->fresh()->balance, 0.05);
    }

    /**
     * Going DOWN (reducing amount_paid) — must also work correctly.
     */
    public function test_decreasing_amount_paid_reverses_correctly(): void
    {
        /** @var WalletTransactionService $service */
        $service = app(WalletTransactionService::class);

        $tx = $service->createTransaction([
            'wallet_type_id' => $this->walletType->id,
            'customer_id' => $this->customer->id,
            'customer_name' => $this->customer->full_name,
            'wallet_number' => '01900088003',
            'type' => WalletTransactionType::Send->value,
            'amount' => 10000.0,
            'service_fee' => 0.0,
            'amount_paid' => 0.0,
            'wallet_account_id' => $this->wallet->id,
            'cash_account_id' => $this->cashbox->id,
            'employee_id' => null,
        ]);

        $custAccountId = (int) Customer::find($this->customer->id)->account_id;

        $service->updateTransaction($tx, ['amount_paid' => 5000.0]);
        $this->assertEqualsWithDelta(5000.0, (float) Account::find($custAccountId)->fresh()->balance, 0.05);

        // Reduce payment to 2000 — debt should go back to 8000
        $service->updateTransaction($tx, ['amount_paid' => 2000.0]);
        $this->assertEqualsWithDelta(8000.0, (float) Account::find($custAccountId)->fresh()->balance, 0.05);

        // Pay full 10000
        $service->updateTransaction($tx, ['amount_paid' => 10000.0]);
        $this->assertEqualsWithDelta(0.0, (float) Account::find($custAccountId)->fresh()->balance, 0.05);
    }
}
