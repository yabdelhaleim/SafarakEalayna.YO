<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    protected DashboardService $dashboardService;

    public function __construct(DashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
    }

    public function index(Request $request): JsonResponse
    {
        $from = $request->from_date ?? $request->date_from ?? now()->startOfMonth()->toDateString();
        $to = $request->to_date ?? $request->date_to ?? now()->endOfMonth()->toDateString();

        $dashboard = $this->dashboardService->getFullDashboard(
            $from,
            $to,
            $request->query('carrier_id'),
            $request->query('system_type'),
        );

        return ApiResponse::success('Dashboard data retrieved successfully', $dashboard);
    }

    public function overview(Request $request): JsonResponse
    {
        $overview = $this->dashboardService->getOverview();

        return ApiResponse::success('Overview retrieved successfully', $overview);
    }

    public function financial(Request $request): JsonResponse
    {
        $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
        ]);

        $financial = $this->dashboardService->getFinancialStats(
            $request->from_date,
            $request->to_date
        );

        return ApiResponse::success('Financial stats retrieved successfully', $financial);
    }

    public function bookings(Request $request): JsonResponse
    {
        $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
        ]);

        $bookings = $this->dashboardService->getBookingsStats(
            $request->from_date,
            $request->to_date
        );

        return ApiResponse::success('Bookings stats retrieved successfully', $bookings);
    }

    public function recentActivities(Request $request): JsonResponse
    {
        $limit = min($request->limit ?? 10, 50);

        $activities = $this->dashboardService->getRecentActivities($limit);

        return ApiResponse::success('Recent activities retrieved successfully', $activities);
    }
}
