<?php

namespace App\Http\Controllers\Api\V1\Flight;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Flight\FlightCarrier;
use App\Services\Flight\FlightCarrierRechargeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FlightCarrierController — CRUD endpoints for flight carriers.
 *
 * ✅ Phase 3 Refactor (2026-07-29):
 *   - `balance` removed from mass-assignable validation. Balance is owned by
 *     `FlightCarrierRechargeService` + `debit()/credit()` only. Any direct write
 *     is rejected by the model's `updating` boot guard.
 *   - All write paths are wrapped in `DB::transaction()` so a partial failure
 *     does not leave the carrier row in an inconsistent state.
 *   - Every state-changing action produces an `AuditLog::create()` row plus a
 *     structured `Log::info()` line for traceability.
 */
class FlightCarrierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $query = FlightCarrier::active()->with('system');

            $systemId = $request->query('system_id') ?? $request->query('flight_system_id');
            if ($systemId !== null && $systemId !== '') {
                $query->where('flight_system_id', $systemId);
            }

            $carriers = $query->orderBy('name')
                ->get([
                    'id', 'name', 'code', 'flight_system_id',
                    'currency', 'balance', 'credit_limit', 'is_active',
                ]);

            return ApiResponse::success('Flight carriers retrieved successfully', $carriers);
        } catch (\Exception $e) {
            Log::error('FlightCarrierController::index failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);
            return ApiResponse::error('فشل في جلب قائمة الناقلين', null, 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'flight_system_id' => 'nullable|exists:flight_systems,id',
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:flight_carriers,code',
            'iata_code' => 'nullable|string|max:10',
            'currency' => 'required|string|max:10',
            // ❌ 'balance' intentionally NOT in validation — ممنوع الكتابة على الرصيد مباشرة
            //    الرصيد يُدار حصرياً عبر FlightCarrierRechargeService + debit()/credit()
            'credit_limit' => 'numeric|min:0',
            'is_active' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        $validated['created_by'] = Auth::id() ?: 1;
        $validated['is_active'] = $validated['is_active'] ?? true;
        $validated['credit_limit'] = $validated['credit_limit'] ?? 0;

        try {
            return DB::transaction(function () use ($validated, $request) {
                $carrier = FlightCarrier::create([
                    'name' => $validated['name'],
                    'code' => $validated['code'],
                    'flight_system_id' => $validated['flight_system_id'] ?? null,
                    'iata_code' => $validated['iata_code'] ?? null,
                    'currency' => $validated['currency'],
                    // 'balance' NOT passed — الرصيد الابتدائي = 0 دائماً
                    'credit_limit' => $validated['credit_limit'] ?? 0,
                    'is_active' => $validated['is_active'] ?? true,
                    'notes' => $validated['notes'] ?? null,
                    'created_by' => $validated['created_by'],
                ]);

                AuditLog::create([
                    'user_id' => $validated['created_by'],
                    'action' => 'flight_carrier_created_via_api',
                    'model_type' => FlightCarrier::class,
                    'model_id' => $carrier->id,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'old_values' => [],
                    'new_values' => $carrier->toArray(),
                    'notes' => 'Created via API. Initial balance = 0 (use rechargeFromAccount() to fund).',
                ]);

                Log::info('Flight carrier created', [
                    'flight_carrier_id' => $carrier->id,
                    'name' => $carrier->name,
                    'currency' => $carrier->currency,
                    'user_id' => $validated['created_by'],
                ]);

                return ApiResponse::success(
                    'تم إنشاء الناقل بنجاح',
                    $carrier,
                    201
                );
            });
        } catch (\Exception $e) {
            Log::error('FlightCarrierController::store failed', [
                'error' => $e->getMessage(),
                'input' => $validated,
                'user_id' => Auth::id(),
            ]);
            return ApiResponse::error('فشل في إنشاء الناقل', null, 500);
        }
    }

    public function show(FlightCarrier $carrier): JsonResponse
    {
        $carrier->load(['system', 'groups', 'transactions' => function ($q) {
            $q->latest()->limit(10);
        }]);

        return ApiResponse::success('Flight carrier retrieved successfully', $carrier);
    }

    public function update(Request $request, FlightCarrier $carrier): JsonResponse
    {
        $validated = $request->validate([
            'flight_system_id' => 'nullable|exists:flight_systems,id',
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:50|unique:flight_carriers,code,'.$carrier->id,
            'iata_code' => 'nullable|string|max:10',
            'currency' => 'sometimes|required|string|max:10',
            // ❌ 'balance' ممنوع — ممنوع حتى في الـ update
            'credit_limit' => 'numeric|min:0',
            'is_active' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        try {
            return DB::transaction(function () use ($validated, $carrier, $request) {
                $oldValues = $carrier->only(['name', 'code', 'currency', 'credit_limit', 'is_active']);

                // ملاحظة: 'balance' لا يُمرَّر أبداً — الـ mass assignment protection تتجاهله بصمت
                $carrier->update([
                    'flight_system_id' => array_key_exists('flight_system_id', $validated) ? $validated['flight_system_id'] : $carrier->flight_system_id,
                    'name' => $validated['name'] ?? $carrier->name,
                    'code' => $validated['code'] ?? $carrier->code,
                    'iata_code' => array_key_exists('iata_code', $validated) ? $validated['iata_code'] : $carrier->iata_code,
                    'currency' => $validated['currency'] ?? $carrier->currency,
                    'credit_limit' => array_key_exists('credit_limit', $validated) ? $validated['credit_limit'] : $carrier->credit_limit,
                    'is_active' => array_key_exists('is_active', $validated) ? $validated['is_active'] : $carrier->is_active,
                    'notes' => array_key_exists('notes', $validated) ? $validated['notes'] : $carrier->notes,
                ]);

                AuditLog::create([
                    'user_id' => Auth::id() ?: 1,
                    'action' => 'flight_carrier_updated_via_api',
                    'model_type' => FlightCarrier::class,
                    'model_id' => $carrier->id,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'old_values' => $oldValues,
                    'new_values' => $carrier->fresh()->only(['name', 'code', 'currency', 'credit_limit', 'is_active']),
                    'notes' => 'Updated via API (balance field never updatable here)',
                ]);

                Log::info('Flight carrier updated', [
                    'flight_carrier_id' => $carrier->id,
                    'user_id' => Auth::id(),
                ]);

                return ApiResponse::success(
                    'تم تحديث الناقل بنجاح',
                    $carrier->fresh()
                );
            });
        } catch (\Exception $e) {
            Log::error('FlightCarrierController::update failed', [
                'error' => $e->getMessage(),
                'carrier_id' => $carrier->id,
                'input' => $validated,
                'user_id' => Auth::id(),
            ]);
            return ApiResponse::error('فشل في تحديث الناقل', null, 500);
        }
    }

    /**
     * Soft-delete a carrier.
     *
     * Refuses if the carrier still has a non-zero balance or active bookings —
     * prevents dangling GL entries or orphaned AirlineTransaction rows.
     */
    public function destroy(Request $request, FlightCarrier $carrier): JsonResponse
    {
        try {
            return DB::transaction(function () use ($carrier, $request) {
                // امنع الحذف لو الناقل لا يزال عنده رصيد (يمكن أن يكون هناك debit/credit معلق)
                if ((float) $carrier->balance !== 0.0) {
                    return ApiResponse::error(
                        "لا يمكن حذف الناقل لوجود رصيد غير صفري ({$carrier->balance} {$carrier->currency}). ".
                        'اسحب الرصيد أولاً عبر FlightCarrierRechargeService أو عالج المعاملات المعلقة.'
                    );
                }

                // امنع الحذف لو في حجوزات مرتبطة
                if ($carrier->bookings()->exists()) {
                    return ApiResponse::error(
                        'لا يمكن حذف الناقل لوجود حجوزات مرتبطة به'
                    );
                }

                $snapshot = $carrier->toArray();
                $carrier->delete();

                AuditLog::create([
                    'user_id' => Auth::id() ?: 1,
                    'action' => 'flight_carrier_deleted_via_api',
                    'model_type' => FlightCarrier::class,
                    'model_id' => $carrier->id,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'old_values' => $snapshot,
                    'new_values' => [],
                    'notes' => 'Soft-deleted via API',
                ]);

                Log::info('Flight carrier deleted', [
                    'flight_carrier_id' => $carrier->id,
                    'user_id' => Auth::id(),
                ]);

                return ApiResponse::success('تم حذف الناقل بنجاح');
            });
        } catch (\Exception $e) {
            Log::error('FlightCarrierController::destroy failed', [
                'error' => $e->getMessage(),
                'carrier_id' => $carrier->id,
                'user_id' => Auth::id(),
            ]);
            return ApiResponse::error('فشل في حذف الناقل', null, 500);
        }
    }

    public function balance(Request $request, FlightCarrier $carrier): JsonResponse
    {
        $availableBalance = $carrier->available_balance;

        return ApiResponse::success('Carrier balance retrieved successfully', [
            'carrier_id' => $carrier->id,
            'carrier_name' => $carrier->name,
            'balance' => $carrier->balance,
            'credit_limit' => $carrier->credit_limit,
            'available_balance' => $availableBalance,
            'currency' => $carrier->currency,
        ]);
    }

    /**
     * شحن رصيد ناقل الطيران من حساب مالي.
     * POST /api/v1/flight/carriers/{carrier}/recharge
     */
    public function recharge(Request $request, FlightCarrier $carrier): JsonResponse
    {
        $validated = $request->validate([
            'from_account_id' => 'required|integer|exists:accounts,id',
            'amount' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:500',
        ]);

        $account = Account::findOrFail($validated['from_account_id']);

        // التحقق من تطابق العملة مبكراً قبل الدخول للـ Service
        if (strtoupper($account->currency) !== strtoupper($carrier->currency)) {
            return ApiResponse::error(
                "تضارب في العملة: الحساب المختار بعملة ({$account->currency}) ".
                "لا يتطابق مع عملة الناقل ({$carrier->currency}).",
                null,
                422
            );
        }

        try {
            $result = app(FlightCarrierRechargeService::class)->rechargeFromAccount(
                $carrier,
                $account,
                (float) $validated['amount'],
                $validated['notes'] ?? null,
            );

            return ApiResponse::success(
                "تم شحن رصيد الناقل {$carrier->name} بنجاح",
                [
                    'carrier' => [
                        'id' => $result['carrier']->id,
                        'name' => $result['carrier']->name,
                        'code' => $result['carrier']->code,
                        'currency' => $result['carrier']->currency,
                        'balance' => (float) $result['carrier']->balance,
                        'credit_limit' => (float) $result['carrier']->credit_limit,
                        'available_balance' => $result['carrier']->available_balance,
                    ],
                    'transaction' => $result['airline_transaction'],
                    'source_account' => [
                        'id' => $result['source_account']->id,
                        'name' => $result['source_account']->name,
                        'balance' => (float) $result['source_account']->balance,
                    ],
                ]
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }
}