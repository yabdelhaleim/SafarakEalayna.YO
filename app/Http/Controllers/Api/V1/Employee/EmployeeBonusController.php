<?php

namespace App\Http\Controllers\Api\V1\Employee;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreEmployeeBonusRequest;
use App\Http\Resources\Employee\EmployeeBonusResource;
use App\Models\Employee\EmployeeBonus;
use App\Services\Employee\EmployeeBonusService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeBonusController extends Controller
{
    public function __construct(
        protected EmployeeBonusService $bonusService
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $filters = $request->only([
                'employee_id',
                'type',
                'from_date',
                'to_date',
                'per_page',
            ]);
            $paginator = $this->bonusService->getAllBonuses($filters);

            return ApiResponse::paginated(
                'Records retrieved successfully.',
                EmployeeBonusResource::collection($paginator),
                $paginator
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function bonus(StoreEmployeeBonusRequest $request): JsonResponse
    {
        try {
            $bonus = $this->bonusService->addBonus($request->validated());

            return ApiResponse::success(
                'Bonus added successfully.',
                new EmployeeBonusResource($bonus),
                201
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function deduction(StoreEmployeeBonusRequest $request): JsonResponse
    {
        try {
            $deduction = $this->bonusService->addDeduction($request->validated());

            return ApiResponse::success(
                'Deduction added successfully.',
                new EmployeeBonusResource($deduction),
                201
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function draw(StoreEmployeeBonusRequest $request): JsonResponse
    {
        try {
            $draw = $this->bonusService->addDraw($request->validated());

            return ApiResponse::success(
                'Salary draw added successfully.',
                new EmployeeBonusResource($draw),
                201
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function show(EmployeeBonus $employeeBonus): JsonResponse
    {
        try {
            // Load relations on the route model bound instance
            $employeeBonus->load(['employee', 'createdBy']);

            return ApiResponse::success(
                'Record retrieved successfully.',
                new EmployeeBonusResource($employeeBonus)
            );
        } catch (ModelNotFoundException $e) {
            return ApiResponse::error('Record not found.', null, 404);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function employeeSummary(Request $request, int $employeeId): JsonResponse
    {
        try {
            $filters = $request->only(['from_date', 'to_date']);
            $summary = $this->bonusService->getEmployeeSummary($employeeId, $filters);

            return ApiResponse::success(
                'Employee summary retrieved successfully.',
                $summary
            );
        } catch (ModelNotFoundException $e) {
            return ApiResponse::error('Employee not found.', null, 404);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function activitySummary(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['from_date', 'to_date', 'employee_id']);

            // Validate date formats if provided
            if (isset($filters['from_date']) && ! Carbon::hasFormat($filters['from_date'], 'Y-m-d')) {
                return ApiResponse::error('Invalid from_date format.', null, 422);
            }
            if (isset($filters['to_date']) && ! Carbon::hasFormat($filters['to_date'], 'Y-m-d')) {
                return ApiResponse::error('Invalid to_date format.', null, 422);
            }

            $summary = $this->bonusService->getEmployeeActivitySummary($filters);

            return ApiResponse::success(
                'Activity summary retrieved successfully.',
                $summary
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }
}
