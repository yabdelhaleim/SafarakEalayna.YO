<?php

namespace App\Http\Controllers\Api\V1\Fawry;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Fawry\FawryMachine;
use App\Services\Fawry\FawryMachineRechargeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class FawryMachineApiController extends Controller
{
    /**
     * Get list of all fawry machines.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = FawryMachine::query();

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('type', 'like', "%{$search}%");
                });
            }

            $machines = $query->orderBy('name')->get();

            return ApiResponse::success(
                'تم جلب ماكينات فوري بنجاح',
                [
                    'machines' => $machines->map(function ($machine) {
                        return [
                            'id' => $machine->id,
                            'name' => $machine->name,
                            'type' => $machine->type,
                            'balance' => (float) $machine->balance,
                            'is_active' => $machine->is_active,
                            'notes' => $machine->notes,
                        ];
                    }),
                ]
            );
        } catch (\Exception $e) {
            Log::error('FawryMachineApiController::index failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return ApiResponse::error('فشل في جلب ماكينات فوري');
        }
    }

    /**
     * Get transactions history for a specific machine.
     */
    public function transactions(Request $request, int $id): JsonResponse
    {
        try {
            $machine = FawryMachine::findOrFail($id);
            $query = $machine->transactions()->with('createdBy');

            if ($request->has('type')) {
                $query->where('type', $request->type);
            }

            if ($request->has('from_date')) {
                $query->whereDate('created_at', '>=', $request->from_date);
            }
            if ($request->has('to_date')) {
                $query->whereDate('created_at', '<=', $request->to_date);
            }

            $perPage = min($request->per_page ?? 20, 100);
            $transactions = $query->latest()->paginate($perPage);

            return ApiResponse::success(
                'تم جلب معاملات الماكينة بنجاح',
                [
                    'machine' => [
                        'id' => $machine->id,
                        'name' => $machine->name,
                        'type' => $machine->type,
                        'balance' => (float) $machine->balance,
                    ],
                    'transactions' => $transactions,
                ]
            );
        } catch (\Exception $e) {
            Log::error('FawryMachineApiController::transactions failed', [
                'error' => $e->getMessage(),
                'machine_id' => $id,
                'user_id' => Auth::id(),
            ]);

            return ApiResponse::error('فشل في جلب معاملات الماكينة');
        }
    }

    /**
     * Recharge machine balance.
     */
    public function recharge(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'from_account_id' => 'required|integer|exists:accounts,id',
            'amount' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $machine = FawryMachine::findOrFail($id);
            // Accept any active Fawry-module or office-division account whose
            // name indicates a Fawry cashbox/wallet/bank. Cashboxes per
            // AccountModuleContract cannot carry module_type='fawry', so a
            // strict equality would block legitimate office cashboxes tagged
            // for the fawry module.
            $source = Account::where('is_active', true)
                ->where(function ($q) {
                    $q->whereIn('module_type', ['fawry', 'office'])
                        ->where(function ($q2) {
                            $q2->where('name', 'like', '%فوري%')
                                ->orWhere('name', 'like', '%Fawry%');
                        });
                })
                ->findOrFail($validated['from_account_id']);

            $service = app(FawryMachineRechargeService::class);
            $result = $service->rechargeFromAccount(
                $machine,
                $source,
                (float) $validated['amount'],
                $validated['notes'] ?? null
            );

            return ApiResponse::success(
                'تم شحن ماكينة فوري بنجاح',
                [
                    'machine' => [
                        'id' => $result['machine']->id,
                        'name' => $result['machine']->name,
                        'balance' => (float) $result['machine']->balance,
                    ],
                    'source_account' => [
                        'id' => $result['source_account']->id,
                        'name' => $result['source_account']->name,
                        'balance' => (float) $result['source_account']->balance,
                    ],
                    'transaction' => $result['machine_transaction'],
                ]
            );
        } catch (\Exception $e) {
            Log::error('FawryMachineApiController::recharge failed', [
                'error' => $e->getMessage(),
                'machine_id' => $id,
                'input' => $validated,
                'user_id' => Auth::id(),
            ]);

            return ApiResponse::error('فشل في شحن رصيد الماكينة: '.$e->getMessage());
        }
    }

    /**
     * Get module-specific accounts for fawry module.
     */
    public function fawryAccounts(): JsonResponse
    {
        try {
            $accounts = Account::where('module_type', 'fawry')
                ->where('is_active', true)
                ->orderBy('name')
                ->get();

            return ApiResponse::success(
                'تم جلب الحسابات المالية لموديول فوري بنجاح',
                [
                    'accounts' => $accounts->map(function ($acc) {
                        return [
                            'id' => $acc->id,
                            'name' => $acc->name,
                            'type' => $acc->type,
                            'balance' => (float) $acc->balance,
                            'currency' => $acc->currency ?? 'EGP',
                        ];
                    }),
                ]
            );
        } catch (\Exception $e) {
            Log::error('FawryMachineApiController::fawryAccounts failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            return ApiResponse::error('فشل في جلب حسابات موديول فوري');
        }
    }
}
