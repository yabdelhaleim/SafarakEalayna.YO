<?php

namespace App\Http\Controllers\Api\V1\Flight;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Flight\AirlineAccount;
use App\Models\Flight\AirlineTransaction;
use App\Support\Finance\LedgerBalanceMutationGuard;
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
            return DB::transaction(function () use ($validated, $request) {
                $account = AirlineAccount::findOrFail($validated['airline_account_id']);
                $amount = (float) $validated['amount'];

                // ✅ Phase 1v2 FIX: لف الـ credit() داخل LedgerBalanceMutationGuard
                //    عشان DB::listen() safety net في AppServiceProvider يحترم الـ write
                //    + تسجيل audit log تلقائي
                $tx = LedgerBalanceMutationGuard::run(function () use ($account, $validated, $amount) {
                    return $account->credit(
                        amount: $amount,
                        description: $validated['description'],
                        userId: Auth::id() ?: 1
                    );
                });

                // Audit log للـ API call
                AuditLog::create([
                    'user_id' => Auth::id() ?: 1,
                    'action' => 'airline_account_credit_via_api',
                    'model_type' => AirlineAccount::class,
                    'model_id' => $account->id,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'old_values' => ['balance' => (float) $account->getOriginal('balance')],
                    'new_values' => ['balance' => (float) $account->fresh()->balance],
                    'notes' => "API credit via AirlineAccountController: amount={$amount}, description='{$validated['description']}'",
                ]);

                Log::info('Airline account credited (recharge)', [
                    'airline_account_id' => $account->id,
                    'amount' => $amount,
                    'transaction_id' => $tx->id,
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
                        'transaction' => $tx->load('createdBy'),
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
     * ✅ Phase 1v2: 'balance' removed from validation — must start at 0
     *    Opening balances must go through the prepaid GL journal entry,
     *    not direct column write.
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
            'currency' => 'required|string|in:EGP,KWD,SAR,USD,AED',
            // ❌ 'balance' intentionally NOT in validation — ممنوع mass assignment
            'credit_limit' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string',
        ]);

        try {
            return DB::transaction(function () use ($validated, $request) {
                $account = AirlineAccount::create([
                    'name' => $validated['name'],
                    'code' => $validated['code'],
                    'system_type' => $validated['system_type'],
                    'currency' => $validated['currency'],
                    // 'balance' NOT passed — Phase 1v2: ممنوع الكتابة المباشرة
                    'credit_limit' => $validated['credit_limit'] ?? 0,
                    'is_active' => $validated['is_active'] ?? true,
                    'notes' => $validated['notes'] ?? null,
                ]);

                // Audit log
                AuditLog::create([
                    'user_id' => Auth::id() ?: 1,
                    'action' => 'airline_account_created_via_api',
                    'model_type' => AirlineAccount::class,
                    'model_id' => $account->id,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'old_values' => [],
                    'new_values' => $account->toArray(),
                    'notes' => 'Created via API. Initial balance = 0 (use addCredit endpoint for opening balance).',
                ]);

                Log::info('Airline account created', [
                    'airline_account_id' => $account->id,
                    'user_id' => Auth::id(),
                ]);

                return ApiResponse::success(
                    'تم إنشاء حساب شركة الطيران بنجاح',
                    ['account' => $account]
                );
            });
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
     * ✅ Phase 1v2: 'balance' intentionally NOT updatable here.
     *    الـ mass assignment حماية تمنع الكتابة على balance حتى لو الـ client بعتها.
     *    الـ DB::listen() safety net في AppServiceProvider بيمسك أي direct UPDATE.
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
            'currency' => 'nullable|string|in:EGP,KWD,SAR,USD,AED',
            'credit_limit' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string',
            // ❌ 'balance' ممنوع — ممنوع حتى في الـ update
        ]);

        try {
            return DB::transaction(function () use ($validated, $request, $id) {
                $account = AirlineAccount::findOrFail($id);
                $oldValues = $account->only(['name', 'code', 'currency', 'credit_limit', 'is_active']);

                // ✅ Phase 1v2: لا 'balance' في المصفوفة — لو الـ client بعتها، الـ mass assignment
                //    protection هيتجاهلها بصمت (مش هترمي exception)
                $account->update([
                    'name' => $validated['name'] ?? $account->name,
                    'code' => $validated['code'] ?? $account->code,
                    'system_type' => $validated['system_type'] ?? $account->system_type,
                    'currency' => $validated['currency'] ?? $account->currency,
                    'credit_limit' => $validated['credit_limit'] ?? $account->credit_limit,
                    'is_active' => $validated['is_active'] ?? $account->is_active,
                    'notes' => $validated['notes'] ?? $account->notes,
                ]);

                // Audit log
                AuditLog::create([
                    'user_id' => Auth::id() ?: 1,
                    'action' => 'airline_account_updated_via_api',
                    'model_type' => AirlineAccount::class,
                    'model_id' => $account->id,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'old_values' => $oldValues,
                    'new_values' => $account->fresh()->only(['name', 'code', 'currency', 'credit_limit', 'is_active']),
                    'notes' => 'Updated via API (Phase 1v2: balance field never updatable here)',
                ]);

                Log::info('Airline account updated', [
                    'airline_account_id' => $account->id,
                    'user_id' => Auth::id(),
                ]);

                return ApiResponse::success(
                    'تم تحديث حساب شركة الطيران بنجاح',
                    ['account' => $account->fresh()]
                );
            });
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
     * @param Request $request
     * @return JsonResponse
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            return DB::transaction(function () use ($id, $request) {
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

                $snapshot = $account->toArray();

                $account->delete();

                // Audit log
                AuditLog::create([
                    'user_id' => Auth::id() ?: 1,
                    'action' => 'airline_account_deleted_via_api',
                    'model_type' => AirlineAccount::class,
                    'model_id' => $id,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'old_values' => $snapshot,
                    'new_values' => [],
                    'notes' => 'Soft-deleted via API',
                ]);

                Log::info('Airline account deleted', [
                    'airline_account_id' => $id,
                    'user_id' => Auth::id(),
                ]);

                return ApiResponse::success(
                    'تم حذف حساب شركة الطيران بنجاح',
                    null
                );
            });
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
