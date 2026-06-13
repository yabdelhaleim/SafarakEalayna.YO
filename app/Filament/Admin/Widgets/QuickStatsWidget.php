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
                ->chart($this->dailyTableCounts('customers'))
                ->extraAttributes([
                    'class' => 'hover:scale-105 transition-transform duration-300',
                ]),

            Stat::make('الموظفين النشطين', number_format($employeesCount))
                ->description('من أصل '.(Schema::hasTable('employees') ? Employee::count() : 0).' موظف')
                ->descriptionIcon('heroicon-o-briefcase')
                ->color('success')
                ->chart($this->dailyEmployeeHires())
                ->extraAttributes([
                    'class' => 'hover:scale-105 transition-transform duration-300',
                ]),

            Stat::make('الفواتير', number_format($invoicesCount))
                ->description('إجمالي الفواتير')
                ->descriptionIcon('heroicon-o-document-text')
                ->color('warning')
                ->chart($this->dailyTableCounts('invoices'))
                ->extraAttributes([
                    'class' => 'hover:scale-105 transition-transform duration-300',
                ]),

            Stat::make('المهام المعلقة', number_format($pendingTasks))
                ->description('تحتاج إجراء')
                ->descriptionIcon('heroicon-o-clipboard-document-list')
                ->color($pendingTasks > 10 ? 'danger' : 'info')
                ->chart($this->dailyPendingTasks())
                ->extraAttributes([
                    'class' => 'hover:scale-105 transition-transform duration-300',
                ]),
        ];
    }

    /**
     * @return array<int, int>
     */
    private function dailyTableCounts(string $table, int $days = 7): array
    {
        if (! Schema::hasTable($table)) {
            return array_fill(0, $days, 0);
        }

        return collect(range($days - 1, 0))
            ->map(fn (int $daysAgo): int => (int) DB::table($table)
                ->whereDate('created_at', now()->subDays($daysAgo))
                ->count())
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function dailyEmployeeHires(int $days = 7): array
    {
        if (! Schema::hasTable('employees')) {
            return array_fill(0, $days, 0);
        }

        return collect(range($days - 1, 0))
            ->map(fn (int $daysAgo): int => Employee::query()
                ->whereDate('hire_date', now()->subDays($daysAgo))
                ->count())
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function dailyPendingTasks(int $days = 7): array
    {
        if (! Schema::hasTable('tasks')) {
            return array_fill(0, $days, 0);
        }

        return collect(range($days - 1, 0))
            ->map(fn (int $daysAgo): int => (int) DB::table('tasks')
                ->where('status', 'pending')
                ->whereDate('created_at', '<=', now()->subDays($daysAgo))
                ->count())
            ->all();
    }

    protected function getColumns(): int
    {
        return 4;
    }
}
