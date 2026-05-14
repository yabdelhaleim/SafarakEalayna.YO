<?php

namespace App\Http\Controllers\Api\V1\Fawry;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fawry\StoreFawryTransactionRequest;
use App\Http\Requests\Fawry\UpdateFawryTransactionRequest;
use App\Http\Resources\Fawry\FawryTransactionResource;
use App\Models\Fawry\FawryTransaction;
use App\Services\Fawry\FawryTransactionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FawryTransactionController extends Controller
{
    public function __construct(
        protected FawryTransactionService $transactionService
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $filters = $request->only([
                'operation_type',
                'payment_method',
                'employee_id',
                'from_date',
                'to_date',
                'search',
                'per_page',
            ]);

            $paginator = $this->transactionService->getAllTransactions($filters);

            return ApiResponse::paginated(
                'Fawry transactions retrieved successfully.',
                FawryTransactionResource::collection($paginator),
                $paginator
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function store(StoreFawryTransactionRequest $request): JsonResponse
    {
        try {
            $transaction = $this->transactionService->createTransaction($request->validated());

            return ApiResponse::success(
                'Fawry transaction created successfully.',
                new FawryTransactionResource($transaction),
                201
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function show(FawryTransaction $fawryTransaction): JsonResponse
    {
        try {
            $fawryTransaction->load([
                'employee',
                'account',
                'expenseTransaction',
                'incomeTransaction',
                'operationTypeRow',
                'paymentMethodRow',
            ]);

            return ApiResponse::success(
                'Fawry transaction retrieved successfully.',
                new FawryTransactionResource($fawryTransaction)
            );
        } catch (ModelNotFoundException $e) {
            return ApiResponse::error('Fawry transaction not found.', null, 404);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function update(UpdateFawryTransactionRequest $request, FawryTransaction $fawryTransaction): JsonResponse
    {
        try {
            $transaction = $this->transactionService->updateTransaction(
                $fawryTransaction,
                $request->validated()
            );

            return ApiResponse::success(
                'Fawry transaction updated successfully.',
                new FawryTransactionResource($transaction)
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function destroy(FawryTransaction $fawryTransaction): JsonResponse
    {
        try {
            $this->transactionService->deleteTransaction($fawryTransaction);

            return ApiResponse::success(
                'Fawry transaction deleted successfully.',
                null
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function dailySummary(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'date' => 'required|date_format:Y-m-d',
            ]);
        } catch (\Exception $e) {
            return ApiResponse::error('Invalid date format.', null, 422);
        }

        try {
            $summary = $this->transactionService->getDailySummary($request->date);

            return ApiResponse::success(
                'Daily summary retrieved successfully.',
                $summary
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }
}
