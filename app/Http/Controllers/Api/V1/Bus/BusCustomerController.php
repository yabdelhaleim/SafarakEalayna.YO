<?php

namespace App\Http\Controllers\Api\V1\Bus;

use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class BusCustomerController extends Controller
{
    /**
     * Display a listing of bus customers with their total debt.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 30), 100);
        $search = $request->query('search');

        $query = Customer::query()
            ->whereHas('busBookings', function ($q) {
                $q->whereNotIn('status', ['cancelled']);
            })
            ->withCount(['busBookings as total_bus_bookings' => function ($q) {
                $q->whereNotIn('status', ['cancelled']);
            }])
            ->withSum(['busBookings as total_bus_amount' => function ($q) {
                $q->whereNotIn('status', ['cancelled']);
            }], 'total_price')
            ->withSum(['busBookings as total_bus_paid' => function ($q) {
                $q->whereNotIn('status', ['cancelled']);
            }], 'paid_amount');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $customers = $query->latest()->paginate($perPage);

        // Map data to calculate remaining debt
        $customers->getCollection()->transform(function ($customer) {
            $totalAmount = (float) $customer->total_bus_amount;
            $totalPaid = (float) $customer->total_bus_paid;
            
            $customer->bus_remaining_debt = max(0, $totalAmount - $totalPaid);
            
            return [
                'id' => $customer->id,
                'full_name' => $customer->full_name,
                'phone' => $customer->phone,
                'total_bus_bookings' => $customer->total_bus_bookings,
                'total_bus_amount' => $totalAmount,
                'total_bus_paid' => $totalPaid,
                'bus_remaining_debt' => $customer->bus_remaining_debt,
            ];
        });

        return ApiResponse::success('Bus customers retrieved.', [
            'customers' => $customers
        ]);
    }
}
