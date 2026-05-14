<?php

namespace App\Services\Employee;

use App\Enums\BonusType;
use App\Enums\TransactionModule;
use App\Models\Employee;
use App\Models\Employee\EmployeeBonus;
use App\Services\Finance\TransactionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EmployeeBonusService
{
    protected TransactionService $transactionService;

    public function __construct(TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    /**
     * Get paginated bonus/deduction records with filters.
     *
     * @param  array  $filters  Keys: employee_id, type, from_date, to_date, per_page
     */
    public function getAllBonuses(array $filters): LengthAwarePaginator
    {
        $query = EmployeeBonus::with([
            'employee.user',
            'account',
            'createdBy',
        ]);

        if (isset($filters['employee_id']) && $filters['employee_id']) {
            $query->where('employee_id', $filters['employee_id']);
        }

        if (isset($filters['type']) && $filters['type']) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['from_date']) && $filters['from_date']) {
            $query->where('created_at', '>=', $filters['from_date'].' 00:00:00');
        }

        if (isset($filters['to_date']) && $filters['to_date']) {
            $query->where('created_at', '<=', $filters['to_date'].' 23:59:59');
        }

        $perPage = min($filters['per_page'] ?? 20, 100);

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Add a bonus for an employee.
     * Financial effect: expense from office account (office pays the employee).
     *
     * @param  array  $data  Keys: employee_id, amount, reason, account_id
     *
     * @throws \Exception
     */
    public function addBonus(array $data): EmployeeBonus
    {
        $employee = Employee::with('user')->findOrFail($data['employee_id']);

        if ($employee->status !== 'active') {
            throw new \Exception('Cannot add bonus to an inactive employee.');
        }

        try {
            return DB::transaction(function () use ($employee, $data) {
                $transaction = $this->transactionService->recordExpense([
                    'amount' => $data['amount'],
                    'from_account_id' => $data['account_id'],
                    'module' => TransactionModule::General->value,
                    'related_type' => EmployeeBonus::class,
                    'related_id' => null,
                    'notes' => 'Bonus for employee: '.$employee->user->name.' | Reason: '.$data['reason'],
                ]);

                $bonus = EmployeeBonus::create([
                    'employee_id' => $employee->id,
                    'type' => BonusType::Bonus,
                    'amount' => $data['amount'],
                    'reason' => $data['reason'],
                    'account_id' => $data['account_id'],
                    'transaction_id' => $transaction->id,
                    'created_by' => Auth::id(),
                ]);

                // Update transaction's related_id
                $transaction->update(['related_id' => $bonus->id]);

                Log::info('Employee bonus added', [
                    'bonus_id' => $bonus->id,
                    'employee_id' => $employee->id,
                    'amount' => $data['amount'],
                    'created_by' => Auth::id(),
                ]);

                return $bonus->load([
                    'employee.user',
                    'account',
                    'transaction',
                    'createdBy',
                ]);
            });
        } catch (\Exception $e) {
            Log::error('EmployeeBonusService::addBonus failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'employee_id' => $data['employee_id'] ?? null,
                'input' => $data,
            ]);
            throw $e;
        }
    }

    /**
     * Add a deduction for an employee.
     * Financial effect: income to office account (office deducts from employee).
     *
     * @param  array  $data  Keys: employee_id, amount, reason, account_id
     *
     * @throws \Exception
     */
    public function addDeduction(array $data): EmployeeBonus
    {
        $employee = Employee::with('user')->findOrFail($data['employee_id']);

        if ($employee->status !== 'active') {
            throw new \Exception('Cannot add deduction to an inactive employee.');
        }

        try {
            return DB::transaction(function () use ($employee, $data) {
                $transaction = $this->transactionService->recordIncome([
                    'amount' => $data['amount'],
                    'to_account_id' => $data['account_id'],
                    'module' => TransactionModule::General->value,
                    'related_type' => EmployeeBonus::class,
                    'related_id' => null,
                    'notes' => 'Deduction from employee: '.$employee->user->name.' | Reason: '.$data['reason'],
                ]);

                $deduction = EmployeeBonus::create([
                    'employee_id' => $employee->id,
                    'type' => BonusType::Deduction,
                    'amount' => $data['amount'],
                    'reason' => $data['reason'],
                    'account_id' => $data['account_id'],
                    'transaction_id' => $transaction->id,
                    'created_by' => Auth::id(),
                ]);

                // Update transaction's related_id
                $transaction->update(['related_id' => $deduction->id]);

                Log::info('Employee deduction added', [
                    'deduction_id' => $deduction->id,
                    'employee_id' => $employee->id,
                    'amount' => $data['amount'],
                    'created_by' => Auth::id(),
                ]);

                return $deduction->load([
                    'employee.user',
                    'account',
                    'transaction',
                    'createdBy',
                ]);
            });
        } catch (\Exception $e) {
            Log::error('EmployeeBonusService::addDeduction failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'employee_id' => $data['employee_id'] ?? null,
                'input' => $data,
            ]);
            throw $e;
        }
    }

    /**
     * Add a salary draw (Advance) for an employee.
     * Financial effect: expense from office account (office pays cash), but deduction on their salary.
     */
    public function addDraw(array $data): EmployeeBonus
    {
        $employee = Employee::with('user')->findOrFail($data['employee_id']);

        if ($employee->status !== 'active') {
            throw new \Exception('Cannot add draw to an inactive employee.');
        }

        try {
            return DB::transaction(function () use ($employee, $data) {
                $transaction = $this->transactionService->recordExpense([
                    'amount' => $data['amount'],
                    'from_account_id' => $data['account_id'],
                    'module' => TransactionModule::General->value,
                    'related_type' => EmployeeBonus::class,
                    'related_id' => null,
                    'notes' => 'Salary Draw (Advance) for employee: '.$employee->user->name.' | Reason: '.$data['reason'],
                ]);

                $draw = EmployeeBonus::create([
                    'employee_id' => $employee->id,
                    'type' => BonusType::Deduction,
                    'amount' => $data['amount'],
                    'reason' => 'سلفة/سحب: ' . $data['reason'],
                    'account_id' => $data['account_id'],
                    'transaction_id' => $transaction->id,
                    'created_by' => Auth::id(),
                ]);

                // Update transaction's related_id
                $transaction->update(['related_id' => $draw->id]);

                return $draw->load(['employee.user', 'account', 'transaction', 'createdBy']);
            });
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Get a single bonus/deduction record by ID.
     */
    public function getBonusById(int $id): EmployeeBonus
    {
        return EmployeeBonus::with([
            'employee.user',
            'account',
            'transaction',
            'createdBy',
        ])->findOrFail($id);
    }

    /**
     * Get a financial summary for a specific employee.
     * Shows totals per type and net balance.
     *
     * @param  array  $filters  Keys: from_date, to_date
     */
    public function getEmployeeSummary(int $employeeId, array $filters): array
    {
        $employee = Employee::with('user')->findOrFail($employeeId);

        $query = EmployeeBonus::where('employee_id', $employeeId);

        if (isset($filters['from_date']) && $filters['from_date']) {
            $query->where('created_at', '>=', $filters['from_date'].' 00:00:00');
        }

        if (isset($filters['to_date']) && $filters['to_date']) {
            $query->where('created_at', '<=', $filters['to_date'].' 23:59:59');
        }

        $records = $query->get();

        $totalBonuses = $records->where('type', BonusType::Bonus->value)->sum('amount');
        $totalDeductions = $records->where('type', BonusType::Deduction->value)->sum('amount');
        $netAdjustment = $totalBonuses - $totalDeductions;

        return [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->user->name ?? 'Unknown',
                'salary' => (float) $employee->salary,
                'status' => $employee->status,
            ],
            'total_bonuses' => (float) $totalBonuses,
            'total_deductions' => (float) $totalDeductions,
            'net_adjustment' => (float) $netAdjustment,
            'records_count' => $records->count(),
        ];
    }

    /**
     * Get per-employee activity count across all operation modules.
     * Shows how many operations each employee performed.
     * Used for performance reporting.
     *
     * @param  array  $filters  Keys: from_date, to_date, employee_id
     * @return Collection
     */
    public function getEmployeeActivitySummary(array $filters)
    {
        $fromDate = $filters['from_date'] ?? null;
        $toDate = $filters['to_date'] ?? null;
        $employeeId = $filters['employee_id'] ?? null;

        // Flight bookings
        $flightQuery = DB::table('flight_bookings');
        if ($fromDate) {
            $flightQuery->where('created_at', '>=', $fromDate.' 00:00:00');
        }
        if ($toDate) {
            $flightQuery->where('created_at', '<=', $toDate.' 23:59:59');
        }
        if ($employeeId) {
            $flightQuery->where('employee_id', $employeeId);
        }
        $flights = $flightQuery->select('employee_id', DB::raw('COUNT(*) as count'))
            ->groupBy('employee_id')
            ->pluck('count', 'employee_id')
            ->toArray();

        // Bus bookings
        $busQuery = DB::table('bus_bookings');
        if ($fromDate) {
            $busQuery->where('created_at', '>=', $fromDate.' 00:00:00');
        }
        if ($toDate) {
            $busQuery->where('created_at', '<=', $toDate.' 23:59:59');
        }
        if ($employeeId) {
            $busQuery->where('employee_id', $employeeId);
        }
        $buses = $busQuery->select('employee_id', DB::raw('COUNT(*) as count'))
            ->groupBy('employee_id')
            ->pluck('count', 'employee_id')
            ->toArray();

        // Service orders
        $serviceQuery = DB::table('service_orders');
        if ($fromDate) {
            $serviceQuery->where('created_at', '>=', $fromDate.' 00:00:00');
        }
        if ($toDate) {
            $serviceQuery->where('created_at', '<=', $toDate.' 23:59:59');
        }
        if ($employeeId) {
            $serviceQuery->where('employee_id', $employeeId);
        }
        $services = $serviceQuery->select('employee_id', DB::raw('COUNT(*) as count'))
            ->groupBy('employee_id')
            ->pluck('count', 'employee_id')
            ->toArray();

        // Online transactions
        $onlineQuery = DB::table('online_transactions');
        if ($fromDate) {
            $onlineQuery->where('created_at', '>=', $fromDate.' 00:00:00');
        }
        if ($toDate) {
            $onlineQuery->where('created_at', '<=', $toDate.' 23:59:59');
        }
        if ($employeeId) {
            $onlineQuery->where('employee_id', $employeeId);
        }
        $onlines = $onlineQuery->select('employee_id', DB::raw('COUNT(*) as count'))
            ->groupBy('employee_id')
            ->pluck('count', 'employee_id')
            ->toArray();

        // Merge all employee IDs
        $allEmployeeIds = array_unique(
            array_merge(
                array_keys($flights),
                array_keys($buses),
                array_keys($services),
                array_keys($onlines)
            )
        );

        // If filtering by specific employee, ensure they're included even with zero counts
        if ($employeeId && ! in_array($employeeId, $allEmployeeIds)) {
            $allEmployeeIds[] = $employeeId;
        }

        // Build result collection
        $results = collect();
        foreach ($allEmployeeIds as $empId) {
            $employee = Employee::with('user')->find($empId);
            if (! $employee) {
                continue;
            }

            $flightCount = $flights[$empId] ?? 0;
            $busCount = $buses[$empId] ?? 0;
            $serviceCount = $services[$empId] ?? 0;
            $onlineCount = $onlines[$empId] ?? 0;
            $totalCount = $flightCount + $busCount + $serviceCount + $onlineCount;

            $results->push([
                'employee_id' => $empId,
                'employee_name' => $employee->user->name ?? 'Unknown',
                'flight_count' => $flightCount,
                'bus_count' => $busCount,
                'service_count' => $serviceCount,
                'online_count' => $onlineCount,
                'total_count' => $totalCount,
            ]);
        }

        return $results->sortByDesc('total_count')->values();
    }
}
