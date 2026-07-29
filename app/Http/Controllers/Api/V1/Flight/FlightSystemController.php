<?php

namespace App\Http\Controllers\Api\V1\Flight;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Flight\FlightSystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FlightSystemController — CRUD endpoints for flight systems (GDS / NDC / carrier signatories).
 *
 * ✅ Phase 3 Refactor (2026-07-29):
 *   - `balance` removed from mass-assignable validation. Balance is owned by
 *     `FlightSystemRechargeService` + `debit()/credit()` only. Any direct write
 *     is rejected by the model's `updating` boot guard.
 *   - All write paths are wrapped in `DB::transaction()`.
 *   - Every state-changing action produces an `AuditLog::create()` row plus a
 *     structured `Log::info()` line for traceability.
 */
class FlightSystemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $systems = FlightSystem::active()
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                    'code',
                    'currency',
                    'balance',
                    'credit_limit',
                    'is_active',
                ]);

            return ApiResponse::success('Flight systems retrieved successfully', $systems);
        } catch (\Exception $e) {
            Log::error('FlightSystemController::index failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);
            return ApiResponse::error('فشل في جلب قائمة الأنظمة', null, 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:flight_systems,code',
            'type' => 'nullable|string|max:50',
            'is_active' => 'boolean',
            'currency' => 'required|string|max:10',
            // ❌ 'balance' ممنوع — الرصيد يُدار حصرياً عبر FlightSystemRechargeService
            'credit_limit' => 'numeric|min:0',
            'description' => 'nullable|string',
        ]);

        $userId = Auth::id() ?: 1;
        $validated['created_by'] = $userId;
        $validated['is_active'] = $validated['is_active'] ?? true;
        $validated['credit_limit'] = $validated['credit_limit'] ?? 0;

        try {
            return DB::transaction(function () use ($validated, $request, $userId) {
                $system = FlightSystem::create([
                    'name' => $validated['name'],
                    'code' => $validated['code'],
                    'type' => $validated['type'] ?? null,
                    'is_active' => $validated['is_active'] ?? true,
                    'currency' => $validated['currency'],
                    // 'balance' NOT passed — الرصيد الابتدائي = 0 دائماً
                    'credit_limit' => $validated['credit_limit'] ?? 0,
                    'description' => $validated['description'] ?? null,
                    'created_by' => $userId,
                ]);

                AuditLog::create([
                    'user_id' => $userId,
                    'action' => 'flight_system_created_via_api',
                    'model_type' => FlightSystem::class,
                    'model_id' => $system->id,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'old_values' => [],
                    'new_values' => $system->toArray(),
                    'notes' => 'Created via API. Initial balance = 0 (use rechargeFromAccount() to fund).',
                ]);

                Log::info('Flight system created', [
                    'flight_system_id' => $system->id,
                    'name' => $system->name,
                    'currency' => $system->currency,
                    'user_id' => $userId,
                ]);

                return ApiResponse::success(
                    'تم إنشاء النظام بنجاح',
                    $system,
                    201
                );
            });
        } catch (\Exception $e) {
            Log::error('FlightSystemController::store failed', [
                'error' => $e->getMessage(),
                'input' => $validated,
                'user_id' => Auth::id(),
            ]);
            return ApiResponse::error('فشل في إنشاء النظام', null, 500);
        }
    }

    public function show(FlightSystem $system): JsonResponse
    {
        return ApiResponse::success('Flight system retrieved successfully', $system->load('carriers'));
    }

    public function update(Request $request, FlightSystem $system): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:50|unique:flight_systems,code,' . $system->id,
            'type' => 'nullable|string|max:50',
            'is_active' => 'boolean',
            'currency' => 'sometimes|required|string|max:10',
            // ❌ 'balance' ممنوع — ممنوع حتى في الـ update
            'credit_limit' => 'numeric|min:0',
            'description' => 'nullable|string',
        ]);

        try {
            return DB::transaction(function () use ($validated, $system, $request) {
                $oldValues = $system->only(['name', 'code', 'currency', 'credit_limit', 'is_active']);

                $system->update([
                    'name' => $validated['name'] ?? $system->name,
                    'code' => $validated['code'] ?? $system->code,
                    'type' => array_key_exists('type', $validated) ? $validated['type'] : $system->type,
                    'is_active' => array_key_exists('is_active', $validated) ? $validated['is_active'] : $system->is_active,
                    'currency' => $validated['currency'] ?? $system->currency,
                    'credit_limit' => array_key_exists('credit_limit', $validated) ? $validated['credit_limit'] : $system->credit_limit,
                    'description' => array_key_exists('description', $validated) ? $validated['description'] : $system->description,
                ]);

                AuditLog::create([
                    'user_id' => Auth::id() ?: 1,
                    'action' => 'flight_system_updated_via_api',
                    'model_type' => FlightSystem::class,
                    'model_id' => $system->id,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'old_values' => $oldValues,
                    'new_values' => $system->fresh()->only(['name', 'code', 'currency', 'credit_limit', 'is_active']),
                    'notes' => 'Updated via API (balance field never updatable here)',
                ]);

                Log::info('Flight system updated', [
                    'flight_system_id' => $system->id,
                    'user_id' => Auth::id(),
                ]);

                return ApiResponse::success(
                    'تم تحديث النظام بنجاح',
                    $system->fresh()
                );
            });
        } catch (\Exception $e) {
            Log::error('FlightSystemController::update failed', [
                'error' => $e->getMessage(),
                'system_id' => $system->id,
                'input' => $validated,
                'user_id' => Auth::id(),
            ]);
            return ApiResponse::error('فشل في تحديث النظام', null, 500);
        }
    }

    /**
     * Soft-delete a flight system.
     *
     * Refuses if the system still has a non-zero balance or any carriers
     * attached — prevents dangling GL entries or orphaned carrier rows.
     */
    public function destroy(Request $request, FlightSystem $system): JsonResponse
    {
        try {
            return DB::transaction(function () use ($system, $request) {
                // امنع الحذف لو في رصيد
                if ((float) $system->balance !== 0.0) {
                    return ApiResponse::error(
                        "لا يمكن حذف النظام لوجود رصيد غير صفري ({$system->balance} {$system->currency}). ".
                        'اسحب الرصيد أولاً.'
                    );
                }

                // امنع الحذف لو في ناقلين مرتبطين
                if ($system->carriers()->exists()) {
                    return ApiResponse::error(
                        'لا يمكن حذف النظام لوجود ناقلين مرتبطين به. احذف الناقلين أولاً.'
                    );
                }

                $snapshot = $system->toArray();
                $system->delete();

                AuditLog::create([
                    'user_id' => Auth::id() ?: 1,
                    'action' => 'flight_system_deleted_via_api',
                    'model_type' => FlightSystem::class,
                    'model_id' => $system->id,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'old_values' => $snapshot,
                    'new_values' => [],
                    'notes' => 'Soft-deleted via API',
                ]);

                Log::info('Flight system deleted', [
                    'flight_system_id' => $system->id,
                    'user_id' => Auth::id(),
                ]);

                return ApiResponse::success('تم حذف النظام بنجاح');
            });
        } catch (\Exception $e) {
            Log::error('FlightSystemController::destroy failed', [
                'error' => $e->getMessage(),
                'system_id' => $system->id,
                'user_id' => Auth::id(),
            ]);
            return ApiResponse::error('فشل في حذف النظام', null, 500);
        }
    }
}