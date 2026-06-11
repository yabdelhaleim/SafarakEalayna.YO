<?php

namespace App\Http\Controllers\Api\V1\Flight;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Flight\FlightCarrier;
use App\Services\Flight\FlightCarrierRechargeService;
use Illuminate\Http\Request;

class FlightCarrierController extends Controller
{
    public function index(Request $request)
    {
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
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'flight_system_id' => 'nullable|exists:flight_systems,id',
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:flight_carriers,code',
            'iata_code' => 'nullable|string|max:10',
            'currency' => 'required|string|max:10',
            'balance' => 'numeric|min:0',
            'credit_limit' => 'numeric|min:0',
            'is_active' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        $validated['created_by'] = $request->user()?->id ?? 1;
        $validated['is_active'] = $validated['is_active'] ?? true;
        $validated['balance'] = $validated['balance'] ?? 0;
        $validated['credit_limit'] = $validated['credit_limit'] ?? 0;

        $carrier = FlightCarrier::create($validated);

        return ApiResponse::success('Flight carrier created successfully', $carrier, 201);
    }

    public function show(FlightCarrier $carrier)
    {
        $carrier->load(['system', 'groups', 'transactions' => function ($q) {
            $q->latest()->limit(10);
        }]);

        return ApiResponse::success('Flight carrier retrieved successfully', $carrier);
    }

    public function update(Request $request, FlightCarrier $carrier)
    {
        $validated = $request->validate([
            'flight_system_id' => 'nullable|exists:flight_systems,id',
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:50|unique:flight_carriers,code,'.$carrier->id,
            'iata_code' => 'nullable|string|max:10',
            'currency' => 'sometimes|required|string|max:10',
            'balance' => 'numeric|min:0',
            'credit_limit' => 'numeric|min:0',
            'is_active' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        $carrier->update($validated);

        return ApiResponse::success('Flight carrier updated successfully', $carrier);
    }

    public function destroy(FlightCarrier $carrier)
    {
        $carrier->delete();

        return ApiResponse::success('Flight carrier deleted successfully');
    }

    public function balance(Request $request, FlightCarrier $carrier)
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
    public function recharge(Request $request, FlightCarrier $carrier)
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
