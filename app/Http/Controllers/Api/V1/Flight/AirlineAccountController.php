<?php

namespace App\Http\Controllers\Api\V1\Flight;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Flight\AirlineAccount;
use App\Models\Flight\AirlineTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class AirlineAccountController extends Controller
{
    /**
     * Get all airline accounts with their balances and transactions.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = AirlineAccount::with(['transactions' => function ($q) {
                $q->latest()->limit(10);
            }]);

            // Filter by active status
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            // Filter by system type
            if ($request->has('system_type')) {
                $query->where('system_type', $request->system_type);
            }

            // Search by name or code
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%");
                });
            }

            $accounts = $query->orderBy('name')->get();

            return ApiResponse::success(
                'تم جلب حسابات شركات الطيران بنجاح',
                [
                    'accounts' => $accounts->map(function ($account) {
                        return [
                            'id' => $account->id,
                            'name' => $account->name,
                            'code' => $account->code,
                            'system_type' => $account->system_type,
                            'currency' => $account->currency,
                            'balance' => (float) $account->balance,
                            'credit_limit' => (float) $account->credit_limit,
                            'available_balance' => $account->available_balance,
                            'is_active' => $account->is_active,
                            'notes' => $account->notes,
                            'transactions_count' => $account->transactions()->count(),
                            'latest_transactions' => $account->transactions->take(5),
                        ];
                    }),
                ]
            );
        } catch (\Exception $e) {
            Log::error('AirlineAccountController::index failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);
            return ApiResponse::error('فشل في جلب حسابات شركات الطيران');
        }
    }

    /**
     * Get transactions for a specific airline account.
     *
     * @param Request $request
     * @param int $accountId
     * @return JsonResponse
     */
    public function transactions(Request $request, int $accountId): JsonResponse
    {
        try {
            $account = AirlineAccount::with('transactions.createdBy')->findOrFail($accountId);

            $query = $account->transactions();

            // Filter by transaction type
            if ($request->has('type')) {
                $query->where('type', $request->type);
            }

            // Filter by date range
            if ($request->has('from_date')) {
                $query->whereDate('created_at', '>=', $request->from_date);
            }
            if ($request->has('to_date')) {
                $query->whereDate('created_at', '<=', $request->to_date);
            }

            $perPage = min($request->per_page ?? 20, 100);
            $transactions = $query->latest()->paginate($perPage);

            return ApiResponse::success(
                'تم جلب معاملات حساب شركة الطيران بنجاح',
                [
                    'account' => [
                        'id' => $account->id,
                        'name' => $account->name,
                        'code' => $account->code,
                        'balance' => (float) $account->balance,
                        'credit_limit' => (float) $account->credit_limit,
                        'available_balance' => $account->available_balance,
                    ],
                    'transactions' => $transactions,
                ]
            );
        } catch (\Exception $e) {
            Log::error('AirlineAccountController::transactions failed', [
                'error' => $e->getMessage(),
                'account_id' => $accountId,
                'user_id' => Auth::id(),
            ]);
            return ApiResponse::error('فشل في جلب معاملات حساب شركة الطيران');
        }
    }

    /**
     * Add credit to an airline account (recharge).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function addCredit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'airline_account_id' => 'required|integer|exists:airline_accounts,id',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string|max:500',
        ]);

        try {
            return DB::transaction(function () use ($validated) {
                $account = AirlineAccount::findOrFail($validated['airline_account_id']);
                $amount = (float) $validated['amount'];

                // Credit the account
                $transaction = $account->credit(
                    amount: $amount,
                    description: $validated['description'],
                    userId: Auth::id() ?: 1
                );

                Log::info('Airline account credited (recharge)', [
                    'airline_account_id' => $account->id,
                    'amount' => $amount,
                    'transaction_id' => $transaction->id,
                    'balance_after' => $account->fresh()->balance,
                    'user_id' => Auth::id(),
                ]);

                return ApiResponse::success(
                    'تم شحن حساب شركة الطيران بنجاح',
                    [
                        'account' => [
                            'id' => $account->id,
                            'name' => $account->name,
                            'code' => $account->code,
                            'balance' => (float) $account->fresh()->balance,
                            'credit_limit' => (float) $account->credit_limit,
                            'available_balance' => $account->fresh()->available_balance,
                        ],
                        'transaction' => $transaction->load('createdBy'),
                    ]
                );
            });
        } catch (\Exception $e) {
            Log::error('AirlineAccountController::addCredit failed', [
                'error' => $e->getMessage(),
                'input' => $validated,
                'user_id' => Auth::id(),
            ]);
            return ApiResponse::error('فشل في شحن حساب شركة الطيران');
        }
    }

    /**
     * Create a new airline account.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:airline_accounts,code',
            'system_type' => 'required|string|max:100',
            'currency' => 'required|string|max:3',
            'balance' => 'nullable|numeric|min:0',
            'credit_limit' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string',
        ]);

        try {
            $account = AirlineAccount::create([
                'name' => $validated['name'],
                'code' => $validated['code'],
                'system_type' => $validated['system_type'],
                'currency' => $validated['currency'] ?? 'SAR',
                'balance' => $validated['balance'] ?? 0,
                'credit_limit' => $validated['credit_limit'] ?? 0,
                'is_active' => $validated['is_active'] ?? true,
                'notes' => $validated['notes'] ?? null,
            ]);

            Log::info('Airline account created', [
                'airline_account_id' => $account->id,
                'user_id' => Auth::id(),
            ]);

            return ApiResponse::success(
                'تم إنشاء حساب شركة الطيران بنجاح',
                ['account' => $account]
            );
        } catch (\Exception $e) {
            Log::error('AirlineAccountController::store failed', [
                'error' => $e->getMessage(),
                'input' => $validated,
                'user_id' => Auth::id(),
            ]);
            return ApiResponse::error('فشل في إنشاء حساب شركة الطيران');
        }
    }

    /**
     * Update an airline account.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('airline_accounts', 'code')->ignore($id),
            ],
            'system_type' => 'nullable|string|max:100',
            'currency' => 'nullable|string|max:3',
            'credit_limit' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string',
        ]);

        try {
            $account = AirlineAccount::findOrFail($id);

            // Note: balance should not be updated directly - use credit/debit transactions
            $account->update([
                'name' => $validated['name'] ?? $account->name,
                'code' => $validated['code'] ?? $account->code,
                'system_type' => $validated['system_type'] ?? $account->system_type,
                'currency' => $validated['currency'] ?? $account->currency,
                'credit_limit' => $validated['credit_limit'] ?? $account->credit_limit,
                'is_active' => $validated['is_active'] ?? $account->is_active,
                'notes' => $validated['notes'] ?? $account->notes,
            ]);

            Log::info('Airline account updated', [
                'airline_account_id' => $account->id,
                'user_id' => Auth::id(),
            ]);

            return ApiResponse::success(
                'تم تحديث حساب شركة الطيران بنجاح',
                ['account' => $account->fresh()]
            );
        } catch (\Exception $e) {
            Log::error('AirlineAccountController::update failed', [
                'error' => $e->getMessage(),
                'account_id' => $id,
                'input' => $validated,
                'user_id' => Auth::id(),
            ]);
            return ApiResponse::error('فشل في تحديث حساب شركة الطيران');
        }
    }

    /**
     * Delete an airline account.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $account = AirlineAccount::findOrFail($id);

            // Check if account has bookings
            if ($account->bookings()->exists()) {
                return ApiResponse::error(
                    'لا يمكن حذف الحساب لوجود حجوزات مرتبطة به'
                );
            }

            // Check if account has transactions
            if ($account->transactions()->exists()) {
                return ApiResponse::error(
                    'لا يمكن حذف الحساب لوجود معاملات مرتبطة به'
                );
            }

            $account->delete();

            Log::info('Airline account deleted', [
                'airline_account_id' => $id,
                'user_id' => Auth::id(),
            ]);

            return ApiResponse::success(
                'تم حذف حساب شركة الطيران بنجاح',
                null
            );
        } catch (\Exception $e) {
            Log::error('AirlineAccountController::destroy failed', [
                'error' => $e->getMessage(),
                'account_id' => $id,
                'user_id' => Auth::id(),
            ]);
            return ApiResponse::error('فشل في حذف حساب شركة الطيران');
        }
    }
}
