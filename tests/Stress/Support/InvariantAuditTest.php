<?php

declare(strict_types=1);

namespace Tests\Stress\Support;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Tests\Stress\FinanceStressTestCase;

/**
 * InvariantAuditTest — explicit per-account / per-transaction invariant checks
 * for the Phase 25 stress fixture. NOT a stress test itself; a verification
 * audit to confirm the canonical opening-balance pattern is honored before
 * bulk generation starts.
 *
 * Runs three invariant checks:
 *   1. directBalancedTransaction — confirm exactly 2 entries per transaction
 *      and the SUM(debit) == SUM(credit) per transaction, with the balance
 *      update owned by AccountService.
 *   2. openBalance — confirm exactly 2 entries per call (1 credit on target,
 *      1 debit on capital) and the per-account SUM(credit-debit) == balance
 *      for both the target and the capital account after N openings.
 *   3. Global stress fixture — every account satisfies balance = SUM(credit - debit),
 *      no duplicate entries, no orphans.
 */
final class InvariantAuditTest extends FinanceStressTestCase
{
    public function test_A_direct_balanced_transaction_creates_exactly_two_entries(): void
    {
        $accts = StressBulkFactory::bulkLiquidityAccounts(2, 'office');
        StressBulkFactory::openBalance($accts[0], 5000.0, $this->actorId);
        StressBulkFactory::openBalance($accts[1], 5000.0, $this->actorId);

        $tx = StressBulkFactory::directBalancedTransaction([
            'from_account_id' => $accts[0]->id,
            'to_account_id'   => $accts[1]->id,
            'amount'          => 1500.0,
        ]);

        $entries = AccountEntry::where('transaction_id', $tx->id)->get();
        $this->assertCount(2, $entries, 'directBalancedTransaction must create exactly 2 AccountEntry rows');

        $debitSum = (float) $entries->sum('debit');
        $creditSum = (float) $entries->sum('credit');
        $this->assertSame(1500.0, $debitSum, 'Debit leg must equal 1500');
        $this->assertSame(1500.0, $creditSum, 'Credit leg must equal 1500');

        // Service-owned balance update.
        $this->assertSame(3500.0, (float) $accts[0]->fresh()->balance, 'Debit account balance');
        $this->assertSame(6500.0, (float) $accts[1]->fresh()->balance, 'Credit account balance');

        // No AccountEntry rows outside the 2 we expect.
        $this->assertSame(2, AccountEntry::where('transaction_id', $tx->id)->count());
    }

    public function test_B_open_balance_creates_two_balanced_entries_per_call(): void
    {
        $accts = StressBulkFactory::bulkLiquidityAccounts(3, 'office');
        $capitalBefore = StressBulkFactory::openingCapitalAccount($this->actorId);

        StressBulkFactory::openBalance($accts[0], 4000.0, $this->actorId, 'OPEN-A');
        StressBulkFactory::openBalance($accts[1], 6000.0, $this->actorId, 'OPEN-B');
        StressBulkFactory::openBalance($accts[2], 9000.0, $this->actorId, 'OPEN-C');

        // Per-call invariant: each opening produced exactly 2 entries on
        // the shared STRESS-OPENING-TX — one credit on target, one debit on capital.
        $openingTx = Transaction::where('notes', 'STRESS-OPENING-TX')->firstOrFail();
        $entries = AccountEntry::where('transaction_id', $openingTx->id)->orderBy('id')->get();
        $this->assertCount(6, $entries, '3 openings x 2 entries = 6');

        $debitSum = (float) $entries->sum('debit');
        $creditSum = (float) $entries->sum('credit');
        $this->assertSame(19000.0, $debitSum, 'Total debit on opening tx = sum of openings');
        $this->assertSame(19000.0, $creditSum, 'Total credit on opening tx = sum of openings');

        // Per-account invariant.
        foreach ($accts as $i => $a) {
            $fresh = $a->fresh();
            $entrySum = (float) AccountEntry::where('account_id', $a->id)->sum('credit')
                     - (float) AccountEntry::where('account_id', $a->id)->sum('debit');
            $this->assertSame(
                (float) $fresh->balance,
                $entrySum,
                "Account {$a->id} ({$a->name}): balance={$fresh->balance} vs entries={$entrySum}"
            );
        }

        // Capital account.
        $capital = $capitalBefore->fresh();
        $capEntrySum = (float) AccountEntry::where('account_id', $capital->id)->sum('credit')
                     - (float) AccountEntry::where('account_id', $capital->id)->sum('debit');
        $this->assertSame(
            (float) $capital->balance,
            $capEntrySum,
            "Capital account balance={$capital->balance} vs entries={$capEntrySum}"
        );
        $this->assertSame(-19000.0, (float) $capital->balance, 'Capital goes negative by total openings');
    }

    public function test_C_global_stress_fixture_invariant(): void
    {
        // Seed: 10 liquidity accounts, each opened with 10000, then 5 random
        // balanced transactions, exactly as the smoke test does.
        $accts = StressBulkFactory::bulkLiquidityAccounts(10, 'office');
        foreach ($accts as $a) {
            StressBulkFactory::openBalance($a, 10000.0, $this->actorId);
        }
        for ($i = 0; $i < 5; $i++) {
            StressBulkFactory::directBalancedTransaction();
        }

        // Per-account: balance == SUM(credit - debit) on its entries.
        $rows = DB::select("
            SELECT a.id, a.name, a.balance AS stored,
                   COALESCE(SUM(e.credit), 0) - COALESCE(SUM(e.debit), 0) AS computed
            FROM accounts a
            LEFT JOIN account_entries e ON e.account_id = a.id
            GROUP BY a.id
        ");
        $mismatches = [];
        foreach ($rows as $r) {
            if (abs((float)$r->stored - (float)$r->computed) > 0.01) {
                $mismatches[] = ['id' => (int)$r->id, 'name' => $r->name, 'stored' => (float)$r->stored, 'computed' => (float)$r->computed];
            }
        }

        // Duplicate entries check — two entries are duplicates only when
        // ALL of (transaction_id, account_id, debit, credit, balance_after,
        // notes, created_at) match exactly. Multiple legitimate openings of
        // the same account have the same (tx, account, debit, credit) but
        // different balance_after — those are NOT duplicates.
        $dupes = DB::select("
            SELECT transaction_id, account_id, debit, credit, balance_after,
                   notes, created_at, COUNT(*) AS c
            FROM account_entries
            GROUP BY transaction_id, account_id, debit, credit, balance_after,
                     notes, created_at
            HAVING c > 1
        ");

        // Orphan entries (account_id missing or transaction_id missing).
        $orphans = DB::select("
            SELECT e.id FROM account_entries e
            LEFT JOIN accounts a ON a.id = e.account_id
            LEFT JOIN transactions t ON t.id = e.transaction_id
            WHERE a.id IS NULL OR t.id IS NULL
        ");

        // Global totals.
        $totals = DB::selectOne("
            SELECT
                (SELECT COALESCE(SUM(credit), 0) FROM account_entries) AS credits,
                (SELECT COALESCE(SUM(debit), 0) FROM account_entries) AS debits,
                (SELECT COALESCE(SUM(balance), 0) FROM accounts) AS balance_sum
        ");

        $report = [
            'accounts_checked'    => count($rows),
            'mismatches'          => $mismatches,
            'duplicate_entries'   => $dupes,
            'orphan_entries'      => $orphans,
            'totals'              => [
                'credits'     => (float) $totals->credits,
                'debits'      => (float) $totals->debits,
                'balance_sum' => (float) $totals->balance_sum,
                'diff'        => round((float)$totals->credits - (float)$totals->debits - (float)$totals->balance_sum, 4),
            ],
        ];
        $this->writeArtifact('audit', 'global-invariant', $report);

        $this->assertCount(0, $mismatches, 'Per-account balance mismatches: '.json_encode($mismatches));
        $this->assertCount(0, $dupes, 'Duplicate (tx,account) entries: '.json_encode($dupes));
        $this->assertCount(0, $orphans, 'Orphan entries: '.json_encode($orphans));
        $this->assertSame(0.0, $report['totals']['diff'], 'Global totals diff must be 0');
    }
}
