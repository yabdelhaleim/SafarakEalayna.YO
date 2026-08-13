<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\Reports\FinanceOperationsReportService;
use App\Services\Reports\ReportCustomerService;
use App\Services\Reports\ReportEmployeeService;
use App\Services\Reports\ReportFinanceService;
use App\Services\Reports\ReportOperationsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            if ($request->filled('module')) {
                $filters['module'] = $request->input('module');
            }
            if ($request->filled('category')) {
                $filters['category'] = $request->input('category');
            }
            if ($request->filled('expense_scope')) {
                $filters['expense_scope'] = $request->input('expense_scope');
            }
            $data = $this->financeService->getFinancialSummary($filters);

            return ApiResponse::success('Financial summary retrieved successfully.', $data)
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function accountsBalance(): JsonResponse
    {
        try {
            $data = $this->financeService->getAccountsBalance();

            return ApiResponse::success('Accounts balance retrieved successfully.', $data)
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
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
            $filters['section'] = $request->input('section', 'all');

            if (! in_array($filters['section'], ['all', 'revenue', 'cogs', 'refund', 'expense'], true)) {
                return ApiResponse::error('قيمة قسم التقرير غير صالحة.', null, 422);
            }

            if (! empty($filters['from_date']) && ! empty($filters['to_date'])) {
                $from = Carbon::parse($filters['from_date']);
                $to = Carbon::parse($filters['to_date']);
                if ($to->lt($from)) {
                    return ApiResponse::error('to_date must be on or after from_date.', null, 422);
                }
                if ($from->diffInDays($to) > 730) {
                    return ApiResponse::error('الفترة الزمنية طويلة جداً. الحد الأقصى سنتان (730 يوماً).', null, 422);
                }
            }

            $data = $this->financeService->getProfitLossReport($filters);

            return ApiResponse::success('Profit & Loss retrieved successfully.', $data)
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
                ->header('Pragma', 'no-cache');
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * Loss-drill-down — list every office transaction that contributed to a
     * negative net profit, ranked by absolute impact on P&L.
     *
     * Why this endpoint exists (vs `finance/operations`):
     *   `finance/operations` only returns transfer-shape rows (recharges,
     *   module transfers, prepaid movements). It silently DROPS pure
     *   income/expense/refund/writeoff rows, which is exactly what the
     *   loss-drill-down on /finance/profit-loss needs to render.
     *   This endpoint queries the raw transactions table directly, filters
     *   to the office-division modules, applies the same signed-amount
     *   convention as the P&L engine, and returns 25–200 rows ranked by
     *   `abs(signed_amount) desc`.
     *
     * Filters:
     *   - from_date, to_date (Y-m-d, required)
     *   - category ('office' | 'tourism' | default 'office')
     *   - module (single module filter, optional)
     *   - limit (1..200, default 25)
     *   - request_type (optional — 'expense', 'income', 'refund', 'writeoff',
     *     'negative' to limit the rows to those that hurt P&L)
     *
     * Each row:
     *   {
     *     id, date, type, module, amount, signed_impact, currency,
     *     notes, related_type, related_id,
     *     from_account{id,name,type}, to_account{id,name,type},
     *     created_by_name
     *   }
     *
     * Sorted by abs(signed_impact) DESC so the worst offenders float to
     * the top.
     */
    public function lossDrillDown(Request $request): JsonResponse
    {
        try {
            $filters = $this->validateDateFilters($request);
            if (empty($filters['from_date']) || empty($filters['to_date'])) {
                return ApiResponse::error('from_date and to_date are required.', null, 422);
            }
            $category = $request->input('category', 'office');
            $module = $request->input('module');
            $limit = max(1, min((int) $request->input('limit', 25), 200));
            $requestType = $request->input('request_type');

            // Modules per division — mirror the canonical list in
            // ProfitLossReportService so the same set of transactions feeds
            // both the headline P&L and the drill-down.
            $officeModules = ['bus', 'fawry', 'online', 'wallet', 'wallet_transfer', 'wallets', 'general', 'service', 'office'];
            $tourismModules = ['flight', 'hajj_umra', 'visa', 'tourism'];
            $modules = $category === 'tourism' ? $tourismModules : $officeModules;
            if ($module && $module !== 'all') {
                $modules = [$module];
            }

            $query = DB::table('transactions as t')
                ->leftJoin('accounts as fa', 'fa.id', '=', 't.from_account_id')
                ->leftJoin('accounts as ta', 'ta.id', '=', 't.to_account_id')
                ->leftJoin('users as u', 'u.id', '=', 't.created_by')
                ->whereIn('t.module', $modules)
                ->whereBetween('t.created_at', [
                    $filters['from_date'].' 00:00:00',
                    $filters['to_date'].' 23:59:59',
                ])
                ->select([
                    't.id', 't.created_at', 't.type', 't.module', 't.amount',
                    't.currency', 't.notes', 't.related_type', 't.related_id',
                    't.from_account_id', 't.to_account_id',
                    'fa.name as from_account_name', 'fa.type as from_account_type',
                    'ta.name as to_account_name', 'ta.type as to_account_type',
                    'u.name as created_by_name',
                ]);

            // Filter to a specific transaction type if requested.
            // 'expense' shows only expenses, 'negative' shows only rows
            // with negative impact (the actual loss drivers).
            if ($requestType === 'expense') {
                $query->where('t.type', 'expense');
            } elseif ($requestType === 'income') {
                $query->where('t.type', 'income');
            } elseif ($requestType === 'refund') {
                $query->where('t.type', 'refund');
            } elseif ($requestType === 'writeoff') {
                $query->where('t.type', 'writeoff');
            } elseif ($requestType === 'negative') {
                $query->whereIn('t.type', ['expense', 'refund', 'writeoff']);
            }

            // Pre-sort: pull enough rows so we can rank by abs(amount) desc
            // in the post-process step. Limit is applied AFTER signing.
            $rows = $query->orderBy('t.amount', 'desc')->limit($limit * 4)->get();

            $signed = $rows->map(function ($r) {
                $type = strtolower((string) $r->type);
                $amount = (float) $r->amount;
                $signed_impact = match ($type) {
                    'income' => +$amount,
                    'expense', 'refund', 'writeoff' => -$amount,
                    default => 0.0,
                };
                return [
                    'id' => (int) $r->id,
                    'date' => $r->created_at,
                    'type' => $r->type,
                    'module' => $r->module,
                    'amount' => round($amount, 2),
                    'signed_impact' => round($signed_impact, 2),
                    'currency' => $r->currency,
                    'notes' => $r->notes,
                    'related_type' => $r->related_type,
                    'related_id' => (int) $r->related_id,
                    'from_account' => [
                        'id' => (int) $r->from_account_id,
                        'name' => $r->from_account_name,
                        'type' => $r->from_account_type,
                    ],
                    'to_account' => [
                        'id' => (int) $r->to_account_id,
                        'name' => $r->to_account_name,
                        'type' => $r->to_account_type,
                    ],
                    'created_by_name' => $r->created_by_name,
                ];
            })
            // Drop zero-impact (transfers)
            ->filter(fn ($r) => $r['signed_impact'] !== 0.0)
            // Sort by absolute impact DESC so the worst loss drivers float up
            ->sortByDesc(fn ($r) => abs($r['signed_impact']))
            // Take the requested number
            ->take($limit)
            ->values();

            return ApiResponse::success('Loss drill-down retrieved.', [
                'period' => [
                    'from' => $filters['from_date'],
                    'to' => $filters['to_date'],
                ],
                'category' => $category,
                'module' => $module,
                'request_type' => $requestType,
                'total_scanned' => $rows->count(),
                'returned' => $signed->count(),
                'limit' => $limit,
                'rows' => $signed->all(),
            ])
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function financeOperations(Request $request): JsonResponse
    {
        try {
            $filters = $this->validateDateFilters($request);
            if ($request->filled('operation_type')) {
                $filters['operation_type'] = $request->input('operation_type');
            }
            if ($request->filled('module')) {
                $filters['module'] = $request->input('module');
            }
            if ($request->filled('search')) {
                $filters['search'] = $request->input('search');
            }
            $filters['per_page'] = $request->input('per_page', 25);
            $filters['page'] = $request->input('page', 1);

            $data = app(FinanceOperationsReportService::class)->report($filters);

            return ApiResponse::success('Finance operations ledger retrieved successfully.', $data)
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
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
            $filters = array_merge($filters, $request->only([
                'type',
                'module',
                'account_id',
                'per_page',
                'page',
                'account_type',
                'search',
                'expenses_only',
            ]));
            $paginator = $this->financeService->getTransactionReport($filters);

            return ApiResponse::paginated('Transactions report retrieved successfully.', $paginator->items(), $paginator)
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
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
