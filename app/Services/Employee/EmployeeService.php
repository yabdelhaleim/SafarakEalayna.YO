<?php

namespace App\Services\Employee;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EmployeeService
{
    public function getAllEmployees(array $filters = null)
    {
        $query = Employee::query()->with('user');

        if ($filters) {
            if (isset($filters['search'])) {
                $query->whereHas('user', function ($q) use ($filters) {
                    $q->where('name', 'like', "%{$filters['search']}%")
                      ->orWhere('email', 'like', "%{$filters['search']}%");
                });
            }

            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (isset($filters['employment_type'])) {
                $query->where('employment_type', $filters['employment_type']);
            }
        }

        return $query->orderBy('id', 'desc');
    }

    public function getEmployeeById(int $id): Employee
    {
        return Employee::with(['user', 'bonuses'])->findOrFail($id);
    }

    public function createEmployee(array $data): Employee
    {
        return DB::transaction(function () use ($data) {
            $employee = Employee::create($data);

            Log::info('Employee created', [
                'employee_id' => $employee->id,
                'user_id' => $employee->user_id,
            ]);

            return $employee->fresh();
        });
    }

    public function updateEmployee(Employee $employee, array $data): Employee
    {
        return DB::transaction(function () use ($employee, $data) {
            $employee->update($data);

            Log::info('Employee updated', [
                'employee_id' => $employee->id,
                'changes' => $data,
            ]);

            return $employee->fresh();
        });
    }

    public function deleteEmployee(Employee $employee): bool
    {
        return DB::transaction(function () use ($employee) {
            $employee->delete();

            Log::info('Employee deleted', [
                'employee_id' => $employee->id,
            ]);

            return true;
        });
    }

    public function getEmployeeStats(int $employeeId, string $from, string $to): array
    {
        $employee = $this->getEmployeeById($employeeId);

        $bonuses = $employee->bonuses()
            ->whereBetween('date', [$from, $to])
            ->get();

        $attendances = $employee->attendances()
            ->whereBetween('attendance_date', [$from, $to])
            ->get();

        return [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->user->name,
                'position' => $employee->position,
                'salary' => $employee->salary,
            ],
            'bonuses_summary' => [
                'total_bonuses' => $bonuses->where('type', 'bonus')->sum('amount'),
                'total_deductions' => abs($bonuses->where('type', 'deduction')->sum('amount')),
                'net_bonuses' => $bonuses->sum('amount'),
                'count' => $bonuses->count(),
            ],
            'attendance_summary' => [
                'total_days' => $attendances->count(),
                'present_days' => $attendances->where('status', 'present')->count(),
                'absent_days' => $attendances->where('status', 'absent')->count(),
                'late_days' => $attendances->where('status', 'late')->count(),
            ],
            'period' => [
                'from' => $from,
                'to' => $to,
            ],
        ];
    }
}
