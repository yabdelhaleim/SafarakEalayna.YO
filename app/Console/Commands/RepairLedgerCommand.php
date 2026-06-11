<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Services\Finance\LedgerReconciliationService;
use App\Services\Finance\LedgerRepairService;
use App\Support\Finance\AccountModuleDivision;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature(
    signature: 'ledger:repair
        {--dry-run : Preview counts only, no writes}
        {--force : Apply without confirmation}
        {--backfill-only : Only backfill single-leg transactions}
        {--customers-only : Only sync customer balances}
        {--chains-only : Rebuild balance_after chains for liquidity accounts}
        {--limit= : Limit backfill batch size}',
)]
class RepairLedgerCommand extends Command
{
    protected $description = 'إصلاح الدفتر: ترحيل القيود أحادية الساق + مزامنة ذمم العملاء + تصحيح تصنيف الخزائن';

    public function handle(LedgerRepairService $repair): int
    {
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        if ($this->option('dry-run')) {
            $scan = app(LedgerReconciliationService::class)->runPostingAndBalanceIntegrityScan();
            $this->info('معاينة — لن يُطبَّق أي تعديل:');
            $this->table(['المؤشر', 'القيمة'], [
                ['فرق المدين/الدائن الإجمالي', $scan['global_totals_delta']],
                ['قيود أحادية الساق', $scan['legacy_single_leg_transactions'] ?? '—'],
                ['انحراف خزائن السيولة', $scan['treasury_liquidity_drift_count'] ?? 0],
                ['انحراف ذمم العملاء', $scan['customer_drift_count'] ?? 0],
            ]);

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('تطبيق إصلاح الدفتر الكامل؟', true)) {
            return self::SUCCESS;
        }

        if ($this->option('backfill-only')) {
            $result = $repair->backfillLegacySingleLegPostings($limit);
            $this->info("Backfill: {$result['backfilled']} applied, {$result['skipped']} skipped, ".count($result['errors']).' errors');

            return count($result['errors']) > 0 ? self::FAILURE : self::SUCCESS;
        }

        if ($this->option('customers-only')) {
            $result = $repair->syncCustomerBalancesFromLedger();
            $this->info("Customers: {$result['synced']} synced, {$result['zeroed']} zeroed, {$result['skipped']} ok");

            return self::SUCCESS;
        }

        if ($this->option('chains-only')) {
            $ids = Account::query()
                ->tap(fn ($q) => AccountModuleDivision::applyLiquidityTreasuryScope($q))
                ->pluck('id')
                ->all();
            $result = $repair->rebuildBrokenBalanceAfterChains($ids);
            $this->info("Chains: {$result['accounts_fixed']} accounts, {$result['entries_fixed']} entries fixed");

            return self::SUCCESS;
        }

        $this->info('جاري إصلاح الدفتر...');
        $report = $repair->runFullRepair($limit);

        $this->newLine();
        $this->info('── قبل الإصلاح ──');
        $this->line(json_encode($report['before'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->newLine();
        $this->info('── نتائج الإصلاح ──');
        $this->line('Backfill: '.json_encode($report['backfill'], JSON_UNESCAPED_UNICODE));
        if (isset($report['flight_sales'])) {
            $this->line('Flight sales: '.json_encode($report['flight_sales'], JSON_UNESCAPED_UNICODE));
        }
        $this->line('Customers: '.json_encode($report['customers'], JSON_UNESCAPED_UNICODE));
        $this->line('Modules: '.json_encode($report['modules'], JSON_UNESCAPED_UNICODE));

        $this->newLine();
        $this->info('── بعد الإصلاح ──');
        $this->line(json_encode($report['after'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        if ($report['after']['global_ok'] && $report['after']['treasury_drift'] === 0 && $report['after']['customer_drift'] === 0) {
            $this->info('✓ الدفتر متوازن وجميع الأرصدة متطابقة.');

            return self::SUCCESS;
        }

        if ($report['after']['legacy_single_leg'] === 0 && $report['after']['customer_drift'] === 0) {
            $this->warn('تم الإصلاح الجوهري. راجع أي تحذيرات متبقية أعلاه.');

            return self::SUCCESS;
        }

        $this->warn('بقي بعض الانحرافات — راجع التفاصيل.');

        return self::SUCCESS;
    }
}
