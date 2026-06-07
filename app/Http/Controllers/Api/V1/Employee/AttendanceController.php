<?php

namespace App\Http\Controllers\Api\V1\Employee;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\Employee\EmployeeAttendanceService;
use App\Http\Resources\Employee\EmployeeAttendanceResource;
use App\Models\EmployeeAttendance;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AttendanceController extends Controller
{
    protected EmployeeAttendanceService $attendanceService;

    public function __construct(EmployeeAttendanceService $attendanceService)
    {
        $this->attendanceService = $attendanceService;
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EmployeeAttendance::class);

        $attendances = EmployeeAttendance::query()
            ->with(['employee', 'createdBy'])
            ->when($request->employee_id, fn($q) => $q->byEmployee($request->employee_id))
            ->when($request->from_date && $request->to_date, fn($q) => $q->byDateRange($request->from_date, $request->to_date))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->orderBy('attendance_date', 'desc')
            ->paginate(min($request->per_page ?? 15, 100));

        return ApiResponse::paginated(
            'Attendance records retrieved successfully',
            EmployeeAttendanceResource::collection($attendances),
            $attendances
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', EmployeeAttendance::class);

        $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'attendance_date' => 'required|date',
            'status' => 'required|in:present,absent,late',
            'check_in' => 'nullable|date_format:H:i',
            'check_out' => 'nullable|date_format:H:i',
            'notes' => 'nullable|string|max:500',
        ]);

        $attendance = $this->attendanceService->recordAttendance($request->all());

        return ApiResponse::success(
            'Attendance recorded successfully',
            new EmployeeAttendanceResource($attendance->load('employee')),
            201
        );
    }

    public function show(int $id): JsonResponse
    {
        $attendance = EmployeeAttendance::with(['employee', 'createdBy'])->findOrFail($id);

        $this->authorize('view', $attendance);

        return ApiResponse::success(
            'Attendance record retrieved successfully',
            new EmployeeAttendanceResource($attendance)
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $attendance = EmployeeAttendance::findOrFail($id);

        $this->authorize('update', $attendance);

        $request->validate([
            'status' => 'sometimes|required|in:present,absent,late',
            'check_in' => 'nullable|date_format:H:i',
            'check_out' => 'nullable|date_format:H:i',
            'notes' => 'nullable|string|max:500',
        ]);

        $attendance = $this->attendanceService->updateAttendance($attendance, $request->all());

        return ApiResponse::success(
            'Attendance updated successfully',
            new EmployeeAttendanceResource($attendance->load('employee'))
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $attendance = EmployeeAttendance::findOrFail($id);

        $this->authorize('delete', $attendance);

        $this->attendanceService->deleteAttendance($attendance);

        return ApiResponse::success(
            'Attendance deleted successfully'
        );
    }

    public function getEmployeeAttendance(Request $request, int $employeeId): JsonResponse
    {
        $this->authorize('viewAny', EmployeeAttendance::class);

        $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
        ]);

        $attendances = $this->attendanceService->getEmployeeAttendance(
            $employeeId,
            $request->from_date,
            $request->to_date
        );

        return ApiResponse::success(
            'Employee attendance retrieved successfully',
            EmployeeAttendanceResource::collection($attendances)
        );
    }

    public function getAttendanceSummary(Request $request, int $employeeId): JsonResponse
    {
        $this->authorize('viewAny', EmployeeAttendance::class);

        $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
        ]);

        $summary = $this->attendanceService->getAttendanceSummary(
            $employeeId,
            $request->from_date,
            $request->to_date
        );

        return ApiResponse::success(
            'Attendance summary retrieved successfully',
            $summary
        );
    }

    public function getDailyAttendance(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EmployeeAttendance::class);

        $request->validate([
            'date' => 'required|date',
        ]);

        $attendances = $this->attendanceService->getDailyAttendance($request->date);

        return ApiResponse::success(
            'Daily attendance retrieved successfully',
            EmployeeAttendanceResource::collection($attendances)
        );
    }
}
