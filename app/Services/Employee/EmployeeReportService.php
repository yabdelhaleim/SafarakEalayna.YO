<?php

namespace App\Services\Employee;

use App\Models\Employee;
use App\Models\Employee\EmployeeBonus;
use App\Models\EmployeeAttendance;
use Illuminate\Support\Facades\DB;

class EmployeeReportService
{
    public function getOverallReport(string $from, string $to): array
    {
        $totalEmployees = Employee::count();
        $activeEmployees = Employee::where('status', 'active')->count();

        // Bonuses summary
        $bonuses = EmployeeBonus::whereBetween('created_at', [$from, $to])->get();
        $totalBonuses = $bonuses->where('type', 'bonus')->sum('amount');
        $totalDeductions = abs($bonuses->where('type', 'deduction')->sum('amount'));
        $netBonuses = $bonuses->sum('amount');

        // Attendance summary
        $attendances = EmployeeAttendance::whereBetween('attendance_date', [$from, $to])->get();
        $presentDays = $attendances->where('status', 'present')->count();
        $absentDays = $attendances->where('status', 'absent')->count();
        $lateDays = $attendances->where('status', 'late')->count();

        // Top performers
        $topPerformers = Employee::with('user')
            ->where('performance_rating', 'excellent')
            ->limit(5)
            ->get();

        return [
            'summary' => [
                'total_employees' => $totalEmployees,
                'active_employees' => $activeEmployees,
                'inactive_employees' => $totalEmployees - $activeEmployees,
            ],
            'financials' => [
                'total_bonuses' => $totalBonuses,
                'total_deductions' => $totalDeductions,
                'net_amount' => $netBonuses,
                'bonus_transactions' => $bonuses->where('type', 'bonus')->count(),
                'deduction_transactions' => $bonuses->where('type', 'deduction')->count(),
            ],
            'attendance' => [
                'total_records' => $attendances->count(),
                'present_days' => $presentDays,
                'absent_days' => $absentDays,
                'late_days' => $lateDays,
                'attendance_rate' => $attendances->count() > 0
                    ? round(($presentDays / $attendances->count()) * 100, 2)
                    : 0,
            ],
            'top_performers' => $topPerformers->map(function ($emp) {
                return [
                    'id' => $emp->id,
                    'name' => $emp->user->name,
                    'position' => $emp->position,
                    'performance_rating' => $emp->performance_rating,
                ];
            }),
            'period' => [
                'from' => $from,
                'to' => $to,
            ],
        ];
    }

    public function getDetailedAttendanceReport(string $from, string $to): array
    {
        $employees = Employee::with(['user', 'attendances' => function ($query) use ($from, $to) {
            $query->whereBetween('attendance_date', [$from, $to]);
        }])->where('status', 'active')->get();

        $report = [];

        foreach ($employees as $employee) {
            $attendances = $employee->attendances;
            $present = $attendances->where('status', 'present')->count();
            $absent = $attendances->where('status', 'absent')->count();
            $late = $attendances->where('status', 'late')->count();

            $report[] = [
                'employee_id' => $employee->id,
                'name' => $employee->user->name,
                'position' => $employee->position,
                'department' => $employee->department,
                'total_days' => $attendances->count(),
                'present_days' => $present,
                'absent_days' => $absent,
                'late_days' => $late,
                'attendance_rate' => $attendances->count() > 0
                    ? round(($present / $attendances->count()) * 100, 2)
                    : 0,
            ];
        }

        // Sort by attendance rate (highest first)
        usort($report, function ($a, $b) {
            return $b['attendance_rate'] <=> $a['attendance_rate'];
        });

        return [
            'employees_attendance' => $report,
            'period' => [
                'from' => $from,
                'to' => $to,
            ],
        ];
    }

    public function getDetailedBonusesReport(string $from, string $to): array
    {
        $bonuses = EmployeeBonus::with(['employee.user', 'createdBy'])
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at', 'desc')
            ->get();

        $byEmployee = [];
        $byType = [
            'bonus' => ['count' => 0, 'total' => 0],
            'deduction' => ['count' => 0, 'total' => 0],
        ];

        foreach ($bonuses as $bonus) {
            // Group by employee
            $empId = $bonus->employee_id;
            if (!isset($byEmployee[$empId])) {
                $byEmployee[$empId] = [
                    'employee_id' => $empId,
                    'name' => $bonus->employee->user->name,
                    'bonuses_count' => 0,
                    'bonuses_total' => 0,
                    'deductions_count' => 0,
                    'deductions_total' => 0,
                    'net_total' => 0,
                ];
            }

            if ($bonus->type === 'bonus') {
                $byEmployee[$empId]['bonuses_count']++;
                $byEmployee[$empId]['bonuses_total'] += $bonus->amount;
                $byType['bonus']['count']++;
                $byType['bonus']['total'] += $bonus->amount;
            } else {
                $byEmployee[$empId]['deductions_count']++;
                $byEmployee[$empId]['deductions_total'] += abs($bonus->amount);
                $byType['deduction']['count']++;
                $byType['deduction']['total'] += abs($bonus->amount);
            }

            $byEmployee[$empId]['net_total'] = $byEmployee[$empId]['bonuses_total'] - $byEmployee[$empId]['deductions_total'];
        }

        // Convert to array and sort by net total
        $byEmployeeArray = array_values($byEmployee);
        usort($byEmployeeArray, function ($a, $b) {
            return $b['net_total'] <=> $a['net_total'];
        });

        return [
            'by_type' => $byType,
            'by_employee' => $byEmployeeArray,
            'all_transactions' => $bonuses->map(function ($bonus) {
                return [
                    'id' => $bonus->id,
                    'employee_name' => $bonus->employee->user->name,
                    'type' => $bonus->type,
                    'amount' => $bonus->amount,
                    'reason' => $bonus->reason,
                    'date' => $bonus->created_at->format('Y-m-d'),
                    'created_by' => $bonus->createdBy->name,
                ];
            }),
            'period' => [
                'from' => $from,
                'to' => $to,
            ],
        ];
    }

    public function getEmployeePerformanceReport(int $employeeId, string $from, string $to): array
    {
        $employee = Employee::with(['user', 'bonuses' => function ($query) use ($from, $to) {
            $query->whereBetween('created_at', [$from, $to]);
        }, 'attendances' => function ($query) use ($from, $to) {
            $query->whereBetween('attendance_date', [$from, $to]);
        }])->findOrFail($employeeId);

        $bonuses = $employee->bonuses;
        $attendances = $employee->attendances;

        return [
            'employee_info' => [
                'id' => $employee->id,
                'name' => $employee->user->name,
                'position' => $employee->position,
                'department' => $employee->department,
                'performance_rating' => $employee->performance_rating,
                'salary' => $employee->salary,
            ],
            'performance_metrics' => [
                'attendance_rate' => $attendances->count() > 0
                    ? round(($attendances->where('status', 'present')->count() / $attendances->count()) * 100, 2)
                    : 0,
                'punctuality_rate' => $attendances->count() > 0
                    ? round((($attendances->count() - $attendances->where('status', 'late')->count()) / $attendances->count()) * 100, 2)
                    : 0,
                'total_bonuses' => $bonuses->where('type', 'bonus')->sum('amount'),
                'total_deductions' => abs($bonuses->where('type', 'deduction')->sum('amount')),
                'net_bonuses' => $bonuses->sum('amount'),
            ],
            'attendance_breakdown' => [
                'total_days' => $attendances->count(),
                'present' => $attendances->where('status', 'present')->count(),
                'absent' => $attendances->where('status', 'absent')->count(),
                'late' => $attendances->where('status', 'late')->count(),
            ],
            'bonuses_breakdown' => [
                'bonus_count' => $bonuses->where('type', 'bonus')->count(),
                'bonus_total' => $bonuses->where('type', 'bonus')->sum('amount'),
                'deduction_count' => $bonuses->where('type', 'deduction')->count(),
                'deduction_total' => abs($bonuses->where('type', 'deduction')->sum('amount')),
            ],
            'period' => [
                'from' => $from,
                'to' => $to,
            ],
        ];
    }
}
