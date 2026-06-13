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
        $now = now();
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();
        $lastMonthStart = $now->copy()->subMonth()->startOfMonth();
        $lastMonthEnd = $now->copy()->subMonth()->endOfMonth();

        $monthRevenue = Booking::query()
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->where('payment_status', 'paid')
            ->sum('total_price') ?? 0;

        $monthBookings = Booking::query()
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->count();

        $lastMonthBookings = Booking::query()
            ->whereBetween('created_at', [$lastMonthStart, $lastMonthEnd])
            ->count();

        $bookingGrowth = $lastMonthBookings > 0
            ? round((($monthBookings - $lastMonthBookings) / $lastMonthBookings) * 100, 1)
            : 0;

        $chartData = collect(range(5, 0))
            ->map(function (int $monthsAgo): int {
                $start = now()->subMonths($monthsAgo)->startOfMonth();
                $end = now()->subMonths($monthsAgo)->endOfMonth();

                return Booking::query()
                    ->whereBetween('created_at', [$start, $end])
                    ->count();
            })
            ->values()
            ->all();

        if ($chartData === []) {
            $chartData = [0, 0, 0, 0, 0, 0];
        }

        return [
            Stat::make('إجمالي الحجوزات', number_format($monthBookings))
                ->description($bookingGrowth >= 0 ? "↑ {$bookingGrowth}% هذا الشهر" : '↓ '.abs($bookingGrowth).'% هذا الشهر')
                ->descriptionIcon($bookingGrowth >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($bookingGrowth >= 0 ? 'success' : 'danger')
                ->chart($chartData),

            Stat::make('الإيرادات (ج.م)', 'ج.م '.number_format($monthRevenue, 0))
                ->description('إيرادات '.$now->translatedFormat('F Y'))
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
