<?php

namespace App\Services\Reports;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportEmployeeService
{
    /**
     * Get full performance report for all employees or one employee.
     *
     * @param  array  $filters  Keys: employee_id, from_date, to_date
     */
    public function getEmployeePerformance(array $filters): Collection
    {
        $employeesQuery = DB::table('employees')
            ->join('users', 'employees.user_id', '=', 'users.id')
            ->select('employees.id', 'users.name', 'employees.salary');

        if (! empty($filters['employee_id'])) {
            $employeesQuery->where('employees.id', $filters['employee_id']);
        }

        $employees = $employeesQuery->get();

        $results = collect();

        foreach ($employees as $employee) {
            // Operation counts
            $flightCount = $this->getEmployeeOperationCount('flight_bookings', $employee->id, $filters);
            $busCount = $this->getEmployeeOperationCount('bus_bookings', $employee->id, $filters);
            $serviceCount = $this->getEmployeeOperationCount('service_orders', $employee->id, $filters);
            $onlineCount = $this->getEmployeeOperationCount('online_transactions', $employee->id, $filters);

            $totalOperations = $flightCount + $busCount + $serviceCount + $onlineCount;

            // Profit sums
            $flightProfit = $this->getEmployeeProfitSum('flight_bookings', $employee->id, $filters, 'status != "cancelled"');
            $busProfit = $this->getEmployeeProfitSum('bus_bookings', $employee->id, $filters, 'status = "paid"', 'profit');
            $serviceProfit = $this->getEmployeeProfitSum('service_orders', $employee->id, $filters, 'status != "cancelled"');
            $onlineProfit = $this->getEmployeeProfitSum('online_transactions', $employee->id, $filters, 'status = "completed"', 'fee');

            $totalProfitGenerated = $flightProfit + $busProfit + $serviceProfit + $onlineProfit;

            // Bonuses and deductions
            $bonusesQuery = DB::table('employee_bonuses')
                ->where('employee_id', $employee->id)
                ->selectRaw('
                    SUM(CASE WHEN type = "bonus" THEN amount ELSE 0 END) as bonuses,
                    SUM(CASE WHEN type = "deduction" THEN amount ELSE 0 END) as deductions
                ');

            if (! empty($filters['from_date'])) {
                $bonusesQuery->whereDate('created_at', '>=', $filters['from_date']);
            }

            if (! empty($filters['to_date'])) {
                $bonusesQuery->whereDate('created_at', '<=', $filters['to_date']);
            }

            $bonusesData = $bonusesQuery->first();
            $totalBonuses = (float) ($bonusesData->bonuses ?? 0);
            $totalDeductions = (float) ($bonusesData->deductions ?? 0);
            $netAdjustment = $totalBonuses - $totalDeductions;

            $results->push([
                'employee_id' => $employee->id,
                'employee_name' => $employee->name,
                'salary' => round((float) $employee->salary, 2),
                'flight_count' => $flightCount,
                'bus_count' => $busCount,
                'service_count' => $serviceCount,
                'online_count' => $onlineCount,
                'total_operations' => $totalOperations,
                'flight_profit' => round($flightProfit, 2),
                'bus_profit' => round($busProfit, 2),
                'service_profit' => round($serviceProfit, 2),
                'online_profit' => round($onlineProfit, 2),
                'total_profit_generated' => round($totalProfitGenerated, 2),
                'total_bonuses' => round($totalBonuses, 2),
                'total_deductions' => round($totalDeductions, 2),
                'net_adjustment' => round($netAdjustment, 2),
            ]);
        }

        return $results->sortByDesc('total_profit_generated')->values();
    }

    /**
     * Get bonus and deduction report per employee.
     *
     * @param  array  $filters  Keys: employee_id, type, from_date, to_date, per_page
     */
    public function getBonusReport(array $filters): LengthAwarePaginator
    {
        $query = DB::table('employee_bonuses')
            ->join('employees', 'employee_bonuses.employee_id', '=', 'employees.id')
            ->join('users as employee_user', 'employees.user_id', '=', 'employee_user.id')
            ->join('accounts', 'employee_bonuses.account_id', '=', 'accounts.id')
            ->join('users as creator_user', 'employee_bonuses.created_by', '=', 'creator_user.id')
            ->select(
                'employee_user.name as employee_name',
                'employee_bonuses.type',
                'employee_bonuses.amount',
                'employee_bonuses.reason',
                'accounts.name as account_name',
                'creator_user.name as creator_name',
                'employee_bonuses.created_at'
            );

        if (! empty($filters['employee_id'])) {
            $query->where('employee_bonuses.employee_id', $filters['employee_id']);
        }

        if (! empty($filters['type'])) {
            $query->where('employee_bonuses.type', $filters['type']);
        }

        if (! empty($filters['from_date'])) {
            $query->whereDate('employee_bonuses.created_at', '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $query->whereDate('employee_bonuses.created_at', '<=', $filters['to_date']);
        }

        $perPage = $filters['per_page'] ?? 20;

        return $query->orderBy('employee_bonuses.created_at', 'desc')->paginate($perPage);
    }

    /**
     * Helper method to get operation count for an employee.
     */
    private function getEmployeeOperationCount(string $table, int $employeeId, array $filters): int
    {
        $query = DB::table($table)->where('employee_id', $employeeId);

        if (! empty($filters['from_date'])) {
            $query->whereDate('created_at', '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $query->whereDate('created_at', '<=', $filters['to_date']);
        }

        return $query->count();
    }

    /**
     * Helper method to get profit sum for an employee.
     */
    private function getEmployeeProfitSum(string $table, int $employeeId, array $filters, string $whereCondition, string $column = 'profit'): float
    {
        $query = DB::table($table)
            ->where('employee_id', $employeeId)
            ->whereRaw($whereCondition)
            ->selectRaw("SUM({$column}) as total");

        if (! empty($filters['from_date'])) {
            $query->whereDate('created_at', '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $query->whereDate('created_at', '<=', $filters['to_date']);
        }

        $result = $query->first();

        return (float) ($result->total ?? 0);
    }
}
