<?php

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\StoreWalletTransactionRequest;
use App\Http\Requests\Wallet\UpdateWalletTransactionRequest;
use App\Http\Resources\Wallet\WalletTransactionResource;
use App\Models\Wallet\WalletTransaction;
use App\Services\Wallet\WalletTransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletTransactionController extends Controller
{
    public function __construct(
        protected WalletTransactionService $service
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters   = $request->only([
            'type', 'wallet_type_id', 'customer_id', 'employee_id',
            'search', 'from_date', 'to_date', 'per_page',
        ]);
        $paginator = $this->service->getAllTransactions($filters);

        return ApiResponse::paginated(
            'Wallet transactions retrieved successfully.',
            WalletTransactionResource::collection($paginator),
            $paginator
        );
    }

    public function store(StoreWalletTransactionRequest $request): JsonResponse
    {
        try {
            $transaction = $this->service->createTransaction($request->validated());

            return ApiResponse::success(
                'Wallet transaction created successfully.',
                new WalletTransactionResource($transaction),
                201
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function show(WalletTransaction $transaction): JsonResponse
    {
        $transaction->load([
            'walletType', 'customer', 'walletAccount', 'cashAccount',
            'employee', 'createdBy', 'incomeTransaction', 'expenseTransaction',
        ]);

        return ApiResponse::success(
            'Wallet transaction retrieved successfully.',
            new WalletTransactionResource($transaction)
        );
    }

    public function update(UpdateWalletTransactionRequest $request, WalletTransaction $transaction): JsonResponse
    {
        try {
            $updated = $this->service->updateTransaction($transaction, $request->validated());

            return ApiResponse::success(
                'Wallet transaction updated successfully.',
                new WalletTransactionResource($updated)
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function destroy(WalletTransaction $transaction): JsonResponse
    {
        try {
            $this->service->deleteTransaction($transaction);

            return ApiResponse::success('Wallet transaction deleted successfully.');
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function dailySummary(Request $request): JsonResponse
    {
        $date    = $request->get('date', today()->toDateString());
        $summary = $this->service->getDailySummary($date);

        return ApiResponse::success('Daily summary retrieved successfully.', $summary);
    }
}
