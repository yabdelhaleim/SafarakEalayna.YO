<?php

namespace App\Services\Employee;

use App\Models\EmployeeAttendance;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EmployeeAttendanceService
{
    public function recordAttendance(array $data): EmployeeAttendance
    {
        return DB::transaction(function () use ($data) {
            $employee = Employee::findOrFail($data['employee_id']);

            $attendance = EmployeeAttendance::updateOrCreate(
                [
                    'employee_id' => $data['employee_id'],
                    'attendance_date' => $data['attendance_date'],
                ],
                [
                    'status' => $data['status'],
                    'check_in' => $data['check_in'] ?? null,
                    'check_out' => $data['check_out'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => auth()->id(),
                ]
            );

            Log::info('Attendance recorded', [
                'attendance_id' => $attendance->id,
                'employee_id' => $employee->id,
                'date' => $data['attendance_date'],
                'status' => $data['status'],
            ]);

            return $attendance->fresh();
        });
    }

    public function updateAttendance(EmployeeAttendance $attendance, array $data): EmployeeAttendance
    {
        return DB::transaction(function () use ($attendance, $data) {
            $attendance->update([
                'status' => $data['status'] ?? $attendance->status,
                'check_in' => $data['check_in'] ?? $attendance->check_in,
                'check_out' => $data['check_out'] ?? $attendance->check_out,
                'notes' => $data['notes'] ?? $attendance->notes,
            ]);

            Log::info('Attendance updated', [
                'attendance_id' => $attendance->id,
                'employee_id' => $attendance->employee_id,
                'changes' => $data,
            ]);

            return $attendance->fresh();
        });
    }

    public function deleteAttendance(EmployeeAttendance $attendance): bool
    {
        return DB::transaction(function () use ($attendance) {
            $attendance->delete();

            Log::info('Attendance deleted', [
                'attendance_id' => $attendance->id,
                'employee_id' => $attendance->employee_id,
            ]);

            return true;
        });
    }

    public function getEmployeeAttendance(int $employeeId, ?string $from = null, ?string $to = null)
    {
        $query = EmployeeAttendance::byEmployee($employeeId);

        if ($from && $to) {
            $query->byDateRange($from, $to);
        }

        return $query->orderBy('attendance_date', 'desc')->get();
    }

    public function getAttendanceSummary(int $employeeId, string $from, string $to): array
    {
        $attendances = EmployeeAttendance::byEmployee($employeeId)
            ->byDateRange($from, $to)
            ->get();

        return [
            'total_days' => $attendances->count(),
            'present_days' => $attendances->where('status', 'present')->count(),
            'absent_days' => $attendances->where('status', 'absent')->count(),
            'late_days' => $attendances->where('status', 'late')->count(),
            'attendance_records' => $attendances,
        ];
    }

    public function getDailyAttendance(string $date): \Illuminate\Database\Eloquent\Collection
    {
        return EmployeeAttendance::whereDate('attendance_date', $date)
            ->with('employee')
            ->orderBy('attendance_date', 'desc')
            ->get();
    }
}
