<?php

namespace App\Filament\Admin\Resources\Programs\Widgets;

use App\Models\Program;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ProgramProfitability extends BaseWidget
{
    public ?Program $record = null;

    protected function getStats(): array
    {
        if (!$this->record) {
            return [];
        }

        $bookings = $this->record->bookings;
        
        $totalRevenue = $bookings->sum(fn ($booking) => $booking->total_selling_price);
        $bookingCosts = $bookings->sum(
            fn ($booking) => (float) $booking->purchase_price
                + (float) ($booking->companion_purchase_price ?? 0)
        );
        
        $extraCosts = \App\Models\Transaction::where('program_id', $this->record->id)
            ->where('type', \App\Enums\TransactionType::Expense->value)
            ->sum('amount') ?? 0;
            
        $totalCosts = $bookingCosts + $extraCosts;
        $profit = $totalRevenue - $totalCosts;
        
        $profitMargin = $totalRevenue > 0 ? ($profit / $totalRevenue) * 100 : 0;

        return [
            Stat::make('إجمالي الإيرادات', number_format($totalRevenue, 2) . ' ج.م')
                ->description('إجمالي مبيعات البرنامج من الحجوزات')
                ->icon('heroicon-o-banknotes')
                ->color('success'),
                
            Stat::make('إجمالي التكاليف', number_format($totalCosts, 2) . ' ج.م')
                ->description('تكاليف الحجوزات + المصاريف التشغيلية')
                ->icon('heroicon-o-shopping-cart')
                ->color('danger'),
                
            Stat::make('صافي الربح', number_format($profit, 2) . ' ج.م')
                ->description('هامش الربح: ' . number_format($profitMargin, 1) . '%')
                ->icon('heroicon-o-presentation-chart-line')
                ->color($profit >= 0 ? 'success' : 'danger'),
        ];
    }
}
