<?php

namespace AuditHelpers;

use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Independent reconciliation helpers — verify the project-wide invariant
 *   SUM(account_entries.credit) − SUM(account_entries.debit) === account.balance
 * plus per-scenario financial delta checks against independently-computed
 * expected balances.
 *
 * Tolerance: 0.00 EGP. The epsilon (0.0001) absorbs ONLY float-rounding noise.
 * Anything beyond is a finding.
 */
class AuditReconciliation
{
    public const TOLERANCE_EGP = 0.0001;
    public const EPSILON_DISPLAY = 0.005;

    /** Verify project invariant: balance == SUM(credit) - SUM(debit) for one account */
    public function assertAccountInvariant(int $accountId, string $scenario, PhaseResult $result): bool
    {
        $account = Account::find($accountId);
        if (!$account) {
            $result->recordFail(
                scenario: "Account invariant: {$scenario}",
                expected: "Account #{$accountId} exists",
                actual: 'Account not found',
                severity: 'medium',
                context: ['module' => 'cross', 'account_ids' => [$accountId]],
            );
            return false;
        }

        $entries = DB::table('account_entries')->where('account_id', $accountId)->get();
        $creditSum = (float) $entries->where('type', 'credit')->sum('amount');
        $debitSum = (float) $entries->where('type', 'debit')->sum('amount');
        $computed = $creditSum - $debitSum;
        $actual = (float) $account->balance;
        $diff = abs($computed - $actual);

        if ($diff > self::TOLERANCE_EGP) {
            $result->recordFail(
                scenario: "Account invariant: {$scenario}",
                expected: sprintf('balance=%s, computed=%s, diff<%s',
                    number_format($actual, 2), number_format($computed, 2), self::EPSILON_DISPLAY),
                actual: sprintf('drift=%s EGP', number_format($diff, 4)),
                severity: 'critical',
                context: [
                    'module' => 'cross',
                    'account_ids' => [$accountId],
                    'diff_egp' => $diff,
                    'root_cause' => "Account #{$accountId} balance does not match entries SUM",
                ],
            );
            return false;
        }

        $result->recordPass();
        return true;
    }

    /** Verify delta balance matches the independently computed expected delta */
    public function assertBalanceDelta(int $accountId, float $initialBalance, float $expectedDelta, string $scenario, PhaseResult $result): bool
    {
        $current = (float) Account::find($accountId)->balance;
        $actualDelta = $current - $initialBalance;
        $diff = abs($actualDelta - $expectedDelta);

        if ($diff > self::TOLERANCE_EGP) {
            $result->recordFail(
                scenario: "Balance delta: {$scenario}",
                expected: sprintf('delta=%s', number_format($expectedDelta, 2)),
                actual: sprintf('actual_delta=%s, drift=%s', number_format($actualDelta, 2), number_format($diff, 4)),
                severity: 'critical',
                context: [
                    'module' => 'cross',
                    'account_ids' => [$accountId],
                    'diff_egp' => $diff,
                    'root_cause' => "Account #{$accountId} expected delta of {$expectedDelta} but got {$actualDelta}",
                ],
            );
            return false;
        }

        $result->recordPass();
        return true;
    }

    /** Assert two float values match within tolerance */
    public function assertZeroEGPDiff(float $expected, float $actual, string $scenario, PhaseResult $result, string $module = 'cross'): bool
    {
        $diff = abs($expected - $actual);
        if ($diff > self::TOLERANCE_EGP) {
            $result->recordFail(
                scenario: "Zero EGP diff: {$scenario}",
                expected: sprintf('value=%s', number_format($expected, 4)),
                actual: sprintf('value=%s, diff=%s', number_format($actual, 4), number_format($diff, 4)),
                severity: 'critical',
                context: ['module' => $module, 'diff_egp' => $diff],
            );
            return false;
        }
        $result->recordPass();
        return true;
    }

    /**
     * Detect duplicate transactions: for each (related_id, related_type, amount, type)
     * tuple, there must be exactly 1 row. Returns the duplicates found.
     */
    public function findDuplicateTransactions(int $relatedId, string $relatedType): array
    {
        $rows = DB::table('transactions')
            ->select('type', 'amount', 'currency', DB::raw('COUNT(*) as cnt'))
            ->where('related_type', $relatedType)
            ->where('related_id', $relatedId)
            ->groupBy('type', 'amount', 'currency')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->toArray();
        return $rows;
    }

    /**
     * Returns the SUM of all credit entries on an account — independent
     * recomputation of `account.balance` per the project invariant.
     */
    public function recomputeAccountBalance(int $accountId): float
    {
        $entries = DB::table('account_entries')->where('account_id', $accountId)->get();
        return (float) $entries->where('type', 'credit')->sum('amount')
             - (float) $entries->where('type', 'debit')->sum('amount');
    }

    /** Count transactions of a given type for a booking */
    public function countTransactions(int $relatedId, string $relatedType, ?string $type = null): int
    {
        $q = DB::table('transactions')
            ->where('related_type', $relatedType)
            ->where('related_id', $relatedId);
        if ($type) $q->where('type', $type);
        return $q->count();
    }

    /** Returns the SUM of customer payments (independent of booking.paid_amount) */
    public function totalPaymentsRecorded(int $relatedId, string $paymentTable, string $bookingFkColumn): float
    {
        return (float) DB::table($paymentTable)
            ->where($bookingFkColumn, $relatedId)
            ->sum('amount');
    }

    /** Returns the SUM of refund rows against a booking */
    public function totalRefunded(int $relatedId, string $relatedType): float
    {
        // Refund is reflected as negative entries on customer account OR new
        // Transaction rows with type=Refund or notes starting with 'عكس:'
        // For now, count all transactions where notes start with 'عكس' (additive reversal)
        return (float) DB::table('transactions')
            ->where('related_type', $relatedType)
            ->where('related_id', $relatedId)
            ->where(function ($q) {
                $q->where('type', 'refund')
                  ->orWhere('notes', 'like', 'عكس%')
                  ->orWhere('notes', 'like', 'refund%');
            })
            ->sum(DB::raw('CASE WHEN type = "refund" OR notes LIKE "عكس%" OR notes LIKE "refund%" THEN amount ELSE 0 END'));
    }
}
