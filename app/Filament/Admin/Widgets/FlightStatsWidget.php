<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Account;
use App\Models\Flight\FlightBooking;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FlightStatsWidget extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $totalBalance = Account::query()
            ->where('is_active', true)
            ->where('module_type', 'flights')
            ->sum('balance') ?? 0;

        $totalBookings = FlightBooking::count();
        $revenueThisMonth = FlightBooking::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('selling_price') ?? 0;

        $dailyBookings = $this->dailyBookingCounts();
        $dailyRevenue = $this->dailyRevenueTotals();

        return [
            Stat::make('إجمالي أرصدة الطيران', number_format($totalBalance, 2).' ج.م')
                ->description('إجمالي أرصدة الحسابات والمحافظ')
                ->descriptionIcon('heroicon-o-banknotes')
                ->color('primary')
                ->chart($dailyRevenue)
                ->extraAttributes([
                    'class' => 'hover:scale-105 transition-transform duration-300',
                ]),

            Stat::make('حجوزات الطيران', number_format($totalBookings))
                ->description('إجمالي الحجوزات المسجلة')
                ->descriptionIcon('heroicon-o-paper-airplane')
                ->color('success')
                ->chart($dailyBookings)
                ->extraAttributes([
                    'class' => 'hover:scale-105 transition-transform duration-300',
                ]),

            Stat::make('إيرادات الشهر', number_format($revenueThisMonth, 2).' ج.م')
                ->description('إجمالي مبيعات الشهر الحالي')
                ->descriptionIcon('heroicon-o-arrow-trending-up')
                ->color('warning')
                ->chart($dailyRevenue)
                ->extraAttributes([
                    'class' => 'hover:scale-105 transition-transform duration-300',
                ]),
        ];
    }

    /**
     * @return array<int, int>
     */
    private function dailyBookingCounts(int $days = 7): array
    {
        return collect(range($days - 1, 0))
            ->map(fn (int $daysAgo): int => FlightBooking::query()
                ->whereDate('created_at', now()->subDays($daysAgo))
                ->count())
            ->all();
    }

    /**
     * @return array<int, float>
     */
    private function dailyRevenueTotals(int $days = 7): array
    {
        return collect(range($days - 1, 0))
            ->map(fn (int $daysAgo): float => (float) (FlightBooking::query()
                ->whereDate('created_at', now()->subDays($daysAgo))
                ->sum('selling_price') ?? 0))
            ->all();
    }
}
