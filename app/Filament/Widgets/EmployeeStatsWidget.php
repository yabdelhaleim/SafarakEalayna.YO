<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Models\Employee\EmployeeAttendance;
use App\Models\Employee\EmployeeBonus;

class EmployeeStatsWidget extends BaseWidget
{
    protected static ?string $pollingInterval = '15s';

    public static function canView(): bool
    {
        return auth()->user() && in_array(auth()->user()->role, ['admin', 'owner'], true);
    }

    protected function getStats(): array
    {
        $thisMonth = now()->month;
        $thisYear = now()->year;

        // إحصائيات الحضور هذا الشهر
        $presentDays = EmployeeAttendance::whereMonth('attendance_date', $thisMonth)
            ->whereYear('attendance_date', $thisYear)
            ->where('status', 'present')
            ->count();

        $absentDays = EmployeeAttendance::whereMonth('attendance_date', $thisMonth)
            ->whereYear('attendance_date', $thisYear)
            ->where('status', 'absent')
            ->count();

        $lateDays = EmployeeAttendance::whereMonth('attendance_date', $thisMonth)
            ->whereYear('attendance_date', $thisYear)
            ->where('status', 'late')
            ->count();

        // إحصائيات المكافآت هذا الشهر
        $totalBonuses = EmployeeBonus::whereMonth('created_at', $thisMonth)
            ->whereYear('created_at', $thisYear)
            ->where('type', 'bonus')
            ->sum('amount') ?? 0;

        $totalDeductions = EmployeeBonus::whereMonth('created_at', $thisMonth)
            ->whereYear('created_at', $thisYear)
            ->where('type', 'deduction')
            ->sum('amount') ?? 0;

        $netBonuses = $totalBonuses - $totalDeductions;
        $totalDays = $presentDays + $absentDays + $lateDays;
        $attendanceRate = $totalDays > 0 ? round(($presentDays / $totalDays) * 100, 1) : 0;

        return [
            Stat::make('أيام الحضور', number_format($presentDays))
                ->description('نسبة الحضور: ' . $attendanceRate . '%')
                ->descriptionIcon('heroicon-o-calendar-days')
                ->color('success')
                ->chart([5, 8, 7, 9, 8, 10, 9, 11, 10, 12, 11, 13])
                ->extraAttributes([
                    'class' => 'hover:scale-105 transition-transform duration-300'
                ]),

            Stat::make('أيام الغياب', number_format($absentDays))
                ->description('هذا الشهر')
                ->descriptionIcon('heroicon-o-x-circle')
                ->color($absentDays > 5 ? 'danger' : 'warning')
                ->extraAttributes([
                    'class' => 'hover:scale-105 transition-transform duration-300'
                ]),

            Stat::make('أيام التأخير', number_format($lateDays))
                ->description('هذا الشهر')
                ->descriptionIcon('heroicon-o-clock')
                ->color($lateDays > 3 ? 'warning' : 'info')
                ->extraAttributes([
                    'class' => 'hover:scale-105 transition-transform duration-300'
                ]),

            Stat::make('صافي المكافآت', number_format($netBonuses, 2) . ' ج.م')
                ->description('مكافآت: ' . number_format($totalBonuses, 2) . ' | خصومات: ' . number_format($totalDeductions, 2))
                ->descriptionIcon('heroicon-o-banknotes')
                ->color($netBonuses >= 0 ? 'success' : 'danger')
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
