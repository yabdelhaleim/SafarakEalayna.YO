<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

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
        $carrierId = $request->query('carrier_id') ?? '';
        $systemType = $request->query('system_type') ?? '';

        $cacheKey = "dashboard_full_{$from}_{$to}_{$carrierId}_{$systemType}";

        $dashboard = \App\Helpers\CacheHelper::tags(['dashboard'])->remember($cacheKey, 300, function () use ($from, $to, $carrierId, $systemType) {
            return $this->dashboardService->getFullDashboard(
                $from,
                $to,
                $carrierId,
                $systemType
            );
        });

        return ApiResponse::success('Dashboard data retrieved successfully', $dashboard);
    }

    public function overview(Request $request): JsonResponse
    {
        $overview = \App\Helpers\CacheHelper::tags(['dashboard'])->remember('dashboard_overview', 300, function () {
            return $this->dashboardService->getOverview();
        });

        return ApiResponse::success('Overview retrieved successfully', $overview);
    }

    public function financial(Request $request): JsonResponse
    {
        $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
        ]);
        
        $from = $request->from_date;
        $to = $request->to_date;
        $cacheKey = "dashboard_financial_{$from}_{$to}";

        $financial = \App\Helpers\CacheHelper::tags(['dashboard'])->remember($cacheKey, 300, function () use ($from, $to) {
            return $this->dashboardService->getFinancialStats($from, $to);
        });

        return ApiResponse::success('Financial stats retrieved successfully', $financial);
    }

    public function bookings(Request $request): JsonResponse
    {
        $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
        ]);

        $from = $request->from_date;
        $to = $request->to_date;
        $cacheKey = "dashboard_bookings_{$from}_{$to}";

        $bookings = \App\Helpers\CacheHelper::tags(['dashboard'])->remember($cacheKey, 300, function () use ($from, $to) {
            return $this->dashboardService->getBookingsStats($from, $to);
        });

        return ApiResponse::success('Bookings stats retrieved successfully', $bookings);
    }

    public function recentActivities(Request $request): JsonResponse
    {
        $limit = min($request->limit ?? 10, 50);
        $cacheKey = "dashboard_recent_activities_{$limit}";

        $activities = \App\Helpers\CacheHelper::tags(['dashboard'])->remember($cacheKey, 300, function () use ($limit) {
            return $this->dashboardService->getRecentActivities($limit);
        });

        return ApiResponse::success('Recent activities retrieved successfully', $activities);
    }
}
