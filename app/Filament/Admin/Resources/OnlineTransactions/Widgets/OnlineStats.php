<?php

namespace App\Filament\Admin\Resources\OnlineTransactions\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OnlineStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('إجمالي المعاملات', \App\Models\Online\OnlineTransaction::count())
                ->description('إجمالي عدد معاملات الخدمات الإلكترونية')
                ->descriptionIcon('heroicon-m-globe-alt')
                ->color('info'),
            Stat::make('إجمالي الأرباح', number_format(\App\Models\Online\OnlineTransaction::sum('profit'), 2) . ' ج.م')
                ->description('صافي الربح من الخدمات الإلكترونية')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),
            Stat::make('المبالغ المحصلة', number_format(\App\Models\Online\OnlineTransaction::sum('selling_price'), 2) . ' ج.م')
                ->description('إجمالي المبالغ التي تم استلامها من العملاء')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('primary'),
        ];
    }
}
