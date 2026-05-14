<?php

namespace App\Http\Controllers\Api\V1\Online;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Online\StoreOnlineServiceProviderRequest;
use App\Http\Requests\Online\UpdateOnlineServiceProviderRequest;
use App\Http\Resources\Online\OnlineServiceProviderResource;
use App\Models\Online\OnlineServiceProvider;
use App\Services\Online\OnlineServiceProviderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnlineServiceProviderController extends Controller
{
    public function __construct(
        protected OnlineServiceProviderService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['search', 'is_active', 'per_page']);
            $paginator = $this->service->getAll($filters);

            return ApiResponse::paginated(
                'تم جلب مزودي الخدمات الأونلاين بنجاح.',
                OnlineServiceProviderResource::collection($paginator),
                $paginator,
            );
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), null, 500);
        }
    }

    public function store(StoreOnlineServiceProviderRequest $request): JsonResponse
    {
        try {
            $provider = $this->service->create($request->validated());

            return ApiResponse::success(
                'تم إنشاء مزود الخدمة بنجاح.',
                new OnlineServiceProviderResource($provider),
                201,
            );
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function show(OnlineServiceProvider $onlineServiceProvider): JsonResponse
    {
        try {
            $provider = $this->service->getById($onlineServiceProvider->id);

            return ApiResponse::success(
                'تم جلب مزود الخدمة بنجاح.',
                new OnlineServiceProviderResource($provider),
            );
        } catch (\Throwable $e) {
            return ApiResponse::error('مزود الخدمة غير موجود.', null, 404);
        }
    }

    public function update(UpdateOnlineServiceProviderRequest $request, OnlineServiceProvider $onlineServiceProvider): JsonResponse
    {
        try {
            $provider = $this->service->update($onlineServiceProvider, $request->validated());

            return ApiResponse::success(
                'تم تحديث مزود الخدمة بنجاح.',
                new OnlineServiceProviderResource($provider),
            );
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function destroy(OnlineServiceProvider $onlineServiceProvider): JsonResponse
    {
        try {
            $this->service->delete($onlineServiceProvider);

            return ApiResponse::success('تم حذف مزود الخدمة بنجاح.');
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function active(): JsonResponse
    {
        try {
            $providers = $this->service->getActive();

            return ApiResponse::success(
                'تم جلب مزودي الخدمات النشطين.',
                OnlineServiceProviderResource::collection($providers),
            );
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), null, 500);
        }
    }
}
