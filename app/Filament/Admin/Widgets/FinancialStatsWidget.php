<?php

namespace App\Filament\Admin\Widgets;

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
        $currentYear = $now->year;

        $previousMonth = $now->copy()->subMonth();
        $prevMonth = $previousMonth->month;
        $prevYear = $previousMonth->year;

        $income = DB::table('transactions')
            ->where('type', 'income')
            ->whereMonth('created_at', $currentMonth)
            ->whereYear('created_at', $currentYear)
            ->sum('amount') ?? 0;

        $expense = DB::table('transactions')
            ->where('type', 'expense')
            ->whereMonth('created_at', $currentMonth)
            ->whereYear('created_at', $currentYear)
            ->sum('amount') ?? 0;

        $profit = $income - $expense;

        $previousMonthIncome = DB::table('transactions')
            ->where('type', 'income')
            ->whereMonth('created_at', $prevMonth)
            ->whereYear('created_at', $prevYear)
            ->sum('amount') ?? 0;

        $incomeGrowth = $previousMonthIncome > 0
            ? (($income - $previousMonthIncome) / $previousMonthIncome) * 100
            : 0;

        $monthlyIncome = $this->monthlyTransactionTotals('income');
        $monthlyExpense = $this->monthlyTransactionTotals('expense');
        $monthlyProfit = array_map(
            fn (float $incomeValue, float $expenseValue): float => $incomeValue - $expenseValue,
            $monthlyIncome,
            $monthlyExpense
        );

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
     * @return array<int, float>
     */
    private function monthlyTransactionTotals(string $type, int $months = 7): array
    {
        return collect(range($months - 1, 0))
            ->map(function (int $monthsAgo) use ($type): float {
                $date = now()->subMonths($monthsAgo);

                return (float) (DB::table('transactions')
                    ->where('type', $type)
                    ->whereMonth('created_at', $date->month)
                    ->whereYear('created_at', $date->year)
                    ->sum('amount') ?? 0);
            })
            ->all();
    }

    protected function getColumns(): int
    {
        return 3;
    }
}
