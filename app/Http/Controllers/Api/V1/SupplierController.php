<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\SupplierService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SupplierController extends Controller
{
    protected SupplierService $supplierService;

    public function __construct(SupplierService $supplierService)
    {
        $this->supplierService = $supplierService;
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', \App\Models\Supplier::class);

        $filters = [
            'search' => $request->search,
            'type' => $request->type,
            'is_active' => $request->is_active,
        ];

        $suppliers = $this->supplierService->getAllSuppliers($filters)
            ->paginate(min($request->per_page ?? 15, 100));

        return ApiResponse::paginated(
            'Suppliers retrieved successfully',
            $suppliers->getCollection(),
            $suppliers
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', \App\Models\Supplier::class);

        $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:airline,bus_company,hotel,visa_provider,service_provider,other',
            'contact_person' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'mobile' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'account_id' => 'nullable|exists:accounts,id',
            'credit_limit' => 'nullable|numeric|min:0',
            'payment_terms' => 'nullable|in:cash,credit_30,credit_60,credit_90',
            'notes' => 'nullable|string|max:1000',
        ]);

        $supplier = $this->supplierService->createSupplier($request->all());

        return ApiResponse::success(
            'Supplier created successfully',
            $supplier,
            201
        );
    }

    public function show(int $id): JsonResponse
    {
        $supplier = $this->supplierService->getSupplierById($id);

        $this->authorize('view', $supplier);

        return ApiResponse::success(
            'Supplier retrieved successfully',
            $supplier
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $supplier = \App\Models\Supplier::findOrFail($id);

        $this->authorize('update', $supplier);

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'type' => 'sometimes|required|in:airline,bus_company,hotel,visa_provider,service_provider,other',
            'contact_person' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'mobile' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'account_id' => 'nullable|exists:accounts,id',
            'credit_limit' => 'nullable|numeric|min:0',
            'payment_terms' => 'nullable|in:cash,credit_30,credit_60,credit_90',
            'is_active' => 'sometimes|boolean',
            'notes' => 'nullable|string|max:1000',
        ]);

        $supplier = $this->supplierService->updateSupplier($supplier, $request->all());

        return ApiResponse::success(
            'Supplier updated successfully',
            $supplier
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $supplier = \App\Models\Supplier::findOrFail($id);

        $this->authorize('delete', $supplier);

        $this->supplierService->deleteSupplier($supplier);

        return ApiResponse::success(
            'Supplier deleted successfully'
        );
    }

    public function getDebt(): JsonResponse
    {
        $this->authorize('viewAny', \App\Models\Supplier::class);

        $debt = $this->supplierService->getSuppliersDebt();

        return ApiResponse::success(
            'Suppliers debt retrieved successfully',
            $debt
        );
    }
}
