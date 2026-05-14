<?php

namespace App\Filament\Resources\Flight\TicketModificationResource\Widgets;

use App\Models\Flight\TicketModification;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ModificationStatsWidget extends BaseWidget
{
    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $totalModifications = TicketModification::count();
        $confirmedModifications = TicketModification::where('status', 'confirmed')->count();
        
        $totalFees = TicketModification::where('status', 'confirmed')->sum('airline_change_fee');
        $totalCommissions = TicketModification::where('status', 'confirmed')->sum('agency_commission');
        
        $pendingReconciliation = TicketModification::where('status', 'confirmed')
            ->where('reconciliation_status', 'unreconciled')
            ->count();

        return [
            Stat::make('طلبات التعديل', number_format($totalModifications))
                ->description("مؤكد منها: {$confirmedModifications}")
                ->descriptionIcon('heroicon-o-adjustments-horizontal')
                ->color('primary')
                ->extraAttributes([
                    'class' => 'hover:scale-105 transition-transform duration-300'
                ]),

            Stat::make('غرامات الطيران', number_format((float) $totalFees, 2) . ' EGP')
                ->description('إجمالي الغرامات المخصومة')
                ->descriptionIcon('heroicon-o-currency-dollar')
                ->color('warning')
                ->extraAttributes([
                    'class' => 'hover:scale-105 transition-transform duration-300'
                ]),

            Stat::make('أرباح العمولات', number_format((float) $totalCommissions, 2) . ' EGP')
                ->description('أرباح الوكالة الصافية من التعديلات')
                ->descriptionIcon('heroicon-o-banknotes')
                ->color('success')
                ->extraAttributes([
                    'class' => 'hover:scale-105 transition-transform duration-300'
                ]),

            Stat::make('تعديلات بانتظار المطابقة', number_format($pendingReconciliation))
                ->description('تتطلب مراجعة كشف الطيران')
                ->descriptionIcon('heroicon-o-document-magnifying-glass')
                ->color($pendingReconciliation > 0 ? 'danger' : 'info')
                ->extraAttributes([
                    'class' => 'hover:scale-105 transition-transform duration-300'
                ]),
        ];
    }

    protected function getColumns(): int
    {
        return 4;
    }
}
