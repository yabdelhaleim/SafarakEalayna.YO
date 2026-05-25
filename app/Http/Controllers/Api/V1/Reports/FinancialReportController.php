<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\LedgerReconciliationRun;
use App\Services\Reports\FinancialReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialReportController extends Controller
{
    public function __construct(
        protected FinancialReportService $reportService
    ) {}

    /**
     * تقرير كشف خزينة
     */
    public function treasuryReport(Request $request): JsonResponse
    {
        try {
            $treasuryId = $request->input('treasury_id');
            $treasury = \App\Models\Account::findOrFail($treasuryId);

            $report = $this->reportService->getTreasuryReport($treasury, $request->all());

            return ApiResponse::success('Treasury report generated successfully.', $report);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * تقرير الأرباح
     */
    public function profitReport(Request $request): JsonResponse
    {
        try {
            $report = $this->reportService->getProfitReport($request->all());

            return ApiResponse::success('Profit report generated successfully.', $report);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * تقرير مديونيات العملاء
     */
    public function customerDebtsReport(Request $request): JsonResponse
    {
        try {
            $report = $this->reportService->getCustomerDebtsReport($request->all());

            return ApiResponse::success('Customer debts report generated successfully.', $report);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * تقرير مديونيات الموردين
     */
    public function supplierDebtsReport(Request $request): JsonResponse
    {
        try {
            $report = $this->reportService->getSupplierDebtsReport($request->all());

            return ApiResponse::success('Supplier debts report generated successfully.', $report);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * ملخص مالي شامل
     */
    public function financialSummary(Request $request): JsonResponse
    {
        try {
            $report = $this->reportService->getFinancialSummary($request->all());

            return ApiResponse::success('Financial summary generated successfully.', $report);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function profitByModule(Request $request): JsonResponse
    {
        try {
            return ApiResponse::success('Profit by module calculated.', $this->reportService->getProfitByModule($request->all()));
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function cashFlowRealtime(Request $request): JsonResponse
    {
        try {
            return ApiResponse::success('Cash flow snapshot generated.', $this->reportService->getCashFlowRealtime($request->all()));
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function customerLedgerBalances(Request $request): JsonResponse
    {
        try {
            return ApiResponse::success('Customer ledger balances generated.', $this->reportService->getCustomerLedgerBalances($request->all()));
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function bankLedgerReconciliation(Request $request): JsonResponse
    {
        try {
            return ApiResponse::success('Bank vs ledger reconciliation generated.', $this->reportService->getBankLedgerReconciliation($request->all()));
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function latestLedgerReconciliation(): JsonResponse
    {
        try {
            $run = LedgerReconciliationRun::query()->with('findings')->latest('run_at')->first();
            if (! $run) {
                return ApiResponse::success('No reconciliation runs recorded yet.', null);
            }

            return ApiResponse::success('Latest ledger reconciliation retrieved.', $run);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * تقرير الديون والمديونيات الموحد
     */
    public function debtsReport(Request $request): JsonResponse
    {
        try {
            $report = $this->reportService->getDebtsReport($request->all());

            return ApiResponse::success('Debts and receivables report generated successfully.', $report);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }
}
