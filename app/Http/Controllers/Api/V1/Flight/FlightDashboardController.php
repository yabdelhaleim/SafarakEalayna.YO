<?php

namespace App\Http\Controllers\Api\V1\Flight;

use App\Enums\AccountType;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\FlightCarrier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class FlightDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        // 1. Monthly Revenue
        $monthlyRevenue = FlightBooking::query()
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->sum('selling_price');

        // 2. Total Bookings
        $totalBookings = FlightBooking::count();

        // 3. Accounts Balances (Cashboxes, Banks, Wallets)
        $accounts = Account::query()
            ->where('is_active', true)
            ->where('module_type', 'flights')
            ->get(['type', 'balance']);

        // Safe enum comparison
        $cashboxes = $accounts->filter(fn($a) => ($a->type instanceof \BackedEnum ? $a->type->value : $a->type) === AccountType::Cashbox->value);
        $banks = $accounts->filter(fn($a) => ($a->type instanceof \BackedEnum ? $a->type->value : $a->type) === AccountType::Bank->value);
        $wallets = $accounts->filter(fn($a) => ($a->type instanceof \BackedEnum ? $a->type->value : $a->type) === AccountType::Wallet->value);

        $cashboxCount = $cashboxes->count();
        $cashboxBalance = $cashboxes->sum('balance');

        $bankCount = $banks->count();
        $bankBalance = $banks->sum('balance');

        $walletCount = $wallets->count();
        $walletBalance = $wallets->sum('balance');

        // 4. System Liquidity
        $totalLiquidity = FlightSystem::query()->sum('balance');
        $totalCarrierLiquidity = FlightCarrier::query()->sum('balance');

        // 5. Recent Bookings (Limit 10)
        $recentBookings = FlightBooking::query()
            ->with(['customer', 'flightSystem', 'flightCarrier'])
            ->latest()
            ->limit(10)
            ->get()
            ->map(function ($booking) {
                return [
                    'id' => $booking->id,
                    'booking_number' => $booking->booking_number,
                    'customer' => ['name' => $booking->customer?->name ?? 'غير محدد'],
                    'pnr' => $booking->pnr,
                    'status' => $booking->status,
                    'pricing' => [
                        'sellingPrice' => $booking->selling_price,
                        'profit' => $booking->profit,
                        'currency' => $booking->currency,
                    ],
                    'created_at' => $booking->created_at,
                    'flight_system' => $booking->flightSystem ? ['name' => $booking->flightSystem->name] : null,
                    'flight_carrier' => $booking->flightCarrier ? ['name' => $booking->flightCarrier->name] : null,
                    'airline_name' => $booking->airline_name,
                    'from_airport' => $booking->from_airport,
                    'to_airport' => $booking->to_airport,
                ];
            });

        return ApiResponse::success('Flight dashboard data fetched', [
            'stats' => [
                'monthly_revenue' => $monthlyRevenue,
                'total_bookings' => $totalBookings,
                'cashboxes' => [
                    'count' => $cashboxCount,
                    'balance' => $cashboxBalance,
                ],
                'banks' => [
                    'count' => $bankCount,
                    'balance' => $bankBalance,
                ],
                'wallets' => [
                    'count' => $walletCount,
                    'balance' => $walletBalance,
                ],
            ],
            'recent_bookings' => $recentBookings,
            'liquidity' => [
                'total' => $totalLiquidity + $totalCarrierLiquidity + $cashboxBalance + $bankBalance + $walletBalance,
            ]
        ]);
    }
}