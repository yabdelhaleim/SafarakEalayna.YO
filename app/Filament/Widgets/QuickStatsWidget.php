<?php

namespace App\Filament\Widgets;

use App\Models\Customer;
use App\Models\Employee\Employee;
use App\Models\Invoice;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class QuickStatsWidget extends BaseWidget
{
    protected static ?string $pollingInterval = '30s';

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $customersCount = Customer::count();
        $employeesCount = Employee::where('is_active', true)->count();
        $invoicesCount = Invoice::count();
        $pendingTasks = DB::table('tasks')->where('status', 'pending')->count() ?? 0;

        return [
            Stat::make('العملاء', number_format($customersCount))
                ->description('إجمالي العملاء')
                ->descriptionIcon('heroicon-o-users')
                ->color('primary')
                ->chart([7, 12, 10, 14, 12, 16, 18])
                ->extraAttributes([
                    'class' => 'hover:scale-105 transition-transform duration-300'
                ]),

            Stat::make('الموظفين النشطين', number_format($employeesCount))
                ->description('من أصل ' . Employee::count() . ' موظف')
                ->descriptionIcon('heroicon-o-briefcase')
                ->color('success')
                ->chart([5, 8, 6, 9, 7, 10, 8])
                ->extraAttributes([
                    'class' => 'hover:scale-105 transition-transform duration-300'
                ]),

            Stat::make('الفواتير', number_format($invoicesCount))
                ->description('هذا الشهر')
                ->descriptionIcon('heroicon-o-document-text')
                ->color('warning')
                ->chart([10, 15, 12, 18, 14, 20, 16])
                ->extraAttributes([
                    'class' => 'hover:scale-105 transition-transform duration-300'
                ]),

            Stat::make('المهام المعلقة', number_format($pendingTasks))
                ->description('تحتاج إجراء')
                ->descriptionIcon('heroicon-o-clipboard-document-list')
                ->color($pendingTasks > 10 ? 'danger' : 'info')
                ->chart([3, 5, 4, 6, 5, 7, $pendingTasks])
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
