<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Customer;
use App\Models\FlightBooking;
use App\Models\HajjUmraBooking;
use App\Models\VisaBooking;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class QuickStatsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'نظرة عامة';

    protected ?string $description = 'أرقام حية من قاعدة البيانات';

    protected function getStats(): array
    {
        return [
            Stat::make('إجمالي العملاء', Customer::count())
                ->description('العملاء المسجلين في النظام')
                ->descriptionIcon('heroicon-m-users')
                ->chart([7, 12, 10, 18, 22, 19, 24])
                ->color('success'),
            Stat::make('حجوزات الطيران', FlightBooking::count())
                ->description('إجمالي الحجوزات المسجّلة')
                ->descriptionIcon('heroicon-m-paper-airplane')
                ->chart([3, 8, 6, 12, 15, 11, 14])
                ->color('info'),
            Stat::make('الحج والعمرة', HajjUmraBooking::count())
                ->description('إجمالي الحجوزات')
                ->descriptionIcon('heroicon-m-building-library')
                ->chart([2, 4, 3, 5, 6, 5, 7])
                ->color('warning'),
            Stat::make('التأشيرات', VisaBooking::count())
                ->description('إجمالي الطلبات')
                ->descriptionIcon('heroicon-m-identification')
                ->chart([1, 2, 2, 4, 3, 5, 4])
                ->color('danger'),
        ];
    }
}
