<?php

namespace App\Http\Controllers\Api\V1\Bus;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bus\StoreBusCompanyRequest;
use App\Http\Requests\Bus\UpdateBusCompanyRequest;
use App\Http\Resources\Bus\BusCompanyResource;
use App\Models\Bus\BusCompany;
use App\Services\Bus\BusCompanyService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusCompanyController extends Controller
{
    public function __construct(
        protected BusCompanyService $companyService
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['search', 'is_active', 'per_page']);
            $paginator = $this->companyService->getAllCompanies($filters);

            return ApiResponse::paginated(
                'Bus companies retrieved successfully.',
                BusCompanyResource::collection($paginator),
                $paginator
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * Public booking widget: active companies only (no pagination).
     */
    public function publicIndex(): JsonResponse
    {
        try {
            $companies = BusCompany::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get();

            return ApiResponse::success(
                'Active bus companies retrieved successfully.',
                BusCompanyResource::collection($companies)
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function store(StoreBusCompanyRequest $request): JsonResponse
    {
        try {
            $company = $this->companyService->createCompany($request->validated());

            return ApiResponse::success(
                'Bus company created successfully.',
                new BusCompanyResource($company),
                201
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function show(BusCompany $company): JsonResponse
    {
        try {
            // Load relations on the route model bound instance
            $company->load(['createdBy']);

            return ApiResponse::success(
                'Bus company retrieved successfully.',
                new BusCompanyResource($company)
            );
        } catch (ModelNotFoundException $e) {
            return ApiResponse::error('Bus company not found.', null, 404);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function update(UpdateBusCompanyRequest $request, BusCompany $company): JsonResponse
    {
        try {
            $company = $this->companyService->updateCompany($company, $request->validated());

            return ApiResponse::success(
                'Bus company updated successfully.',
                new BusCompanyResource($company)
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function destroy(BusCompany $company): JsonResponse
    {
        try {
            $this->companyService->deleteCompany($company);

            return ApiResponse::success('Bus company deleted successfully.');
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function statement(Request $request, BusCompany $company): JsonResponse
    {
        if (!$company->account_id) {
            return ApiResponse::error('هذه الشركة غير مربوطة بحساب مالي.', null, 422);
        }

        $perPage = min((int) $request->query('per_page', 30), 100);

        $paginator = \App\Models\Transaction::query()
            ->where(function ($q) use ($company) {
                $q->where('from_account_id', $company->account_id)
                    ->orWhere('to_account_id', $company->account_id);
            })
            ->with(['fromAccount:id,name', 'toAccount:id,name', 'createdBy:id,name'])
            ->latest()
            ->paginate($perPage);

        return ApiResponse::success('Bus company statement retrieved.', [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'balance' => $company->account?->balance ?? 0,
            ],
            'transactions' => $paginator
        ]);
    }

    public function payDebt(Request $request, BusCompany $company): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'from_account_id' => 'required|exists:accounts,id',
            'notes' => 'nullable|string|max:500',
        ]);

        if (!$company->account_id) {
            return ApiResponse::error('هذه الشركة غير مربوطة بحساب مالي لتسديد الديون.', null, 422);
        }

        try {
            return \Illuminate\Support\Facades\DB::transaction(function () use ($company, $validated) {
                $fromAccount = \App\Models\Account::findOrFail($validated['from_account_id']);
                $toAccount = $company->account;

                // Create Transaction
                $transaction = \App\Models\Transaction::create([
                    'type' => \App\Enums\TransactionType::Transfer,
                    'amount' => $validated['amount'],
                    'from_account_id' => $fromAccount->id,
                    'to_account_id' => $toAccount->id,
                    'module' => \App\Enums\TransactionModule::Bus,
                    'notes' => $validated['notes'] ?? 'تسديد دين شركة باصات',
                    'created_by' => \Illuminate\Support\Facades\Auth::id(),
                ]);

                // Update Balances
                $fromAccount->decrement('balance', $validated['amount']);
                $toAccount->increment('balance', $validated['amount']);

                return ApiResponse::success('تم تسديد الدين بنجاح.', [
                    'transaction_id' => $transaction->id,
                    'new_balance' => $toAccount->balance,
                ]);
            });
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }
}
