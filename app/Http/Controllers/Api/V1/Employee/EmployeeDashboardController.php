<?php

namespace App\Http\Controllers\Api\V1\Employee;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Bus\BusBooking;
use App\Models\FawryTransaction;
use App\Models\FlightBooking;
use App\Models\HajjUmraBooking;
use App\Models\Online\OnlineTransaction;
use App\Models\VisaBooking;
use App\Models\EmployeeAttendance;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

class EmployeeDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        $userId = $user->id;
        
        // Try to get employee ID from user relation or column
        $employeeId = $user->employee_id;
        if (!$employeeId && $user->relationLoaded('employee')) {
            $employeeId = $user->employee?->id;
        } elseif (!$employeeId) {
            // Lazy load if needed
            $employeeId = $user->employee?->id;
        }

        // Date filters for "My Sales" (Current month by default)
        $startOfMonth = now()->startOfMonth();
        $endOfMonth = now()->endOfMonth();

        // 1. My Sales Counts
        $sales = [
            'flights' => FlightBooking::where('created_by', $userId)->whereBetween('created_at', [$startOfMonth, $endOfMonth])->count(),
            'bus' => BusBooking::where('created_by', $userId)->whereBetween('created_at', [$startOfMonth, $endOfMonth])->count(),
            'hajj_umra' => HajjUmraBooking::where('created_by', $userId)->whereBetween('created_at', [$startOfMonth, $endOfMonth])->count(),
            'visas' => VisaBooking::where('created_by', $userId)->whereBetween('created_at', [$startOfMonth, $endOfMonth])->count(),
            'fawry' => FawryTransaction::where('employee_id', $userId)->whereBetween('created_at', [$startOfMonth, $endOfMonth])->count(),
            'online' => OnlineTransaction::where('created_by', $userId)->whereBetween('created_at', [$startOfMonth, $endOfMonth])->count(),
        ];

        // 2. Attendance Status
        $attendanceStatus = null;
        if ($employeeId) {
            $todayAttendance = EmployeeAttendance::where('employee_id', $employeeId)
                ->whereDate('attendance_date', Carbon::today())
                ->first();

            if ($todayAttendance) {
                $attendanceStatus = [
                    'check_in' => $todayAttendance->check_in,
                    'check_out' => $todayAttendance->check_out,
                    'status' => $todayAttendance->status,
                ];
            }
        }

        // 3. Recent My Activity
        $recentActivity = FlightBooking::where('created_by', $userId)
            ->latest()
            ->take(8)
            ->get()
            ->map(fn($b) => [
                'type' => 'flight',
                'description' => "حجز طيران " . ($b->booking_number ?: "#$b->id"),
                'time' => $b->created_at->diffForHumans(),
                'status' => $b->status,
            ]);

        return ApiResponse::success('Employee dashboard data retrieved', [
            'user' => [
                'name' => $user->name,
                'role' => 'موظف',
            ],
            'sales_summary' => $sales,
            'attendance' => $attendanceStatus,
            'recent_activity' => $recentActivity,
        ]);
    }
}
