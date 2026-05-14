<?php

namespace App\Http\Controllers\Api\V1\Flight;

use App\Http\Controllers\Controller;
use App\Models\Flight\FlightCarrier;
use App\Helpers\ApiResponse;
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
                'currency', 'balance', 'credit_limit', 'is_active'
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
        $carrier->load(['system', 'groups', 'transactions' => function($q) {
            $q->latest()->limit(10);
        }]);

        return ApiResponse::success('Flight carrier retrieved successfully', $carrier);
    }

    public function update(Request $request, FlightCarrier $carrier)
    {
        $validated = $request->validate([
            'flight_system_id' => 'nullable|exists:flight_systems,id',
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:50|unique:flight_carriers,code,' . $carrier->id,
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
}
