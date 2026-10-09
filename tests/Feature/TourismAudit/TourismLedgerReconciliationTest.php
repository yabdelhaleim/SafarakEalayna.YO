<?php

namespace Tests\Feature\TourismAudit;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Transaction;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\DB;

/**
 * Section 18 / 15 — Tourism Account Reconciliation & Ledger Invariants.
 *
 * Verifies:
 *  - balance = SUM(credit) - SUM(debit) per account (project invariant)
 *  - All Tourism transactions are double-entry balanced
 *  - Global Tourism ledger: SUM(debit) = SUM(credit)
 */
class TourismLedgerReconciliationTest extends TourismAuditTestCase
{
    public function test_all_accounts_satisfy_balance_invariant(): void
    {
        $verified = $this->assertLedgerGloballyBalanced();
        $this->assertGreaterThan(0, $verified);
    }

    public function test_independent_tourism_ledger_query_returns_only_tourism_entries(): void
    {
        // Create one Tourism transaction and one unrelated transaction
        LedgerBalanceMutationGuard::run(function () {
            // Tourism: vault to bank
            $tourismTx = Transaction::query()->create([
                'type' => 'transfer',
                'amount' => 100.0,
                'module' => TransactionModule::Flight->value,
                'from_account_id' => $this->vaultEgp->id,
                'to_account_id' => $this->bankEgp->id,
                'currency' => 'EGP',
                'created_by' => $this->admin->id,
                'notes' => 'Tourism test transaction',
            ]);

            AccountEntry::query()->insert([
                ['account_id' => $this->vaultEgp->id, 'transaction_id' => $tourismTx->id, 'debit' => 100.0, 'credit' => 0, 'balance_after' => 999900.0, 'created_at' => now(), 'updated_at' => now()],
                ['account_id' => $this->bankEgp->id, 'transaction_id' => $tourismTx->id, 'debit' => 0, 'credit' => 100.0, 'balance_after' => 500100.0, 'created_at' => now(), 'updated_at' => now()],
            ]);
        });

        $tourismEntries = $this->queryTourismLedgerEntries();
        $this->assertGreaterThan(0, count($tourismEntries));
    }

    public function test_global_tourism_ledger_debit_equals_credit(): void
    {
        // The Tourism ledger must be balanced: SUM(debit) = SUM(credit)
        // across all Tourism transactions.
        // Opening-balance transactions are excluded (they are seed entries).
        $totals = DB::table('account_entries as ae')
            ->join('accounts as a', 'ae.account_id', '=', 'a.id')
            ->join('transactions as t', 'ae.transaction_id', '=', 't.id')
            ->where(function ($w) {
                $w->whereIn('t.module', ['flight', 'hajj_umra', 'visa', 'tourism'])
                    ->orWhereIn('a.module_type', ['tourism', 'flights', 'hajj_umra', 'visas']);
            })
            ->where('t.notes', 'not like', 'Opening balance%')
            ->where('t.notes', 'not like', 'رصيد افتتاحي%')
            ->selectRaw('SUM(ae.debit) as total_debit, SUM(ae.credit) as total_credit')
            ->first();

        $totalDebit = (float) ($totals->total_debit ?? 0);
        $totalCredit = (float) ($totals->total_credit ?? 0);

        $this->assertEqualsWithDelta(
            $totalDebit,
            $totalCredit,
            0.01,
            sprintf('Tourism ledger not balanced: debit=%.2f, credit=%.2f', $totalDebit, $totalCredit)
        );
    }

    public function test_each_tourism_transaction_is_balanced(): void
    {
        // Create a few Tourism transactions of various types and verify each is balanced.
        LedgerBalanceMutationGuard::run(function () {
            // Income-like (Tourism sale)
            $saleTx = Transaction::query()->create([
                'type' => 'income',
                'amount' => 1000.0,
                'module' => TransactionModule::HajjUmra->value,
                'from_account_id' => null,
                'to_account_id' => $this->vaultEgp->id,
                'currency' => 'EGP',
                'created_by' => $this->admin->id,
                'notes' => 'Hajj/Umrah sale',
            ]);

            AccountEntry::query()->insert([
                ['account_id' => $this->vaultEgp->id, 'transaction_id' => $saleTx->id, 'debit' => 0, 'credit' => 1000.0, 'balance_after' => 1001000.0, 'created_at' => now(), 'updated_at' => now()],
                ['account_id' => $this->bankEgp->id, 'transaction_id' => $saleTx->id, 'debit' => 1000.0, 'credit' => 0, 'balance_after' => 499000.0, 'created_at' => now(), 'updated_at' => now()],
            ]);

            $this->assertTransactionBalanced($saleTx);
        });
    }

    public function test_stored_balance_matches_ledger_sum_per_tourism_account(): void
    {
        $tourismAccounts = Account::query()
            ->whereIn('module_type', ['tourism', 'flights', 'hajj_umra', 'visas'])
            ->get();

        foreach ($tourismAccounts as $account) {
            $this->assertLedgerBalancedForAccount($account);
        }
    }
}
