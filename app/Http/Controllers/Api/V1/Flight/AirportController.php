<?php

namespace App\Http\Controllers\Api\V1\Flight;

use App\Http\Controllers\Controller;
use App\Models\Airport;
use App\Helpers\ApiResponse;
use Illuminate\Http\Request;

class AirportController extends Controller
{
    /**
     * Get all active airports
     */
    public function index(Request $request)
    {
        $airports = Airport::active()
            ->orderBy('country_code')
            ->orderBy('city_name_en')
            ->get();

        return ApiResponse::success('Airports retrieved successfully', $airports);
    }

    /**
     * Search airports by IATA code, city name, or airport name
     */
    public function search(Request $request)
    {
        $request->validate([
            'q' => 'required|string|min:1|max:100',
            'country_code' => 'nullable|string|max:2',
        ]);

        $query = Airport::active()->search($request->q);

        if ($request->has('country_code')) {
            $query->byCountry($request->country_code);
        }

        $airports = $query->orderBy('city_name_en')
            ->limit(20)
            ->get();

        return ApiResponse::success('Airport search results', $airports);
    }

    /**
     * Get airport by IATA code
     */
    public function getByIata(Request $request)
    {
        $request->validate([
            'code' => 'required|string|max:4',
        ]);

        $airport = Airport::active()
            ->where('iata_code', strtoupper($request->code))
            ->first();

        if (!$airport) {
            return ApiResponse::error('Airport not found', null, 404);
        }

        return ApiResponse::success('Airport retrieved successfully', $airport);
    }

    /**
     * Get popular airports (most used)
     */
    public function popular(Request $request)
    {
        $request->validate([
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $limit = $request->get('limit', 10);

        // Return airports from popular destinations
        // You can customize this based on your business needs
        $popularIataCodes = ['CAI', 'JED', 'RUH', 'KWI', 'DXB', 'DOH', 'IST', 'LHR', 'CDG', 'JFK'];

        $airports = Airport::active()
            ->whereIn('iata_code', $popularIataCodes)
            ->orderByRaw("FIELD(iata_code, '" . implode("','", $popularIataCodes) . "')")
            ->limit($limit)
            ->get();

        return ApiResponse::success('Popular airports retrieved successfully', $airports);
    }

    /**
     * Get grouped airports by country
     */
    public function groupedByCountry(Request $request)
    {
        $airports = Airport::active()
            ->orderBy('country_code')
            ->orderBy('city_name_en')
            ->get()
            ->groupBy('country_code');

        return ApiResponse::success('Airports grouped by country', $airports);
    }
}
