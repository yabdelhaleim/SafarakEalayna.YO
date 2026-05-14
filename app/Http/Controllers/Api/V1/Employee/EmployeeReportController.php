<?php

namespace App\Http\Controllers\Api\V1\Employee;

use App\Http\Controllers\Controller;
use App\Services\Employee\EmployeeReportService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class EmployeeReportController extends Controller
{
    protected EmployeeReportService $reportService;

    public function __construct(EmployeeReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    public function overall(Request $request): JsonResponse
    {
        $this->authorize('viewAny', \App\Models\Employee::class);

        $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
        ]);

        $report = $this->reportService->getOverallReport(
            $request->from_date,
            $request->to_date
        );

        return response()->json([
            'status' => true,
            'message' => 'Overall report generated successfully',
            'data' => $report,
        ]);
    }

    public function attendance(Request $request): JsonResponse
    {
        $this->authorize('viewAny', \App\Models\Employee::class);

        $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
        ]);

        $report = $this->reportService->getDetailedAttendanceReport(
            $request->from_date,
            $request->to_date
        );

        return response()->json([
            'status' => true,
            'message' => 'Attendance report generated successfully',
            'data' => $report,
        ]);
    }

    public function bonuses(Request $request): JsonResponse
    {
        $this->authorize('viewAny', \App\Models\Employee::class);

        $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
        ]);

        $report = $this->reportService->getDetailedBonusesReport(
            $request->from_date,
            $request->to_date
        );

        return response()->json([
            'status' => true,
            'message' => 'Bonuses report generated successfully',
            'data' => $report,
        ]);
    }

    public function performance(Request $request, int $employeeId): JsonResponse
    {
        $this->authorize('view', \App\Models\Employee::class);

        $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
        ]);

        $report = $this->reportService->getEmployeePerformanceReport(
            $employeeId,
            $request->from_date,
            $request->to_date
        );

        return response()->json([
            'status' => true,
            'message' => 'Performance report generated successfully',
            'data' => $report,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $fromDate = $request->input('from_date', now()->startOfMonth()->toDateString());
        $toDate = $request->input('to_date', now()->endOfMonth()->toDateString());

        $report = $this->reportService->getOverallReport($fromDate, $toDate);

        return response()->json([
            'status' => true,
            'message' => 'Overall report generated successfully',
            'data' => $report,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['status' => false, 'message' => 'Operation not supported'], 405);
    }

    public function show($id): JsonResponse
    {
        return response()->json(['status' => false, 'message' => 'Operation not supported'], 405);
    }

    public function update(Request $request, $id): JsonResponse
    {
        return response()->json(['status' => false, 'message' => 'Operation not supported'], 405);
    }

    public function destroy($id): JsonResponse
    {
        return response()->json(['status' => false, 'message' => 'Operation not supported'], 405);
    }
}
