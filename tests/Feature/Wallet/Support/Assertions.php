<?php

namespace Tests\Feature\Wallet\Support;

use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Audit assertion helpers. Every assertion uses exact decimal comparison via
 * Decimal. Any difference ≥ 0.01 is a real financial finding (unless explained).
 */
final class Assertions
{
    public static function assertBalanceEquals(int $accountId, string|int|float $expected, string $context = ''): void
    {
        $actual = AccountState::balance($accountId);
        $exp = Decimal::round((string) $expected);
        $diff = Decimal::diff($actual, $exp);
        PHPUnit::assertTrue(
            Decimal::equals($actual, $exp),
            sprintf(
                'Account #%d balance mismatch%s: expected=%s actual=%s diff=%s',
                $accountId, $context !== '' ? " ($context)" : '', $exp, $actual, $diff
            )
        );
    }

    /**
     * Hard invariant from Account.php docblock:
     *   accounts.balance = SUM(credit) - SUM(debit) on account_entries
     */
    public static function assertBalanceMatchesLedger(int $accountId, string $context = ''): void
    {
        $storedBalance = AccountState::balance($accountId);
        $derivedBalance = AccountState::entriesDerivedBalance($accountId);
        $diff = Decimal::diff($storedBalance, $derivedBalance);
        PHPUnit::assertTrue(
            Decimal::equals($storedBalance, $derivedBalance),
            sprintf(
                'INVARIANT VIOLATION%s: Account #%d stored balance (%s) ≠ derived from ledger (%s); diff=%s '.
                '— Per Account.php docblock: balance = SUM(credit) - SUM(debit).',
                $context !== '' ? " ($context)" : '', $accountId, $storedBalance, $derivedBalance, $diff
            )
        );
    }

    /**
     * Per-transaction double-entry invariant:
     *   SUM(debit on this transaction) = SUM(credit on this transaction)
     * Per AccountBalanceInvariantTest::test_each_transaction_has_balanced_entries.
     */
    public static function assertTransactionBalanced(int $transactionId, string $context = ''): void
    {
        $debitSum = (string) \DB::table('account_entries')
            ->where('transaction_id', $transactionId)
            ->sum('debit');
        $creditSum = (string) \DB::table('account_entries')
            ->where('transaction_id', $transactionId)
            ->sum('credit');
        PHPUnit::assertTrue(
            Decimal::equals($debitSum, $creditSum),
            sprintf(
                'LEDGER CORRUPTION%s: Transaction #%d not balanced. SUM(debit)=%s, SUM(credit)=%s, diff=%s',
                $context !== '' ? " ($context)" : '', $transactionId, $debitSum, $creditSum,
                Decimal::diff($debitSum, $creditSum)
            )
        );
    }

    public static function assertTotalSystemMoneyStable(string $before, string $after, string|int|float $deltaAllowance = '0.00', string $context = ''): void
    {
        $diff = Decimal::diff($after, $before);
        PHPUnit::assertTrue(
            Decimal::cmp($diff, (string) $deltaAllowance) <= 0 && Decimal::cmp($diff, '-'.(string) $deltaAllowance) >= 0,
            sprintf(
                'MONEY CONSERVATION FAILURE%s: system total changed unexpectedly: before=%s, after=%s, diff=%s, allowance=%s',
                $context !== '' ? " ($context)" : '', $before, $after, $diff, $deltaAllowance
            )
        );
    }
}
