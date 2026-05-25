<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\Reports\ReportCustomerService;
use App\Services\Reports\ReportEmployeeService;
use App\Services\Reports\ReportFinanceService;
use App\Services\Reports\ReportOperationsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(
        protected ReportFinanceService $financeService,
        protected ReportOperationsService $operationsService,
        protected ReportEmployeeService $employeeService,
        protected ReportCustomerService $customerService
    ) {}

    private function validateDateFilters(Request $request): array
    {
        $filters = [];

        if ($request->has('from_date') && $request->from_date) {
            if (! Carbon::hasFormat($request->from_date, 'Y-m-d')) {
                throw new \Exception('Invalid from_date format. Use Y-m-d.');
            }
            $filters['from_date'] = $request->from_date;
        }

        if ($request->has('to_date') && $request->to_date) {
            if (! Carbon::hasFormat($request->to_date, 'Y-m-d')) {
                throw new \Exception('Invalid to_date format. Use Y-m-d.');
            }
            $filters['to_date'] = $request->to_date;
        }

        return $filters;
    }

    // Finance Reports

    public function financialSummary(Request $request): JsonResponse
    {
        try {
            $filters = $this->validateDateFilters($request);
            $data = $this->financeService->getFinancialSummary($filters);

            return ApiResponse::success('Financial summary retrieved successfully.', $data);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function accountsBalance(): JsonResponse
    {
        try {
            $data = $this->financeService->getAccountsBalance();

            return ApiResponse::success('Accounts balance retrieved successfully.', $data);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function profitLoss(Request $request): JsonResponse
    {
        try {
            $filters = $this->validateDateFilters($request);
            $filters['category'] = $request->input('category'); // e.g. 'tourism', 'office', or null
            $filters['module'] = $request->input('module'); // e.g. 'flight', 'bus', or 'all'
            $data = $this->financeService->getProfitLossReport($filters);

            return ApiResponse::success('Profit & Loss retrieved successfully.', $data);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function incomeByModule(Request $request): JsonResponse
    {
        try {
            $filters = $this->validateDateFilters($request);
            $data = $this->financeService->getIncomeByModule($filters);

            return ApiResponse::success('Income by module retrieved successfully.', $data);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function expenseByModule(Request $request): JsonResponse
    {
        try {
            $filters = $this->validateDateFilters($request);
            $data = $this->financeService->getExpenseByModule($filters);

            return ApiResponse::success('Expense by module retrieved successfully.', $data);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function dailyFinancialChart(Request $request): JsonResponse
    {
        try {
            if (! $request->has('from_date') || ! $request->from_date) {
                return ApiResponse::error('from_date is required.', null, 422);
            }
            if (! $request->has('to_date') || ! $request->to_date) {
                return ApiResponse::error('to_date is required.', null, 422);
            }

            $filters = $this->validateDateFilters($request);
            $data = $this->financeService->getDailyFinancialChart($filters);

            return ApiResponse::success('Daily chart data retrieved successfully.', $data);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function transactionReport(Request $request): JsonResponse
    {
        try {
            $filters = $this->validateDateFilters($request);
            $filters = array_merge($filters, $request->only(['type', 'module', 'account_id', 'per_page', 'account_type']));
            $paginator = $this->financeService->getTransactionReport($filters);

            return ApiResponse::paginated('Transactions report retrieved successfully.', $paginator->items(), $paginator);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * Alias for SPA / reports UX (same payload as transactionReport).
     */
    public function transactions(Request $request): JsonResponse
    {
        return $this->transactionReport($request);
    }

    public function transactionDetail(int $id): JsonResponse
    {
        try {
            $data = $this->financeService->getTransactionDetail($id);

            return ApiResponse::success('Transaction details retrieved successfully.', $data);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 404);
        }
    }

    public function busDebtSummary(): JsonResponse
    {
        try {
            $data = $this->financeService->getBusDebtSummary();

            return ApiResponse::success('Bus debt summary retrieved successfully.', $data);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    // Operations Reports

    public function profitSummary(Request $request): JsonResponse
    {
        try {
            $filters = $this->validateDateFilters($request);
            $data = $this->operationsService->getProfitSummary($filters);

            return ApiResponse::success('Profit summary retrieved successfully.', $data);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function sales(Request $request): JsonResponse
    {
        try {
            $filters = $this->validateDateFilters($request);
            $data = $this->operationsService->getProfitSummary($filters);

            return ApiResponse::success('Sales overview retrieved successfully.', $data);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function flightReport(Request $request): JsonResponse
    {
        try {
            $filters = $this->validateDateFilters($request);
            $filters = array_merge($filters, $request->only(['status', 'airline_name', 'per_page']));
            $paginator = $this->operationsService->getFlightReport($filters);

            return ApiResponse::paginated('Flight report retrieved successfully.', $paginator->items(), $paginator);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function busReport(Request $request): JsonResponse
    {
        try {
            $filters = $this->validateDateFilters($request);
            $filters = array_merge($filters, $request->only(['status', 'company_id', 'per_page']));
            $paginator = $this->operationsService->getBusReport($filters);

            return ApiResponse::paginated('Bus report retrieved successfully.', $paginator->items(), $paginator);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function servicesReport(Request $request): JsonResponse
    {
        try {
            $filters = $this->validateDateFilters($request);
            $filters = array_merge($filters, $request->only(['status', 'category', 'per_page']));
            $paginator = $this->operationsService->getServicesReport($filters);

            return ApiResponse::paginated('Services report retrieved successfully.', $paginator->items(), $paginator);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function onlineReport(Request $request): JsonResponse
    {
        try {
            $filters = $this->validateDateFilters($request);
            $filters = array_merge($filters, $request->only(['status', 'type_id', 'per_page']));
            $paginator = $this->operationsService->getOnlineReport($filters);

            return ApiResponse::paginated('Online report retrieved successfully.', $paginator->items(), $paginator);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function monthlyProfitChart(Request $request): JsonResponse
    {
        try {
            if (! $request->has('year') || ! $request->year) {
                return ApiResponse::error('year is required.', null, 422);
            }

            $year = (int) $request->year;
            if ($year < 2000 || $year > 2100) {
                return ApiResponse::error('Valid year is required.', null, 422);
            }

            $data = $this->operationsService->getMonthlyProfitChart($year);

            return ApiResponse::success('Monthly profit chart retrieved successfully.', $data);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    // Employee Reports

    public function employeePerformance(Request $request): JsonResponse
    {
        try {
            $filters = $this->validateDateFilters($request);
            $filters = array_merge($filters, $request->only(['employee_id']));
            $data = $this->employeeService->getEmployeePerformance($filters);

            return ApiResponse::success('Employee performance retrieved successfully.', $data);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function bonusReport(Request $request): JsonResponse
    {
        try {
            $filters = $this->validateDateFilters($request);
            $filters = array_merge($filters, $request->only(['employee_id', 'type', 'per_page']));
            $paginator = $this->employeeService->getBonusReport($filters);

            return ApiResponse::paginated('Bonus report retrieved successfully.', $paginator->items(), $paginator);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    // Customer Reports

    public function customerBalances(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['type', 'balance_sign', 'search', 'per_page']);
            $paginator = $this->customerService->getCustomerBalances($filters);

            return ApiResponse::paginated('Customer balances retrieved successfully.', $paginator->items(), $paginator);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function customerActivity(Request $request, int $customerId): JsonResponse
    {
        try {
            $data = $this->customerService->getCustomerActivity($customerId);

            return ApiResponse::success('Customer activity retrieved successfully.', $data);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function topCustomers(Request $request): JsonResponse
    {
        try {
            $filters = $this->validateDateFilters($request);
            $filters = array_merge($filters, $request->only(['limit']));

            if (isset($filters['limit'])) {
                $limit = (int) $filters['limit'];
                if ($limit < 1 || $limit > 50) {
                    return ApiResponse::error('limit must be between 1 and 50.', null, 422);
                }
            }

            $data = $this->customerService->getTopCustomers($filters);

            return ApiResponse::success('Top customers retrieved successfully.', $data);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }
}
