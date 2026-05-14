<?php

namespace App\Filament\Admin\Resources\VisaBookings\VisaBookingResource\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class VisaStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('إجمالي الطلبات', \App\Models\VisaBooking::count())
                ->description('إجمالي عدد طلبات التأشيرة')
                ->descriptionIcon('heroicon-m-identification')
                ->color('info'),
            Stat::make('إجمالي الأرباح', number_format(\App\Models\VisaBooking::sum('profit'), 2) . ' ج.م')
                ->description('صافي الربح المتوقع من جميع التأشيرات')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),
            Stat::make('المدفوعات المحصلة', number_format(\App\Models\VisaPayment::sum('amount'), 2) . ' ج.م')
                ->description('إجمالي ما تم تحصيله من العملاء فعلياً')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('primary'),
        ];
    }
}
