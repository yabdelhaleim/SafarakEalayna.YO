<?php

declare(strict_types=1);

namespace Tests\Stress\Support;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Transaction;
use App\Services\Finance\AccountService;
use Illuminate\Support\Facades\DB;
use Tests\Stress\FinanceStressTestCase;

/**
 * DebugDiag — focused diagnostic test (NOT a stress test).
 *
 * Traces exactly where the extra AccountEntry rows come from in
 * directBalancedTransaction. Will be deleted once the root cause is found.
 *
 * @internal
 */
final class DebugDiag extends FinanceStressTestCase
{
    public function test_trace_one_direct_balanced_transaction(): void
    {
        // Fresh DB. Just 2 accounts, 1 open each, 1 directBalancedTransaction.
        $accts = StressBulkFactory::bulkLiquidityAccounts(2, 'office');
        StressBulkFactory::openBalance($accts[0], 5000.0, $this->actorId);
        StressBulkFactory::openBalance($accts[1], 5000.0, $this->actorId);

        fwrite(STDOUT, "\n[diag] before directBalancedTransaction:\n");
        $this->dumpEntries('PRE-TX');

        $tx = StressBulkFactory::directBalancedTransaction([
            'from_account_id' => $accts[0]->id,
            'to_account_id'   => $accts[1]->id,
            'amount'          => 1000.0,
        ]);

        fwrite(STDOUT, "\n[diag] after directBalancedTransaction (tx.id={$tx->id}):\n");
        $this->dumpEntries('POST-TX');

        $entriesForTx = AccountEntry::where('transaction_id', $tx->id)->get();
        fwrite(STDOUT, "\n[diag] entries on tx.id={$tx->id}: count=".$entriesForTx->count()."\n");
        foreach ($entriesForTx as $e) {
            fwrite(STDOUT, "  id={$e->id} account={$e->account_id} debit={$e->debit} credit={$e->credit} balance_after={$e->balance_after}\n");
        }

        $this->assertTrue(true);
    }

    private function dumpEntries(string $tag): void
    {
        $rows = DB::select("SELECT id, transaction_id, account_id, debit, credit, balance_after, notes FROM account_entries ORDER BY id");
        fwrite(STDOUT, "[diag-{$tag}] ".count($rows)." entries:\n");
        foreach ($rows as $r) {
            fwrite(STDOUT, sprintf(
                "  id=%s tx=%s account=%s debit=%s credit=%s balance_after=%s notes=%s\n",
                $r->id, $r->transaction_id ?? 'NULL', $r->account_id, $r->debit, $r->credit, $r->balance_after, $r->notes
            ));
        }
        $accounts = DB::select("SELECT id, name, balance FROM accounts ORDER BY id");
        fwrite(STDOUT, "[diag-{$tag}] accounts:\n");
        foreach ($accounts as $a) {
            fwrite(STDOUT, sprintf("  id=%s name=%s balance=%s\n", $a->id, $a->name, $a->balance));
        }
    }
}
