<?php

namespace App\Http\Controllers\Api\V1\Flight;

use App\Http\Controllers\Controller;
use App\Models\Flight\FlightSystem;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;

class FlightSystemController extends Controller
{
    public function index(Request $request)
    {
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
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:flight_systems,code',
            'type' => 'nullable|string|max:50',
            'is_active' => 'boolean',
            'currency' => 'required|string|max:10',
            'balance' => 'numeric|min:0',
            'credit_limit' => 'numeric|min:0',
            'description' => 'nullable|string',
        ]);

        $validated['created_by'] = $request->user()?->id ?? 1;
        $validated['is_active'] = $validated['is_active'] ?? true;
        $validated['balance'] = $validated['balance'] ?? 0;
        $validated['credit_limit'] = $validated['credit_limit'] ?? 0;

        $system = FlightSystem::create($validated);

        return ApiResponse::success('Flight system created successfully', $system, 201);
    }

    public function show(FlightSystem $system)
    {
        return ApiResponse::success('Flight system retrieved successfully', $system->load('carriers'));
    }

    public function update(Request $request, FlightSystem $system)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'code' => 'sometimes|required|string|max:50|unique:flight_systems,code,' . $system->id,
            'type' => 'nullable|string|max:50',
            'is_active' => 'boolean',
            'currency' => 'sometimes|required|string|max:10',
            'balance' => 'numeric|min:0',
            'credit_limit' => 'numeric|min:0',
            'description' => 'nullable|string',
        ]);

        $system->update($validated);

        return ApiResponse::success('Flight system updated successfully', $system);
    }

    public function destroy(FlightSystem $system)
    {
        $system->delete();

        return ApiResponse::success('Flight system deleted successfully');
    }
}
