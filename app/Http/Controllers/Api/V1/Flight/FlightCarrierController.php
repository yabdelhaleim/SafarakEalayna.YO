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

    public function show(FlightCarrier $carrier)
    {
        $carrier->load(['system', 'groups', 'transactions' => function($q) {
            $q->latest()->limit(10);
        }]);

        return ApiResponse::success('Flight carrier retrieved successfully', $carrier);
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
