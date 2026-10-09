<?php

namespace Tests\Feature\Wallet\Phases;

use App\Models\Account;
use Tests\Feature\Wallet\Support\AccountState;
use Tests\Feature\Wallet\Support\Assertions;
use Tests\Feature\Wallet\Support\Decimal;
use Tests\Feature\Wallet\WalletTestCase;

/**
 * PHASE 6 smoke. Confirms:
 *   - The test infrastructure boots (auth + DB + clearing accounts).
 *   - The Decimal oracle compares values exactly.
 *   - The Accounts balance invariant from Account.php docblock holds for fixtures.
 */
class Phase00SmokeTest extends WalletTestCase
{
    public function test_fixtures_have_expected_balances(): void
    {
        Assertions::assertBalanceEquals($this->walletAccountEgp->id, '10000.00', 'wallet EGP');
        Assertions::assertBalanceEquals($this->cashboxEgp->id, '5000.00', 'cashbox EGP');
        Assertions::assertBalanceEquals($this->cashboxUsd->id, '1000.00', 'cashbox USD');
        Assertions::assertBalanceEquals($this->cashboxSar->id, '1000.00', 'cashbox SAR');
        Assertions::assertBalanceEquals($this->walletIncomeClearing->id, '0.00', 'income clearing');
        Assertions::assertBalanceEquals($this->walletExpenseClearing->id, '0.00', 'expense clearing');
    }

    public function test_clearing_accounts_exist_for_wallet_module(): void
    {
        // Pull by name + module_type exactly the way LedgerClearingAccounts resolves them.
        $income = Account::query()
            ->where('name', 'إقفال إيرادات المحافظات')
            ->where('type', 'revenue')
            ->firstOrFail();
        $expense = Account::query()
            ->where('name', 'إقفال تكاليف المحافظات')
            ->where('type', 'expense')
            ->firstOrFail();

        $this->assertEquals('إقفال إيرادات المحافظات', $income->name);
        $this->assertEquals('إقفال تكاليف المحافظات', $expense->name);
    }

    public function test_decimal_oracle_compares_exactly(): void
    {
        $this->assertTrue(Decimal::equals('100.00', '100.00'));
        $this->assertTrue(Decimal::equals('100.00', '99.9999'), 'rounds to 100.00 at scale 2');
        $this->assertFalse(Decimal::equals('100.00', '100.01'));
        $this->assertFalse(Decimal::equals('100.00', '99.99'));

        $sum = Decimal::add('0.1', '0.2');
        $this->assertEquals('0.30', $sum, 'bcmath must give 0.30 not 0.30000000000000004');
    }

    /**
     * FINDING FIN-1 (HIGH): The project invariant "Account.balance = SUM(credit) − SUM(debit)"
     * is UNSATISFIABLE for freshly created accounts whose `balance` field is
     * non-zero. The system does NOT auto-create an opening-balance AccountEntry.
     * Therefore the comparison `Account.balance == entriesDerivedBalance()`
     * only holds AFTER the first ledger entry is written.
     *
     * FIXED (FIN-1): `Account::created` boot hook auto-seeds a paired opening-
     * balance `AccountEntry` (CREDIT on new account + paired DEBIT on the
     * singleton "System Opening Balances" contra) whenever an Account is
     * created with `balance > 0`. The paired entry has `transaction_id = NULL`
     * (per migration `2026_05_11_004055`) and `is_opening = true`.
     *
     * Therefore, after this fix, the invariant `Account.balance = SUM(credit) − SUM(debit)`
     * HOLDS for freshly-created fixtures (stored == derived == opening balance).
     */
    public function test_balance_invariant_for_initial_fixtures(): void
    {
        foreach ([
            $this->walletAccountEgp,
            $this->cashboxEgp,
            $this->cashboxUsd,
            $this->cashboxSar,
            $this->walletIncomeClearing,
            $this->walletExpenseClearing,
        ] as $account) {
            $stored = AccountState::balance($account->id);
            $derived = AccountState::entriesDerivedBalance($account->id);

            // FIN-1 fixed: paired opening entry auto-seeded on Account::created.
            // stored == derived == opening balance for every fixture.
            $openingBalance = (string) $account->balance;
            $this->assertTrue(
                Decimal::equals($stored, $derived),
                sprintf(
                    'FIN-1 fixed violation: Account #%d (%s) stored=%s derived=%s — paired opening entry should make them match.',
                    $account->id,
                    $account->name,
                    $stored,
                    $derived
                )
            );
            $this->assertTrue(
                Decimal::equals($stored, $openingBalance),
                sprintf(
                    'Account #%d (%s) stored=%s expected=%s — opening balance preserved.',
                    $account->id,
                    $account->name,
                    $stored,
                    $openingBalance
                )
            );
        }
    }

    public function test_admin_can_be_authenticated_via_sanctum(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/wallet/types');
        $response->assertStatus(200);
    }
}
