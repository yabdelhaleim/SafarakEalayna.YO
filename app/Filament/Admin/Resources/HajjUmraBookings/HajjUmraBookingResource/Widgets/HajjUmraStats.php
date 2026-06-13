<?php

namespace App\Filament\Admin\Resources\HajjUmraBookings\HajjUmraBookingResource\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class HajjUmraStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('إجمالي الحجوزات', \App\Models\HajjUmraBooking::count())
                ->description('إجمالي عدد حجز الحج والعمرة')
                ->descriptionIcon('heroicon-m-building-library')
                ->color('info'),
            Stat::make('إجمالي الأرباح', number_format(\App\Models\HajjUmraBooking::sum('profit') ?? 0, 2).' ج.م')
                ->description('صافي الربح المتوقع من جميع الحجوزات')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),
            Stat::make('المدفوعات المحصلة', number_format(\App\Models\HajjUmraPayment::sum('amount') ?? 0, 2).' ج.م')
                ->description('إجمالي ما تم تحصيله من العملاء فعلياً')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('primary'),
        ];
    }
}
