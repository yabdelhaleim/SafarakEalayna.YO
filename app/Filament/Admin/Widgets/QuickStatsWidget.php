<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Invoice;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class QuickStatsWidget extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = '30s';

    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return auth()->user() && in_array(auth()->user()->role, ['admin', 'owner'], true);
    }

    protected function getStats(): array
    {
        $customersCount = 0;
        $employeesCount = 0;
        $invoicesCount = 0;

        if (Schema::hasTable('customers')) {
            $customersCount = Customer::count();
        }

        if (Schema::hasTable('employees')) {
            $employeesCount = Employee::where('status', 'active')->count();
        }

        if (Schema::hasTable('invoices')) {
            $invoicesCount = Invoice::count();
        }

        $pendingTasks = 0;
        try {
            if (Schema::hasTable('tasks')) {
                $pendingTasks = DB::table('tasks')->where('status', 'pending')->count();
            }
        } catch (\Throwable $e) {
            $pendingTasks = 0;
        }

        return [
            Stat::make('العملاء', number_format($customersCount))
                ->description('إجمالي العملاء')
                ->descriptionIcon('heroicon-o-users')
                ->color('primary')
                ->chart([7, 12, 10, 14, 12, 16, 18])
                ->extraAttributes([
                    'class' => 'hover:scale-105 transition-transform duration-300',
                ]),

            Stat::make('الموظفين النشطين', number_format($employeesCount))
                ->description('من أصل '.(Schema::hasTable('employees') ? Employee::count() : 0).' موظف')
                ->descriptionIcon('heroicon-o-briefcase')
                ->color('success')
                ->chart([5, 8, 6, 9, 7, 10, 8])
                ->extraAttributes([
                    'class' => 'hover:scale-105 transition-transform duration-300',
                ]),

            Stat::make('الفواتير', number_format($invoicesCount))
                ->description('إجمالي الفواتير')
                ->descriptionIcon('heroicon-o-document-text')
                ->color('warning')
                ->chart([10, 15, 12, 18, 14, 20, 16])
                ->extraAttributes([
                    'class' => 'hover:scale-105 transition-transform duration-300',
                ]),

            Stat::make('المهام المعلقة', number_format($pendingTasks))
                ->description('تحتاج إجراء')
                ->descriptionIcon('heroicon-o-clipboard-document-list')
                ->color($pendingTasks > 10 ? 'danger' : 'info')
                ->chart([3, 5, 4, 6, 5, 7, $pendingTasks])
                ->extraAttributes([
                    'class' => 'hover:scale-105 transition-transform duration-300',
                ]),
        ];
    }

    protected function getColumns(): int
    {
        return 4;
    }
}
