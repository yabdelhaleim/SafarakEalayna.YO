<?php

namespace App\Filament\Admin\Widgets;

use App\Services\Reports\ReportFinanceService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinancialStatsWidget extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = '30s';

    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return auth()->user() && in_array(auth()->user()->role, ['admin', 'owner'], true);
    }

    protected function getStats(): array
    {
        if (! Schema::hasTable('transactions')) {
            return [];
        }

        $now = now();
        $currentMonth = $now->month;
        $currentYear  = $now->year;

        $previousMonth = $now->copy()->subMonth();
        $prevMonth     = $previousMonth->month;
        $prevYear      = $previousMonth->year;

        // Use the double-entry P&L service so flight bookings (type=transfer)
        // are correctly counted as revenue / net profit.
        $service = app(ReportFinanceService::class);

        $currentSummary = $service->getFinancialSummary([
            'from_date' => $now->copy()->startOfMonth()->toDateString(),
            'to_date'   => $now->copy()->endOfMonth()->toDateString(),
        ]);

        $prevSummary = $service->getFinancialSummary([
            'from_date' => $previousMonth->copy()->startOfMonth()->toDateString(),
            'to_date'   => $previousMonth->copy()->endOfMonth()->toDateString(),
        ]);

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

        return [
            Stat::make('إجمالي الدخل', number_format($income, 2).' ج.م')
                ->description($incomeGrowth >= 0 ? '+ '.number_format($incomeGrowth, 1).'%' : number_format($incomeGrowth, 1).'% من الشهر الماضي')
                ->descriptionIcon($incomeGrowth >= 0 ? 'heroicon-o-arrow-trending-up' : 'heroicon-o-arrow-trending-down')
                ->color('success')
                ->chart($monthlyIncome)
                ->extraAttributes([
                    'class' => 'hover:scale-105 transition-transform duration-300',
                ]),

            Stat::make('إجمالي المصروفات', number_format($expense, 2).' ج.م')
                ->description('هذا الشهر')
                ->descriptionIcon('heroicon-o-arrow-trending-down')
                ->color('danger')
                ->chart($monthlyExpense)
                ->extraAttributes([
                    'class' => 'hover:scale-105 transition-transform duration-300',
                ]),

            Stat::make('صافي الربح', number_format($profit, 2).' ج.م')
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

                $summary = $service->getFinancialSummary([
                    'from_date' => $date->copy()->startOfMonth()->toDateString(),
                    'to_date'   => $date->copy()->endOfMonth()->toDateString(),
                ]);

                return (float) ($summary[$key] ?? 0);
            })
            ->all();
    }

    protected function getColumns(): int
    {
        return 3;
    }
}
