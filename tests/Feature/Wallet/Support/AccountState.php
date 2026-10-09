<?php

namespace Tests\Feature\Wallet\Support;

use Illuminate\Support\Facades\DB;

/**
 * Independent DB reader. NEVER calls WalletTransactionService,
 * TransactionService, or any service under audit to compute expected values.
 * This is the rule from the master prompt:
 *
 *   "The expected financial result must NOT be calculated by calling the
 *    same application service being tested. Build an independent oracle."
 *
 * All queries here are bare DB::table(...)->where(...)->sum(...) — they mirror
 * exactly what an external accountant would compute by reading the ledger.
 */
final class AccountState
{
    /** Read the `accounts.balance` column directly. */
    public static function balance(int $accountId): string
    {
        $row = DB::table('accounts')->where('id', $accountId)->first(['balance']);

        return $row === null ? '0.00' : Decimal::round((string) $row->balance);
    }

    /** Total CREDIT rows on this account across `account_entries`. */
    public static function totalCredit(int $accountId): string
    {
        $sum = DB::table('account_entries')
            ->where('account_id', $accountId)
            ->sum('credit');

        return Decimal::round((string) $sum);
    }

    /** Total DEBIT rows on this account across `account_entries`. */
    public static function totalDebit(int $accountId): string
    {
        $sum = DB::table('account_entries')
            ->where('account_id', $accountId)
            ->sum('debit');

        return Decimal::round((string) $sum);
    }

    /**
     * Project convention (per Account.php docblock line 27-98):
     *   balance = SUM(credit) - SUM(debit)
     */
    public static function entriesDerivedBalance(int $accountId): string
    {
        return Decimal::sub(self::totalCredit($accountId), self::totalDebit($accountId));
    }

    /** Count of AccountEntry rows for this account. */
    public static function entryCount(int $accountId): int
    {
        return (int) DB::table('account_entries')
            ->where('account_id', $accountId)
            ->count();
    }

    /** Count of AccountEntry rows for a transaction. */
    public static function entryCountForTransaction(int $transactionId): int
    {
        return (int) DB::table('account_entries')
            ->where('transaction_id', $transactionId)
            ->count();
    }

    /** All AccountEntry rows for a transaction, ordered by id. */
    public static function entriesForTransaction(int $transactionId): array
    {
        return DB::table('account_entries')
            ->where('transaction_id', $transactionId)
            ->orderBy('id')
            ->get(['id', 'account_id', 'transaction_id', 'debit', 'credit', 'balance_after', 'notes'])
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /** All AccountEntry rows for an account, ordered by created_at then id. */
    public static function entriesForAccount(int $accountId): array
    {
        return DB::table('account_entries')
            ->where('account_id', $accountId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'account_id', 'transaction_id', 'debit', 'credit', 'balance_after', 'notes'])
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /** Total system money across liquidity accounts. */
    public static function totalSystemLiquidity(): string
    {
        $sum = DB::table('accounts')
            ->whereIn('type', ['cashbox', 'wallet', 'bank'])
            ->sum('balance');

        return Decimal::round((string) $sum);
    }

    /** Balance of all customer accounts. */
    public static function totalCustomerBalances(?string $moduleType = null): string
    {
        $q = DB::table('accounts')->where('type', 'customer');
        if ($moduleType !== null) {
            $q->where('module_type', $moduleType);
        }
        $sum = $q->sum('balance');

        return Decimal::round((string) $sum);
    }

    /** Count of audit_logs rows for a given action+model. */
    public static function auditLogCount(?string $action = null, ?string $modelType = null): int
    {
        $q = DB::table('audit_logs');
        if ($action !== null) {
            $q->where('action', $action);
        }
        if ($modelType !== null) {
            $q->where('model_type', $modelType);
        }

        return (int) $q->count();
    }

    /** All transactions tied to a related_id (e.g. one wallet_transactions row). */
    public static function transactionsForRelated(string $relatedType, int $relatedId): array
    {
        return DB::table('transactions')
            ->where('related_type', $relatedType)
            ->where('related_id', $relatedId)
            ->orderBy('id')
            ->get(['id', 'type', 'module', 'amount', 'currency', 'from_account_id', 'to_account_id', 'created_by', 'notes'])
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /** Snapshots the entire system state for reconciliation. */
    public static function snapshot(): array
    {
        return [
            'accounts' => DB::table('accounts')
                ->orderBy('id')
                ->get(['id', 'name', 'type', 'currency', 'balance', 'is_active', 'module_type', 'module'])
                ->map(fn ($r) => (array) $r)
                ->all(),
            'transactions' => DB::table('transactions')
                ->orderBy('id')
                ->get(['id', 'type', 'module', 'amount', 'currency', 'from_account_id', 'to_account_id', 'created_by', 'related_type', 'related_id'])
                ->map(fn ($r) => (array) $r)
                ->all(),
            'account_entries' => DB::table('account_entries')
                ->orderBy('id')
                ->get(['id', 'account_id', 'transaction_id', 'debit', 'credit', 'balance_after', 'notes'])
                ->map(fn ($r) => (array) $r)
                ->all(),
        ];
    }
}
