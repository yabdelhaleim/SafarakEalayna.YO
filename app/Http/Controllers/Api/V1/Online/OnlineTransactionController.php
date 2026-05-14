<?php

namespace App\Http\Controllers\Api\V1\Online;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Online\StoreOnlineTransactionRequest;
use App\Http\Requests\Online\UpdateOnlineTransactionRequest;
use App\Http\Resources\Online\OnlineTransactionResource;
use App\Models\Online\OnlineTransaction;
use App\Services\Online\OnlineTransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnlineTransactionController extends Controller
{
    public function __construct(
        protected OnlineTransactionService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $filters = $request->only([
                'service_type_id',
                'provider_id',
                'customer_id',
                'employee_id',
                'payment_method',
                'account_id',
                'status',
                'from_date',
                'to_date',
                'search',
                'per_page',
            ]);

            $paginator = $this->service->getAll($filters);

            return ApiResponse::paginated(
                'تم جلب معاملات الخدمات الأونلاين بنجاح.',
                OnlineTransactionResource::collection($paginator),
                $paginator,
            );
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), null, 500);
        }
    }

    public function store(StoreOnlineTransactionRequest $request): JsonResponse
    {
        try {
            $tx = $this->service->create($request->validated());

            return ApiResponse::success(
                'تم تنفيذ معاملة الخدمة بنجاح.',
                new OnlineTransactionResource($tx),
                201,
            );
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function show(OnlineTransaction $onlineTransaction): JsonResponse
    {
        try {
            $tx = $this->service->getById($onlineTransaction->id);

            return ApiResponse::success(
                'تم جلب المعاملة بنجاح.',
                new OnlineTransactionResource($tx),
            );
        } catch (\Throwable $e) {
            return ApiResponse::error('المعاملة غير موجودة.', null, 404);
        }
    }

    public function update(UpdateOnlineTransactionRequest $request, OnlineTransaction $onlineTransaction): JsonResponse
    {
        try {
            $tx = $this->service->update($onlineTransaction, $request->validated());

            return ApiResponse::success(
                'تم تحديث المعاملة بنجاح.',
                new OnlineTransactionResource($tx),
            );
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function destroy(OnlineTransaction $onlineTransaction): JsonResponse
    {
        try {
            $this->service->delete($onlineTransaction);

            return ApiResponse::success('تم حذف المعاملة بنجاح.');
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function dailySummary(Request $request): JsonResponse
    {
        try {
            $request->validate(['date' => 'required|date_format:Y-m-d']);
            $summary = $this->service->getDailySummary($request->string('date')->toString());

            return ApiResponse::success('تم جلب الملخص اليومي بنجاح.', $summary);
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }
}
