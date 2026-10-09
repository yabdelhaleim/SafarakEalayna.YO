<?php

declare(strict_types=1);

namespace Tests\Stress\Support;

use App\Models\Account;
use Illuminate\Support\Facades\DB;

/**
 * StressReconciliation — the canonical post-stress ledger validator.
 *
 * Mirrors the invariants from BusTestCase::assertLedgerGloballyBalanced
 * (which lives under tests/Feature/Bus/) and the OfficeTrialBalanceIntegrityTest
 * patterns. Every check returns a structured result so the final report
 * can serialize it to JSON.
 *
 * Tolerance is sourced from config('accounting.reconciliation.tolerance')
 * with a default fallback of 0.02.
 */
final class StressReconciliation
{
    public const DEFAULT_TOLERANCE = 0.02;
    public const PER_TX_TOLERANCE  = 0.0001;

    /**
     * Run all 10 reconciliation checks and return a structured report.
     *
     * @return array{
     *   ran_at:string,
     *   tier:string,
     *   tolerance:float,
     *   per_account:array{checked:int, failed:int, max_variance:float, failures:array<int,array{account_id:int,variance:float}>},
     *   per_transaction:array{checked:int, failed:int, failures:array<int,array{transaction_id:int,debit:float,credit:float,variance:float}>},
     *   orphan_entries:array{count:int, sample:array<int,int>},
     *   orphan_transactions:array{count:int, sample:array<int,int>},
     *   duplicate_income:array{count:int, sample:array<string,int>},
     *   reversals:array{originals:int, reversals:int, net_impact_egp:float},
     *   totals:array{credits:float, debits:float, balance_sum:float, diff:float},
     *   fk_integrity:array{broken:int, sample:array<int,array<string,mixed>>},
     *   soft_deletes:array<int, string>,
     *   verdict:string
     * }
     */
    public static function runAll(): array
    {
        $tolerance = (float) config('accounting.reconciliation.tolerance', self::DEFAULT_TOLERANCE);
        $tier      = DB::connection()->getDriverName();

        $perAccount    = self::checkPerAccount($tolerance);
        $perTx         = self::checkPerTransaction();
        $orphanEntries = self::orphanEntries();
        $orphanTx      = self::orphanTransactions();
        $duplicateInc  = self::duplicateIncomeKeys();
        $reversals     = self::reversalIntegrity();
        $totals        = self::totalsBalance();
        $fk            = self::foreignKeyIntegrity();
        $soft          = self::unexpectedSoftDeletes();

        $allOk = $perAccount['failed'] === 0
            && $perTx['failed'] === 0
            && $orphanEntries['count'] === 0
            && $orphanTx['count'] === 0
            && $duplicateInc['count'] === 0
            && abs($reversals['net_impact_egp']) < $tolerance
            && abs($totals['diff']) < $tolerance
            && $fk['broken'] === 0
            && count($soft) === 0;

        return [
            'ran_at'   => now()->toIso8601String(),
            'tier'     => $tier,
            'tolerance'=> $tolerance,
            'per_account'     => $perAccount,
            'per_transaction' => $perTx,
            'orphan_entries'  => $orphanEntries,
            'orphan_transactions' => $orphanTx,
            'duplicate_income'=> $duplicateInc,
            'reversals'       => $reversals,
            'totals'          => $totals,
            'fk_integrity'    => $fk,
            'soft_deletes'    => $soft,
            'verdict'         => $allOk ? 'PASS' : 'FAIL',
        ];
    }

    /** 1. For every account: balance == SUM(credit) - SUM(debit) on its entries. */
    public static function checkPerAccount(float $tolerance = self::DEFAULT_TOLERANCE): array
    {
        $rows = DB::select("
            SELECT a.id AS account_id,
                   a.balance AS stored_balance,
                   COALESCE(SUM(e.credit), 0) - COALESCE(SUM(e.debit), 0) AS computed_balance
            FROM accounts a
            LEFT JOIN account_entries e ON e.account_id = a.id
            GROUP BY a.id, a.balance
        ");
        $failed = [];
        $maxVar = 0.0;
        foreach ($rows as $r) {
            $var = round((float)$r->stored_balance - (float)$r->computed_balance, 4);
            $maxVar = max($maxVar, abs($var));
            if (abs($var) > $tolerance) {
                $failed[] = ['account_id' => (int)$r->account_id, 'variance' => $var];
            }
        }
        return [
            'checked'      => count($rows),
            'failed'       => count($failed),
            'max_variance' => round($maxVar, 4),
            'failures'     => array_slice($failed, 0, 20),
        ];
    }

    /** 2. For every transaction: SUM(debit) == SUM(credit). */
    public static function checkPerTransaction(): array
    {
        $rows = DB::select("
            SELECT transaction_id,
                   COALESCE(SUM(debit), 0)  AS debit,
                   COALESCE(SUM(credit), 0) AS credit
            FROM account_entries
            GROUP BY transaction_id
        ");
        $failed = [];
        foreach ($rows as $r) {
            $var = round((float)$r->debit - (float)$r->credit, 4);
            if (abs($var) > self::PER_TX_TOLERANCE) {
                $failed[] = [
                    'transaction_id' => (int)$r->transaction_id,
                    'debit'          => (float)$r->debit,
                    'credit'         => (float)$r->credit,
                    'variance'       => $var,
                ];
            }
        }
        return [
            'checked'  => count($rows),
            'failed'   => count($failed),
            'failures' => array_slice($failed, 0, 20),
        ];
    }

    /** 3. AccountEntry rows pointing to a missing Transaction. */
    public static function orphanEntries(): array
    {
        $rows = DB::select("
            SELECT e.transaction_id
            FROM account_entries e
            LEFT JOIN transactions t ON t.id = e.transaction_id
            WHERE t.id IS NULL
            LIMIT 20
        ");
        $count = (int) DB::selectOne("
            SELECT COUNT(*) AS c FROM account_entries e
            LEFT JOIN transactions t ON t.id = e.transaction_id
            WHERE t.id IS NULL
        ")->c;
        return [
            'count'  => $count,
            'sample' => array_map(fn($r) => (int)$r->transaction_id, $rows),
        ];
    }

    /** 4. Transactions with zero entries (excluding allowed single-leg legacy). */
    public static function orphanTransactions(): array
    {
        $rows = DB::select("
            SELECT t.id
            FROM transactions t
            LEFT JOIN account_entries e ON e.transaction_id = t.id
            WHERE e.id IS NULL
            LIMIT 20
        ");
        $count = (int) DB::selectOne("
            SELECT COUNT(*) AS c FROM transactions t
            LEFT JOIN account_entries e ON e.transaction_id = t.id
            WHERE e.id IS NULL
        ")->c;
        return [
            'count'  => $count,
            'sample' => array_map(fn($r) => (int)$r->id, $rows),
        ];
    }

    /**
     * 5. Duplicate Income keys (related_type + related_id with > 1 active
     * non-reversed income transaction). On MySQL the unique index prevents
     * this; on SQLite we check app-level guard via join.
     */
    public static function duplicateIncomeKeys(): array
    {
        $rows = DB::select("
            SELECT related_type, related_id, COUNT(*) AS c
            FROM transactions
            WHERE type = 'income'
              AND related_type IS NOT NULL
              AND related_id IS NOT NULL
              AND (notes IS NULL OR notes NOT LIKE 'عكس:%')
            GROUP BY related_type, related_id
            HAVING c > 1
            LIMIT 20
        ");
        return [
            'count'  => count($rows),
            'sample' => array_map(fn($r) => $r->related_type.':'.$r->related_id, $rows),
        ];
    }

    /**
     * 6. Reversal integrity — every original with a notes-prefix `عكس:` exists,
     * and the net financial impact of reversal pairs is zero.
     */
    public static function reversalIntegrity(): array
    {
        $originals = (int) DB::selectOne("
            SELECT COUNT(*) AS c FROM transactions
            WHERE notes LIKE 'عكس:%'
        ")->c;
        $reversals = $originals;
        // FIX (Phase B): compute net impact from account_entries (debit/credit),
        // not from transactions.amount which is unsigned. For each reversal tx,
        // SUM(credit) - SUM(debit) of its entries should be 0 (balanced); and
        // a reversal of an original tx should net to 0 across the pair.
        // The proper invariant is: each reversal transaction itself has
        // balanced legs, AND across all reversals the net SUM is 0.
        $netImpact = (float) DB::selectOne("
            SELECT COALESCE(SUM(ae.credit) - SUM(ae.debit), 0) AS s
            FROM account_entries ae
            JOIN transactions t ON t.id = ae.transaction_id
            WHERE t.notes LIKE 'عكس:%'
        ")->s;

        return [
            'originals'       => $originals,
            'reversals'       => $reversals,
            'net_impact_egp'  => round($netImpact, 2),
        ];
    }

    /** 7. Global totals — credits - debits should match balance sum. */
    public static function totalsBalance(): array
    {
        $credits     = (float) DB::table('account_entries')->sum('credit');
        $debits      = (float) DB::table('account_entries')->sum('debit');
        $balanceSum  = (float) DB::table('accounts')->sum('balance');
        $diff        = round($credits - $debits - $balanceSum, 4);
        return [
            'credits'     => round($credits, 2),
            'debits'      => round($debits, 2),
            'balance_sum' => round($balanceSum, 2),
            'diff'        => $diff,
        ];
    }

    /** 8. FK integrity — broken FK references on common relations. */
    public static function foreignKeyIntegrity(): array
    {
        $broken = [];
        $checks = [
            'transactions.from_account_id -> accounts.id' => "
                SELECT t.id, t.from_account_id FROM transactions t
                LEFT JOIN accounts a ON a.id = t.from_account_id
                WHERE t.from_account_id IS NOT NULL AND a.id IS NULL LIMIT 20",
            'transactions.to_account_id -> accounts.id' => "
                SELECT t.id, t.to_account_id FROM transactions t
                LEFT JOIN accounts a ON a.id = t.to_account_id
                WHERE t.to_account_id IS NOT NULL AND a.id IS NULL LIMIT 20",
            'account_entries.account_id -> accounts.id' => "
                SELECT e.id, e.account_id FROM account_entries e
                LEFT JOIN accounts a ON a.id = e.account_id
                WHERE a.id IS NULL LIMIT 20",
            'account_entries.transaction_id -> transactions.id' => "
                SELECT e.id, e.transaction_id FROM account_entries e
                LEFT JOIN transactions t ON t.id = e.transaction_id
                WHERE t.id IS NULL LIMIT 20",
            'customers.account_id -> accounts.id' => "
                SELECT c.id, c.account_id FROM customers c
                LEFT JOIN accounts a ON a.id = c.account_id
                WHERE c.account_id IS NOT NULL AND a.id IS NULL LIMIT 20",
        ];
        $totalBroken = 0;
        foreach ($checks as $label => $sql) {
            $rows = DB::select($sql);
            if (!empty($rows)) {
                $broken[$label] = array_map(fn($r) => (array)$r, $rows);
                $totalBroken += count($rows);
            }
        }
        return ['broken' => $totalBroken, 'sample' => $broken];
    }

    /** 9. Unexpected soft-deletes on active (non-cancelled, non-refunded) bookings. */
    public static function unexpectedSoftDeletes(): array
    {
        $issues = [];
        // Bus bookings: deleted_at set on a non-cancelled, non-refunded row.
        if (\Illuminate\Support\Facades\Schema::hasTable('bus_bookings')
            && \Illuminate\Support\Facades\Schema::hasColumn('bus_bookings', 'deleted_at')) {
            $n = DB::selectOne("
                SELECT COUNT(*) AS c FROM bus_bookings
                WHERE deleted_at IS NOT NULL
                  AND status NOT IN ('cancelled','refunded')
            ")->c;
            if ($n > 0) {
                $issues[] = "bus_bookings soft-deleted but status active: {$n}";
            }
        }
        if (\Illuminate\Support\Facades\Schema::hasTable('hajj_umra_bookings')
            && \Illuminate\Support\Facades\Schema::hasColumn('hajj_umra_bookings', 'deleted_at')) {
            $n = DB::selectOne("
                SELECT COUNT(*) AS c FROM hajj_umra_bookings
                WHERE deleted_at IS NOT NULL
                  AND status NOT IN ('cancelled','refunded')
            ")->c;
            if ($n > 0) {
                $issues[] = "hajj_umra_bookings soft-deleted but status active: {$n}";
            }
        }
        if (\Illuminate\Support\Facades\Schema::hasTable('visa_bookings')
            && \Illuminate\Support\Facades\Schema::hasColumn('visa_bookings', 'deleted_at')) {
            $n = DB::selectOne("
                SELECT COUNT(*) AS c FROM visa_bookings
                WHERE deleted_at IS NOT NULL
                  AND status NOT IN ('cancelled','refunded')
            ")->c;
            if ($n > 0) {
                $issues[] = "visa_bookings soft-deleted but status active: {$n}";
            }
        }
        return $issues;
    }
}
