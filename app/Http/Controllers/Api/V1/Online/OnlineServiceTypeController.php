<?php

namespace App\Http\Controllers\Api\V1\Online;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Online\StoreOnlineServiceTypeRequest;
use App\Http\Requests\Online\UpdateOnlineServiceTypeRequest;
use App\Http\Resources\Online\OnlineServiceTypeResource;
use App\Models\Online\OnlineServiceType;
use App\Services\Online\OnlineServiceTypeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnlineServiceTypeController extends Controller
{
    public function __construct(
        protected OnlineServiceTypeService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['search', 'is_active', 'per_page']);
            $paginator = $this->service->getAllTypes($filters);

            return ApiResponse::paginated(
                'تم جلب أنواع الخدمات الأونلاين بنجاح.',
                OnlineServiceTypeResource::collection($paginator),
                $paginator,
            );
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), null, 500);
        }
    }

    public function store(StoreOnlineServiceTypeRequest $request): JsonResponse
    {
        try {
            $type = $this->service->create($request->validated());

            return ApiResponse::success(
                'تم إنشاء نوع الخدمة بنجاح.',
                new OnlineServiceTypeResource($type),
                201,
            );
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function show(OnlineServiceType $onlineServiceType): JsonResponse
    {
        try {
            $type = $this->service->getById($onlineServiceType->id);

            return ApiResponse::success(
                'تم جلب نوع الخدمة بنجاح.',
                new OnlineServiceTypeResource($type),
            );
        } catch (\Throwable $e) {
            return ApiResponse::error('نوع الخدمة غير موجود.', null, 404);
        }
    }

    public function update(UpdateOnlineServiceTypeRequest $request, OnlineServiceType $onlineServiceType): JsonResponse
    {
        try {
            $type = $this->service->update($onlineServiceType, $request->validated());

            return ApiResponse::success(
                'تم تحديث نوع الخدمة بنجاح.',
                new OnlineServiceTypeResource($type),
            );
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function destroy(OnlineServiceType $onlineServiceType): JsonResponse
    {
        try {
            $this->service->delete($onlineServiceType);

            return ApiResponse::success('تم حذف نوع الخدمة بنجاح.');
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function active(): JsonResponse
    {
        try {
            $types = $this->service->getActiveTypes();

            return ApiResponse::success(
                'تم جلب أنواع الخدمات النشطة.',
                OnlineServiceTypeResource::collection($types),
            );
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), null, 500);
        }
    }
}
