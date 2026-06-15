<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\RechargeSupplierAccountRequest;
use App\Http\Resources\Finance\AccountEntryResource;
use App\Http\Resources\Finance\SupplierAccountResource;
use App\Models\Supplier;
use App\Services\Finance\SupplierAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierAccountController extends Controller
{
    public function __construct(
        protected SupplierAccountService $supplierAccountService
    ) {}

    /**
     * شحن رصيد لحساب مورد
     */
    public function recharge(RechargeSupplierAccountRequest $request, Supplier $supplier): JsonResponse
    {
        try {
            $transaction = $this->supplierAccountService->rechargeSupplierAccount(
                $supplier,
                $request->validated()
            );

            return \App\Helpers\ApiResponse::success('Supplier account recharged successfully.', [
                'transaction_id' => $transaction->id,
                'amount' => $transaction->amount,
                'supplier' => new SupplierAccountResource($supplier->fresh()),
            ], 201);
        } catch (\Exception $e) {
            return \App\Helpers\ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * كشف حساب مورد
     */
    public function statement(Request $request, Supplier $supplier): JsonResponse
    {
        try {
            $data = $this->supplierAccountService->getSupplierStatement(
                $supplier,
                $request->all()
            );

            return \App\Helpers\ApiResponse::success('Supplier statement retrieved successfully.', [
                'items' => AccountEntryResource::collection($data['items']),
                'pagination' => $data['pagination'],
                'stats' => $data['stats'],
                'supplier' => new SupplierAccountResource($supplier),
            ]);
        } catch (\Exception $e) {
            return \App\Helpers\ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * الحصول على رصيد مورد
     */
    public function balance(Supplier $supplier): JsonResponse
    {
        try {
            $balance = $this->supplierAccountService->getSupplierBalance($supplier);

            return \App\Helpers\ApiResponse::success('Supplier balance retrieved successfully.', [
                'supplier' => new SupplierAccountResource($supplier),
                'balance' => $balance,
            ], 200);
        } catch (\Exception $e) {
            return \App\Helpers\ApiResponse::error($e->getMessage(), null, 422);
        }
    }
}
