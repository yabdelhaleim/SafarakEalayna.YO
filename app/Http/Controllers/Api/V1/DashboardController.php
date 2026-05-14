<?php

namespace App\Http\Controllers\Api\V1;

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
        // TODO: Re-enable authorization when auth is working
        // $this->authorize('viewAny', \App\Models\Customer::class);

        $from = $request->from_date ?? now()->startOfMonth()->toDateString();
        $to = $request->to_date ?? now()->endOfMonth()->toDateString();

        $dashboard = $this->dashboardService->getFullDashboard(
            $request->query('date_from'),
            $request->query('date_to'),
            $request->query('carrier_id'),
            $request->query('system_type'),
        );

        return response()->json([
            'status' => true,
            'message' => 'Dashboard data retrieved successfully',
            'data' => $dashboard,
        ]);
    }

    public function overview(Request $request): JsonResponse
    {
        // TODO: Re-enable authorization when auth is working
        // $this->authorize('viewAny', \App\Models\Customer::class);

        $overview = $this->dashboardService->getOverview();

        return response()->json([
            'status' => true,
            'message' => 'Overview retrieved successfully',
            'data' => $overview,
        ]);
    }

    public function financial(Request $request): JsonResponse
    {
        // TODO: Re-enable authorization when auth is working
        // $this->authorize('viewAny', \App\Models\Customer::class);

        $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
        ]);

        $financial = $this->dashboardService->getFinancialStats(
            $request->from_date,
            $request->to_date
        );

        return response()->json([
            'status' => true,
            'message' => 'Financial stats retrieved successfully',
            'data' => $financial,
        ]);
    }

    public function bookings(Request $request): JsonResponse
    {
        // TODO: Re-enable authorization when auth is working
        // $this->authorize('viewAny', \App\Models\Customer::class);

        $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
        ]);

        $bookings = $this->dashboardService->getBookingsStats(
            $request->from_date,
            $request->to_date
        );

        return response()->json([
            'status' => true,
            'message' => 'Bookings stats retrieved successfully',
            'data' => $bookings,
        ]);
    }

    public function recentActivities(Request $request): JsonResponse
    {
        // TODO: Re-enable authorization when auth is working
        // $this->authorize('viewAny', \App\Models\Customer::class);

        $limit = min($request->limit ?? 10, 50);

        $activities = $this->dashboardService->getRecentActivities($limit);

        return response()->json([
            'status' => true,
            'message' => 'Recent activities retrieved successfully',
            'data' => $activities,
        ]);
    }
}
