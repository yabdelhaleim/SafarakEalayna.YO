<?php

namespace App\Filament\Admin\Widgets;

use App\Services\Reports\ReportFinanceService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Schema;

class FinancialStatsWidget extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = '30s';

    protected static ?int $sort = 2;

    /**
     * Division scope for the three KPI cards. Defaults to 'all' so the
     * widget shows combined tourism + office figures the same way it
     * used to, but operators can pin it to one division via
     * Dashboard → "نطاق المؤشرات" toggle (querystring `?division=tourism`).
     * 'tourism' fixes the long-standing bug where flight / hajj / visa
     * revenue appeared lower than expected because the previous filter
     * omitted transfer-type rows.
     */
    public ?string $division = 'all';

    public static function canView(): bool
    {
        return auth()->user() && in_array(auth()->user()->role, ['admin', 'owner'], true);
    }

    public function mount(): void
    {
        $this->division = in_array(request()->query('division'), ['tourism', 'office', 'all'], true)
            ? request()->query('division')
            : 'all';
    }

    protected function getStats(): array
    {
        if (! Schema::hasTable('transactions')) {
            return [];
        }

        $now = now();
        $service = app(ReportFinanceService::class);

        $currentFilters = [
            'from_date' => $now->copy()->startOfMonth()->toDateString(),
            'to_date'   => $now->copy()->endOfMonth()->toDateString(),
        ];
        $previousFilters = [
            'from_date' => $now->copy()->subMonth()->startOfMonth()->toDateString(),
            'to_date'   => $now->copy()->subMonth()->endOfMonth()->toDateString(),
        ];

        if ($this->division !== 'all') {
            $currentFilters['category'] = $this->division;
            $previousFilters['category'] = $this->division;
        }

        // getFinancialSummary now flows through ProfitLossReportService
        // and therefore includes type='transfer' revenue/COGS rows —
        // fixing the under-reported tourism numbers.
        $currentSummary = $service->getFinancialSummary($currentFilters);
        $prevSummary = $service->getFinancialSummary($previousFilters);

        $income  = (float) ($currentSummary['total_income'] ?? 0);
        $expense = (float) ($currentSummary['total_expense'] ?? 0);
        $profit  = (float) ($currentSummary['net_profit'] ?? 0);

        $previousMonthIncome = (float) ($prevSummary['total_income'] ?? 0);
        $incomeGrowth = $previousMonthIncome > 0
            ? (($income - $previousMonthIncome) / $previousMonthIncome) * 100
            : 0;

        $monthlyIncome  = $this->monthlyFinancialTotals('total_income');
        $monthlyExpense = $this->monthlyFinancialTotals('total_expense');
        $monthlyProfit  = $this->monthlyFinancialTotals('net_profit');

        $divLabel = $this->division === 'tourism' ? ' (السياحة)' : ($this->division === 'office' ? ' (المكتب)' : '');

        return [
            Stat::make('إجمالي الدخل'.$divLabel, number_format($income, 2).' ج.م')
                ->description($incomeGrowth >= 0 ? '+ '.number_format($incomeGrowth, 1).'%' : number_format($incomeGrowth, 1).'% من الشهر الماضي')
                ->descriptionIcon($incomeGrowth >= 0 ? 'heroicon-o-arrow-trending-up' : 'heroicon-o-arrow-trending-down')
                ->color('success')
                ->chart($monthlyIncome)
                ->extraAttributes([
                    'class' => 'hover:scale-105 transition-transform duration-300',
                ]),

            Stat::make('إجمالي المصروفات'.$divLabel, number_format($expense, 2).' ج.م')
                ->description('هذا الشهر')
                ->descriptionIcon('heroicon-o-arrow-trending-down')
                ->color('danger')
                ->chart($monthlyExpense)
                ->extraAttributes([
                    'class' => 'hover:scale-105 transition-transform duration-300',
                ]),

            Stat::make('صافي الربح'.$divLabel, number_format($profit, 2).' ج.م')
                ->description('هذا الشهر')
                ->descriptionIcon('heroicon-o-chart-pie')
                ->color($profit >= 0 ? 'success' : 'danger')
                ->chart(array_map(fn (float $value): float => abs($value), $monthlyProfit))
                ->extraAttributes([
                    'class' => 'hover:scale-105 transition-transform duration-300',
                ]),
        ];
    }

    /**
     * Returns 7-month chart data for a P&L summary key (total_income, total_expense, net_profit).
     *
     * @return array<int, float>
     */
    private function monthlyFinancialTotals(string $key, int $months = 7): array
    {
        $service = app(ReportFinanceService::class);

        return collect(range($months - 1, 0))
            ->map(function (int $monthsAgo) use ($service, $key): float {
                $date = now()->subMonths($monthsAgo);
                $filters = [
                    'from_date' => $date->copy()->startOfMonth()->toDateString(),
                    'to_date'   => $date->copy()->endOfMonth()->toDateString(),
                ];
                if ($this->division !== 'all') {
                    $filters['category'] = $this->division;
                }

                $summary = $service->getFinancialSummary($filters);

                return (float) ($summary[$key] ?? 0);
            })
            ->all();
    }

    protected function getColumns(): int
    {
        return 3;
    }
}
