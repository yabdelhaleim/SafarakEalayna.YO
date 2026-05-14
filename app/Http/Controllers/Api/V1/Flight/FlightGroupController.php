<?php

namespace App\Http\Controllers\Api\V1\Flight;

use App\Http\Controllers\Controller;
use App\Models\Flight\FlightGroup;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;

class FlightGroupController extends Controller
{
    /**
     * Get groups by carrier
     */
    public function getByCarrier(Request $request, $carrierId)
    {
        $groups = FlightGroup::active()
            ->byCarrier($carrierId)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'commission_rate', 'is_active']);

        return ApiResponse::success('Flight groups retrieved successfully', $groups);
    }

    /**
     * Get all active groups
     */
    public function index(Request $request)
    {
        $groups = FlightGroup::active()
            ->with('carrier:id,name,code')
            ->orderBy('name')
            ->get();

        return ApiResponse::success('Flight groups retrieved successfully', $groups);
    }

    /**
     * Get single group
     */
    public function show(FlightGroup $group)
    {
        return ApiResponse::success('Flight group retrieved successfully', $group->load('carrier'));
    }
}
