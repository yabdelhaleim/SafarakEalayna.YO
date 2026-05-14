<?php

namespace App\Filament\Admin\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class GeneralFinanceStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $treasuryBalance = \App\Models\Account::where('type', \App\Enums\AccountType::Treasury)->sum('balance');
        $bankBalance = \App\Models\Account::where('type', \App\Enums\AccountType::Bank)->sum('balance');
        $walletBalance = \App\Models\Account::where('type', \App\Enums\AccountType::Wallet)->sum('balance');

        return [
            Stat::make('رصيد الخزينة', number_format($treasuryBalance, 2) . ' ج.م')
                ->description('إجمالي النقدية المتاحة بالخزينة')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),
            Stat::make('رصيد البنوك', number_format($bankBalance, 2) . ' ج.م')
                ->description('إجمالي الأرصدة في الحسابات البنكية')
                ->descriptionIcon('heroicon-m-building-library')
                ->color('info'),
            Stat::make('رصيد المحافظ', number_format($walletBalance, 2) . ' ج.م')
                ->description('إجمالي أرصدة المحافظ الإلكترونية')
                ->descriptionIcon('heroicon-m-wallet')
                ->color('primary'),
        ];
    }
}
