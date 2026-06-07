<?php

namespace App\Console\Commands;

use App\Services\Finance\LedgerReconciliationService;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature(
    signature: 'ledger:reconcile {--json : Emit JSON summary to stdout}',
    aliases: ['finance:reconcile-ledger'],
)]
class LedgerReconcileCommand extends Command
{
    protected $description = 'تسوية يومية: معاملات بلا أسطر، عدم اتزان لكل معاملة، إجمالي المدين/الدائن، ومقابلة أرصدة الحسابات مع القيود';

    public function handle(LedgerReconciliationService $reconcile): int
    {
        $run = $reconcile->runDaily();
        $extra = $reconcile->runPostingAndBalanceIntegrityScan();

        $payload = [
            'run_id' => $run->id,
            'run_at' => $run->run_at->toIso8601String(),
            'transactions_scanned' => $run->transactions_scanned,
            'imbalanced_count' => $run->imbalanced_count,
            'missing_entries_count' => $run->missing_entries_count,
            'global_totals' => [
                'ok' => $extra['global_totals_ok'],
                'debit' => $extra['global_total_debit'],
                'credit' => $extra['global_total_credit'],
                'delta' => $extra['global_totals_delta'],
            ],
            'accounts_balance_drift_count' => $extra['accounts_with_balance_drift'],
            'treasury_liquidity_drift_count' => $extra['treasury_liquidity_drift_count'] ?? 0,
            'customer_drift_count' => $extra['customer_drift_count'] ?? 0,
            'legacy_single_leg_transactions' => $extra['legacy_single_leg_transactions'] ?? 0,
        ];

        if ($this->option('json')) {
            $payload['balance_drift_samples'] = $extra['balance_drift_samples'];
            $this->line(json_encode($payload));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Reconciliation #%d: scanned=%d, imbalanced=%d, missing_entries=%d',
            $run->id,
            $run->transactions_scanned,
            $run->imbalanced_count,
            $run->missing_entries_count
        ));

        $this->line(sprintf(
            'Global Σdebit=%s Σcredit=%s delta=%s %s',
            $extra['global_total_debit'],
            $extra['global_total_credit'],
            $extra['global_totals_delta'],
            $extra['global_totals_ok'] ? 'OK' : 'CRITICAL'
        ));

        $this->line(sprintf(
            'Balance vs ledger drift: total=%d treasury=%d customers=%d',
            $extra['accounts_with_balance_drift'],
            $extra['treasury_liquidity_drift_count'] ?? 0,
            $extra['customer_drift_count'] ?? 0
        ));

        if (isset($extra['legacy_single_leg_transactions'])) {
            $this->line(sprintf(
                'Legacy single-leg transactions (historical): %d',
                $extra['legacy_single_leg_transactions']
            ));
        }

        $treasuryDrift = $extra['treasury_liquidity_drift_count'] ?? $extra['accounts_with_balance_drift'];

        if ($run->imbalanced_count === 0 && $run->missing_entries_count === 0 && $extra['global_totals_ok'] && $treasuryDrift === 0) {
            $this->info('جميع فحوص الدفتر ضمن الحدود.');

            return self::SUCCESS;
        }

        if ($run->imbalanced_count > 0 || $run->missing_entries_count > 0) {
            $this->warn('تم تسجيل انحرافات على مستوى المعاملة — راجع ledger_reconciliation_findings.');
            foreach ($run->findings->take(20) as $f) {
                $this->line(sprintf(
                    '  tx=%s issue=%s detail=%s',
                    $f->transaction_id ?? 'null',
                    $f->issue_type,
                    ($f->delta !== null ? 'Δ='.$f->delta : '').($f->detail ? ' '.$f->detail : '')
                ));
            }
            if ($run->findings->count() > 20) {
                $this->comment('(... عرض 20 ملاحظة فقط)');
            }
        }

        return self::SUCCESS;
    }
}
