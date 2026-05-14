<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class FinancialStatsWidget extends BaseWidget
{
    protected static ?string $pollingInterval = '30s';

    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        $currentMonth = now()->month;
        $currentYear = now()->year;

        $income = DB::table('transactions')
            ->where('type', 'income')
            ->whereMonth('date', $currentMonth)
            ->whereYear('date', $currentYear)
            ->sum('amount') ?? 0;

        $expense = DB::table('transactions')
            ->where('type', 'expense')
            ->whereMonth('date', $currentMonth)
            ->whereYear('date', $currentYear)
            ->sum('amount') ?? 0;

        $profit = $income - $expense;

        // Get previous month data for comparison
        $previousMonthIncome = DB::table('transactions')
            ->where('type', 'income')
            ->whereMonth('date', $currentMonth - 1)
            ->whereYear('date', $currentMonth > 1 ? $currentYear : $currentYear - 1)
            ->sum('amount') ?? 0;

        $incomeGrowth = $previousMonthIncome > 0
            ? (($income - $previousMonthIncome) / $previousMonthIncome) * 100
            : 0;

        return [
            Stat::make('إجمالي الدخل', number_format($income, 2) . ' ج.م')
                ->description($incomeGrowth >= 0 ? '+ ' . number_format($incomeGrowth, 1) . '%' : number_format($incomeGrowth, 1) . '% من الشهر الماضي')
                ->descriptionIcon($incomeGrowth >= 0 ? 'heroicon-o-arrow-trending-up' : 'heroicon-o-arrow-trending-down')
                ->color('success')
                ->chart([7, 12, 10, 14, 12, 16, 18, 15, 20, 18, 22, 20])
                ->extraAttributes([
                    'class' => 'hover:scale-105 transition-transform duration-300'
                ]),

            Stat::make('إجمالي المصروفات', number_format($expense, 2) . ' ج.م')
                ->description('هذا الشهر')
                ->descriptionIcon('heroicon-o-arrow-trending-down')
                ->color('danger')
                ->chart([5, 4, 6, 3, 5, 4, 3, 4, 5, 3, 4, 3])
                ->extraAttributes([
                    'class' => 'hover:scale-105 transition-transform duration-300'
                ]),

            Stat::make('صافي الربح', number_format($profit, 2) . ' ج.م')
                ->description('هذا الشهر')
                ->descriptionIcon('heroicon-o-chart-pie')
                ->color($profit >= 0 ? 'success' : 'danger')
                ->chart([
                    $profit >= 0 ? abs($profit / 1000) : abs($profit / 1000),
                    $profit >= 0 ? abs($profit / 800) : abs($profit / 800),
                    $profit >= 0 ? abs($profit / 1200) : abs($profit / 1200),
                ])
                ->extraAttributes([
                    'class' => 'hover:scale-105 transition-transform duration-300'
                ]),
        ];
    }

    protected function getColumns(): int
    {
        return 3;
    }
}
