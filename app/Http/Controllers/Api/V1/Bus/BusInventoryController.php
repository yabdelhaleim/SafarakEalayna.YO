<?php

namespace App\Http\Controllers\Api\V1\Bus;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bus\PayInventoryDebtRequest;
use App\Http\Requests\Bus\StoreBusInventoryRequest;
use App\Http\Requests\Bus\UpdateBusInventoryRequest;
use App\Http\Resources\Bus\BusCompanyPaymentResource;
use App\Http\Resources\Bus\BusInventoryResource;
use App\Http\Resources\Bus\PublicBusInventoryResource;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusInventory;
use App\Services\Bus\BusInventoryService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusInventoryController extends Controller
{
    public function __construct(
        protected BusInventoryService $inventoryService
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $filters = $request->only([
                'company_id',
                'travel_date',
                'has_available',
                'with_debt',
                'payment_type',
                'per_page',
            ]);
            $paginator = $this->inventoryService->getAllInventories($filters);

            return ApiResponse::paginated(
                'Bus inventories retrieved successfully.',
                BusInventoryResource::collection($paginator),
                $paginator
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * Get available inventories for booking form.
     * Returns inventories with available tickets for a specific company and date.
     */
    public function available(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'company_id' => 'required|exists:bus_companies,id',
                'travel_date' => 'required|date_format:Y-m-d',
            ]);

            $company = BusCompany::query()
                ->whereKey((int) $request->company_id)
                ->where('is_active', true)
                ->first();
            if (! $company) {
                return ApiResponse::error('شركة النقل غير موجودة أو غير مفعّلة.', null, 422);
            }

            $inventories = $this->inventoryService->getAvailableInventories(
                $request->company_id,
                $request->travel_date
            );

            return ApiResponse::success(
                'Available inventories retrieved successfully.',
                PublicBusInventoryResource::collection($inventories)
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function store(StoreBusInventoryRequest $request): JsonResponse
    {
        try {
            $inventory = $this->inventoryService->createInventory($request->validated());

            return ApiResponse::success(
                'Inventory created successfully.',
                new BusInventoryResource($inventory),
                201
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function show(BusInventory $busInventory): JsonResponse
    {
        try {
            // Load relations on the route model bound instance
            $busInventory->load(['company', 'account', 'createdBy']);

            return ApiResponse::success(
                'Inventory retrieved successfully.',
                new BusInventoryResource($busInventory)
            );
        } catch (ModelNotFoundException $e) {
            return ApiResponse::error('Inventory not found.', null, 404);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function update(UpdateBusInventoryRequest $request, BusInventory $busInventory): JsonResponse
    {
        try {
            $inventory = $this->inventoryService->updateInventory($busInventory, $request->validated());

            return ApiResponse::success(
                'Inventory updated successfully.',
                new BusInventoryResource($inventory)
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function payDebt(PayInventoryDebtRequest $request, BusInventory $busInventory): JsonResponse
    {
        try {
            $payment = $this->inventoryService->payInventoryDebt($busInventory, $request->validated());

            return ApiResponse::success(
                'Debt payment recorded successfully.',
                new BusCompanyPaymentResource($payment),
                201
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function destroy(BusInventory $busInventory): JsonResponse
    {
        try {
            $this->inventoryService->deleteInventory($busInventory);

            return ApiResponse::success('Inventory deleted successfully.');
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }
}
