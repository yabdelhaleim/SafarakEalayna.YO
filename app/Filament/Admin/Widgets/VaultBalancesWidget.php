<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Account;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class VaultBalancesWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $stats = [];
        
        $modules = [
            'general' => ['label' => 'الخزنة الرئيسية', 'color' => 'gray'],
            'flights' => ['label' => 'خزنة الطيران', 'color' => 'info'],
            'hajj_umra' => ['label' => 'خزنة الحج والعمرة', 'color' => 'success'],
            'bus' => ['label' => 'خزنة الباصات', 'color' => 'warning'],
            'visas' => ['label' => 'خزنة التأشيرات', 'color' => 'danger'],
        ];

        foreach ($modules as $type => $info) {
            $balance = Account::where('module_type', $type)
                ->where('is_module_vault', true)
                ->sum('balance');
                
            $stats[] = Stat::make($info['label'], number_format($balance, 2) . ' ج.م')
                ->description('الرصيد الحالي المتوفر')
                ->icon('heroicon-o-wallet')
                ->color($info['color']);
        }

        return $stats;
    }
}
