<?php

namespace App\Http\Controllers\Api\V1\Bus;

use App\Enums\TransactionModule;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bus\StoreBusCompanyRequest;
use App\Http\Requests\Bus\UpdateBusCompanyRequest;
use App\Rules\BusLiquidityAccount;
use App\Http\Resources\Bus\BusCompanyResource;
use App\Http\Resources\Bus\PublicBusCompanyResource;
use App\Models\Bus\BusCompany;
use App\Services\Bus\BusCompanyService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusCompanyController extends Controller
{
    public function __construct(
        protected BusCompanyService $companyService,
        protected \App\Services\Finance\TransactionService $transactionService
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
                PublicBusCompanyResource::collection($companies)
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
            $company->load(['createdBy', 'account']);

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
    $company->load('account');
    if (!$company->account_id) {
        return ApiResponse::success('Bus company statement retrieved.', [
            'company' => [
                'id'      => $company->id,
                'name'    => $company->name,
                'balance' => 0,
            ],
            'transactions' => [
              'data'  => [],
              'total' => 0,
              'per_page' => 30,
              'current_page' => 1,
              'last_page' => 1,
            ],
        ]);
    }

    $perPage = min((int) $request->query('per_page', 30), 100);

    $paginator = \App\Models\Transaction::query()
        ->where('module', TransactionModule::Bus)
        ->where(function ($q) use ($company) {
            $q->where('from_account_id', $company->account_id)
                ->orWhere('to_account_id', $company->account_id);
        })
        ->with([
            'fromAccount:id,name',
            'toAccount:id,name',
            'createdBy:id,name',
            'related' => function ($morphTo) {
                $morphTo->morphWith([
                    \App\Models\Bus\BusBooking::class => ['customer', 'inventory', 'relatedTransactions.fromAccount', 'relatedTransactions.toAccount'],
                ]);
            }
        ])
        ->latest()
        ->paginate($perPage);

    return ApiResponse::success('Bus company statement retrieved.', [
        'company' => [
            'id'      => $company->id,
            'name'    => $company->name,
            'balance' => $company->account?->balance ?? 0,
        ],
        'transactions' => $paginator,
    ]);
}

    public function payDebt(Request $request, BusCompany $company): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'from_account_id' => ['required', 'exists:accounts,id', new BusLiquidityAccount],
            'notes' => 'nullable|string|max:500',
            'booking_id' => 'nullable|integer',
        ]);

        $company->load('account');
        if (!$company->account_id) {
            return ApiResponse::error('هذه الشركة غير مربوطة بحساب مالي لتسديد الديون.', null, 422);
        }

        try {
            return \Illuminate\Support\Facades\DB::transaction(function () use ($company, $validated) {
                // 🔒 Lock the account row to prevent concurrent double-payments (race condition fix)
                $toAccount = \App\Models\Account::lockForUpdate()->findOrFail($company->account_id);
                $fromAccount = \App\Models\Account::findOrFail($validated['from_account_id']);

                $currentDebt = (float) $toAccount->balance; // negative = we owe company
                $actualDebt  = max(0, -$currentDebt);       // absolute debt amount

                // 🛡️ Guard: do not allow overpayment beyond what is actually owed
                if ($actualDebt <= 0) {
                    throw new \Exception(
                        'لا يوجد دين مستحق لهذه الشركة. الرصيد الحالي: ' . number_format($currentDebt, 2)
                    );
                }

                $amount = (float) $validated['amount'];
                $willOverpay = $amount > $actualDebt + 0.005; // 0.5 piaster tolerance

                if ($willOverpay) {
                    throw new \Exception(
                        'مبلغ الدفع (' . number_format($amount, 2) . ' ج.م) يتجاوز الدين الفعلي (' . number_format($actualDebt, 2) . ' ج.م). استخدم المبلغ الصحيح لتجنب الدفع الزائد.'
                    );
                }

                $bookingId = !empty($validated['booking_id']) ? (int) $validated['booking_id'] : null;

                // Record transaction using TransactionService
                $transaction = $this->transactionService->recordJournalTransfer([
                    'amount'             => $amount,
                    'from_account_id'    => $fromAccount->id,
                    'to_account_id'      => $toAccount->id,
                    'module'             => \App\Enums\TransactionModule::Bus->value,
                    'notes'              => $validated['notes'] ?? 'تسديد دين شركة باصات',
                    'created_by'         => \Illuminate\Support\Facades\Auth::id() ?? 1,
                    'allow_from_negative'=> false,
                    'related_type'       => $bookingId ? \App\Models\Bus\BusBooking::class : null,
                    'related_id'         => $bookingId,
                ]);

                // Create BusCompanyPayment record
                \App\Models\Bus\BusCompanyPayment::create([
                    'company_id'     => $company->id,
                    'inventory_id'   => null,
                    'amount'         => $amount,
                    'account_id'     => $fromAccount->id,
                    'transaction_id' => $transaction->id,
                    'status'         => \App\Enums\BusCompanyPaymentStatus::Paid,
                    'notes'          => $validated['notes'] ?? 'تسديد دين شركة باصات',
                    'created_by'     => \Illuminate\Support\Facades\Auth::id() ?? 1,
                ]);

                $newBalance = $toAccount->fresh()->balance;

                return ApiResponse::success('تم تسديد الدين بنجاح.', [
                    'transaction_id' => $transaction->id,
                    'new_balance'    => $newBalance,
                    'fully_settled'  => $newBalance >= -0.005, // true if debt is cleared
                ]);
            });
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }
}
