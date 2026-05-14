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

    public function show(FlightSystem $system)
    {
        return ApiResponse::success('Flight system retrieved successfully', $system->load('carriers'));
    }
}
