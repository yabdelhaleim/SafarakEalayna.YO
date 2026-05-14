<?php

namespace App\Services\Finance;

use App\Models\AccountEntry;
use App\Models\Account;
use App\Models\LedgerReconciliationFinding;
use App\Models\LedgerReconciliationRun;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LedgerReconciliationService
{
    /**
     * مسح شامل: معاملات بلا أسطر في account_entries أو مجموع مدين ≠ دائن.
     */
    public function runDaily(): LedgerReconciliationRun
    {
        $tolerance = (float) config('accounting.reconciliation.tolerance', 0.02);

        /** @var \Illuminate\Support\LazyCollection<int, \stdClass> $imbalanced */
        $imbalanced = AccountEntry::query()
            ->select('transaction_id')
            ->selectRaw('SUM(debit) as debit_sum, SUM(credit) as credit_sum, COUNT(*) as line_count')
            ->groupBy('transaction_id')
            ->havingRaw('ABS(SUM(debit) - SUM(credit)) > ?', [$tolerance])
            ->cursor();

        $missingIds = Transaction::query()
            ->doesntHave('entries')
            ->pluck('id');

        return DB::transaction(function () use ($imbalanced, $missingIds, $tolerance) {
            $run = LedgerReconciliationRun::query()->create([
                'run_at' => now(),
                'transactions_scanned' => Transaction::query()->count(),
                'imbalanced_count' => 0,
                'missing_entries_count' => $missingIds->count(),
                'status' => 'completed',
                'notes' => null,
            ]);

            foreach ($missingIds as $tid) {
                LedgerReconciliationFinding::query()->create([
                    'ledger_reconciliation_run_id' => $run->id,
                    'transaction_id' => $tid,
                    'issue_type' => 'missing_entries',
                    'debit_sum' => null,
                    'credit_sum' => null,
                    'delta' => null,
                    'detail' => 'لا توجد حركات account_entries لهذه المعاملة.',
                ]);
            }

            foreach ($imbalanced as $row) {
                $d = round((float) $row->debit_sum, 2);
                $c = round((float) $row->credit_sum, 2);

                LedgerReconciliationFinding::query()->create([
                    'ledger_reconciliation_run_id' => $run->id,
                    'transaction_id' => (int) $row->transaction_id,
                    'issue_type' => 'imbalanced_journal',
                    'debit_sum' => $d,
                    'credit_sum' => $c,
                    'delta' => round($d - $c, 4),
                    'detail' => 'خطوط: '.$row->line_count,
                ]);

                $run->imbalanced_count = $run->imbalanced_count + 1;
            }

            $run->save();

            if ($run->imbalanced_count > 0 || $run->missing_entries_count > 0) {
                logger()->warning('ledger_reconciliation_findings', [
                    'run_id' => $run->id,
                    'imbalanced' => $run->imbalanced_count,
                    'missing_entries' => $run->missing_entries_count,
                ]);
            }

            return $run->fresh('findings');
        });
    }

    /**
     * فحوصات قراءة فقط: إجمالي المدين/الدائن على مستوى النظام، ومقارنة accounts.balance بصافي القيود.
     *
     * @return array<string, mixed>
     */
    public function runPostingAndBalanceIntegrityScan(): array
    {
        $tolerance = (float) config('accounting.reconciliation.tolerance', 0.02);
        $balanceTol = (float) config('accounting.reconciliation.balance_vs_entries_tolerance', 0.05);

        $sums = AccountEntry::query()->selectRaw('SUM(debit) AS td, SUM(credit) AS tc')->first();
        $totalDebit = round((float) ($sums->td ?? 0), 2);
        $totalCredit = round((float) ($sums->tc ?? 0), 2);
        $globalDelta = round(abs($totalDebit - $totalCredit), 2);

        $globalOk = $globalDelta <= $tolerance;

        if (! $globalOk) {
            logger()->critical('ledger_global_debit_credit_totals_mismatch', [
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'delta' => $globalDelta,
            ]);
        }

        /** @var Collection<int, float> $ledgerNetByAccountId */
        $ledgerNetByAccountId = AccountEntry::query()
            ->selectRaw('account_id')
            ->selectRaw('SUM(COALESCE(credit, 0) - COALESCE(debit, 0)) AS net_flow')
            ->groupBy('account_id')
            ->pluck('net_flow', 'account_id')
            ->map(fn ($v) => round((float) $v, 2));

        $driftSamples = [];

        foreach (Account::query()->select(['id', 'balance'])->cursor() as $account) {
            /** @var Account $account */
            $ledgerNet = round((float) (
                $ledgerNetByAccountId->get((int) $account->id)
                ?? $ledgerNetByAccountId->get((string) $account->id)
                ?? 0.0
            ), 2);
            $stored = round((float) $account->balance, 2);

            if (abs($stored - $ledgerNet) > $balanceTol) {
                $driftSamples[] = [
                    'account_id' => $account->id,
                    'stored_balance' => $stored,
                    'ledger_net_credit_minus_debit' => $ledgerNet,
                    'difference' => round($stored - $ledgerNet, 2),
                ];

                if (count($driftSamples) >= 500) {
                    logger()->warning('balance_vs_ledger_scan_truncated', ['limit' => 500]);

                    break;
                }
            }
        }

        if ($driftSamples !== []) {
            foreach (array_slice($driftSamples, 0, 40) as $sample) {
                logger()->warning('balance_vs_ledger_row_mismatch', $sample);
            }

            if (count($driftSamples) > 40) {
                logger()->notice('balance_vs_ledger_additional_rows_omitted_from_log', [
                    'total_detected' => count($driftSamples),
                ]);
            }
        }

        return [
            'global_totals_ok' => $globalOk,
            'global_total_debit' => $totalDebit,
            'global_total_credit' => $totalCredit,
            'global_totals_delta' => $globalDelta,
            'accounts_with_balance_drift' => count($driftSamples),
            'balance_drift_samples' => array_slice($driftSamples, 0, 25),
        ];
    }
}
