<?php

namespace App\Filament\Widgets;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Flight;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverviewWidget extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected static ?int $sort = 1;

    protected ?string $heading = 'نظرة عامة';

    protected ?string $pollingInterval = '60s';

    protected static bool $isLazy = true;

    protected function getStats(): array
    {
        $monthRevenue = Booking::whereMonth('created_at', now()->month)
            ->where('payment_status', 'paid')
            ->sum('total_price');

        $monthBookings = Booking::whereMonth('created_at', now()->month)->count();

        $lastMonthBookings = Booking::whereMonth('created_at', now()->subMonth()->month)->count();
        $bookingGrowth = $lastMonthBookings > 0
            ? round((($monthBookings - $lastMonthBookings) / $lastMonthBookings) * 100, 1)
            : 0;

        // Fetch count for chart
        $chartData = Booking::selectRaw('COUNT(*) as count')
            ->whereMonth('created_at', '>=', now()->subMonths(6)->month)
            ->groupByRaw('MONTH(created_at)')
            ->pluck('count')
            ->toArray();

        // Default chart data if empty to prevent empty array warnings
        if (empty($chartData)) {
            $chartData = [0, 0, 0, 0, 0, 0];
        }

        return [
            Stat::make('إجمالي الحجوزات', number_format($monthBookings))
                ->description($bookingGrowth >= 0 ? "↑ {$bookingGrowth}% هذا الشهر" : '↓ '.abs($bookingGrowth).'% هذا الشهر')
                ->descriptionIcon($bookingGrowth >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($bookingGrowth >= 0 ? 'success' : 'danger')
                ->chart($chartData),

            Stat::make('الإيرادات (ج.م)', 'ج.م '.number_format($monthRevenue, 0))
                ->description('إيرادات '.now()->translatedFormat('F Y'))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('primary'),

            Stat::make('العملاء المسجلين', number_format(Customer::active()->count()))
                ->description(Customer::where('status', 'vip')->count().' عميل VIP')
                ->descriptionIcon('heroicon-m-star')
                ->color('warning'),

            Stat::make('رحلات نشطة', Flight::whereIn('status', ['scheduled', 'boarding'])->count())
                ->description(Flight::where('status', 'cancelled')->whereDate('departure_at', today())->count().' إلغاء اليوم')
                ->descriptionIcon('heroicon-m-paper-airplane')
                ->color('info'),
        ];
    }
}
