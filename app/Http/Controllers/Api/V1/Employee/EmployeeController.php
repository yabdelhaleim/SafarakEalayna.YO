<?php

namespace App\Http\Controllers\Api\V1\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Services\Employee\EmployeeService;
use App\Services\Employee\EmployeeAttendanceService;
use App\Services\Employee\EmployeeBonusService;
use App\Http\Resources\Employee\EmployeeResource;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class EmployeeController extends Controller
{
    protected EmployeeService $employeeService;
    protected EmployeeAttendanceService $attendanceService;
    protected EmployeeBonusService $bonusService;

    public function __construct(
        EmployeeService $employeeService,
        EmployeeAttendanceService $attendanceService,
        EmployeeBonusService $bonusService
    ) {
        $this->employeeService = $employeeService;
        $this->attendanceService = $attendanceService;
        $this->bonusService = $bonusService;
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

        $filters = [
            'search' => $request->search,
            'status' => $request->status,
            'employment_type' => $request->employment_type,
            'per_page' => min($request->per_page ?? 15, 100),
            'page' => $request->get('page', 1),
        ];

        $cacheKey = 'employees_list_' . md5(serialize($filters));

        $data = \App\Helpers\CacheHelper::tags(['employees'])->remember($cacheKey, 60, function () use ($filters) {
            $employees = $this->employeeService->getAllEmployees($filters)
                ->paginate($filters['per_page']);
            return EmployeeResource::collection($employees)->response()->getData(true);
        });

        return ApiResponse::success(
            'Employees retrieved successfully',
            $data
        );
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $this->authorize('create', Employee::class);

        $employee = $this->employeeService->createEmployee($request->validated());

        return ApiResponse::success(
            'Employee created successfully',
            new EmployeeResource($employee),
            201
        );
    }

    public function show(int $id): JsonResponse
    {
        $employee = $this->employeeService->getEmployeeById($id);

        $this->authorize('view', $employee);

        return ApiResponse::success(
            'Employee retrieved successfully',
            new EmployeeResource($employee)
        );
    }

    public function update(UpdateEmployeeRequest $request, int $id): JsonResponse
    {
        $employee = Employee::findOrFail($id);

        $this->authorize('update', $employee);

        $employee = $this->employeeService->updateEmployee($employee, $request->validated());

        return ApiResponse::success(
            'Employee updated successfully',
            new EmployeeResource($employee)
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $employee = Employee::findOrFail($id);

        $this->authorize('delete', $employee);

        $this->employeeService->deleteEmployee($employee);

        return ApiResponse::success('Employee deleted successfully');
    }

    public function referenceData(): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

        $departments = Employee::query()
            ->whereNotNull('department')
            ->where('department', '!=', '')
            ->distinct()
            ->orderBy('department')
            ->pluck('department')
            ->map(fn (string $d) => ['value' => $d, 'label' => $d])
            ->values();

        $statusLabels = [
            'active' => 'نشط',
            'on_leave' => 'في إجازة',
            'terminated' => 'منتهي',
            'resigned' => 'مستقيل',
        ];

        $employmentStatuses = collect(EmploymentStatus::cases())->map(function (EmploymentStatus $s) use ($statusLabels) {
            return [
                'value' => $s->value,
                'label' => $statusLabels[$s->value] ?? $s->name,
            ];
        })->values();

        return ApiResponse::success(
            'Employee reference data retrieved successfully',
            [
                'departments' => $departments,
                'employment_statuses' => $employmentStatuses,
            ]
        );
    }

    public function getStats(Request $request, int $id): JsonResponse
    {
        $this->authorize('view', Employee::class);

        $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
        ]);

        $stats = $this->employeeService->getEmployeeStats(
            $id,
            $request->from_date,
            $request->to_date
        );

        return ApiResponse::success(
            'Employee statistics retrieved successfully',
            $stats
        );
    }

    public function transactions(int $id): JsonResponse
    {
        $employee = Employee::findOrFail($id);

        if (!$employee->user_id) {
            return ApiResponse::success(
                'No user account linked to this employee',
                []
            );
        }

        $transactions = \Illuminate\Support\Facades\DB::table('transactions')
            ->where('created_by', $employee->user_id)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        return ApiResponse::success(
            'Employee transactions retrieved successfully',
            $transactions
        );
    }
}
