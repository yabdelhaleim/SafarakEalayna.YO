<?php

namespace App\Http\Controllers\Api\V1\Bus;

use App\Enums\AccountType;
use App\Enums\BusBookingStatus;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusInventory;
use App\Models\Bus\BusCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class BusDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        // 1. Monthly Revenue (exclude cancelled / refunded)
        $monthlyRevenue = (float) BusBooking::query()
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->whereNotIn('status', [
                BusBookingStatus::Cancelled->value,
                BusBookingStatus::Refunded->value,
                BusBookingStatus::PartiallyRefunded->value,
            ])
            ->sum('total_price');

        // 2. Total Bookings
        $totalBookings = BusBooking::count();

        // 3. Accounts Balances (Cashboxes, Banks, Wallets) for Bus Module
        $accounts = Account::query()
            ->where('is_active', true)
            ->where('module_type', 'bus')
            ->get(['type', 'balance']);

        $cashboxes = $accounts->filter(fn($a) => ($a->type instanceof \BackedEnum ? $a->type->value : $a->type) === AccountType::Cashbox->value);
        $banks = $accounts->filter(fn($a) => ($a->type instanceof \BackedEnum ? $a->type->value : $a->type) === AccountType::Bank->value);
        $wallets = $accounts->filter(fn($a) => ($a->type instanceof \BackedEnum ? $a->type->value : $a->type) === AccountType::Wallet->value);

        $cashboxBalance = $cashboxes->sum('balance');
        $bankBalance = $banks->sum('balance');
        $walletBalance = $wallets->sum('balance');

        // 4. Company Debts (Sum of balances from linked accounts)
        $totalCompanyDebt = Account::query()
            ->whereIn('id', BusCompany::query()->whereNotNull('account_id')->pluck('account_id'))
            ->sum('balance');

        // 5. Recent Bookings (Limit 10)
        $recentBookings = BusBooking::query()
            ->with(['customer', 'inventory.company'])
            ->latest()
            ->limit(10)
            ->get()
            ->map(function ($booking) {
                return [
                    'id' => $booking->id,
                    'booking_number' => $booking->booking_number,
                    'customer' => ['name' => $booking->customer?->name ?? 'غير محدد'],
                    'status' => $booking->status,
                    'total_price' => $booking->total_price,
                    'paid_amount' => $booking->paid_amount,
                    'created_at' => $booking->created_at,
                    'route' => $booking->inventory?->route,
                    'company' => $booking->inventory?->company?->name,
                ];
            });

        return ApiResponse::success('تم جلب بيانات لوحة تحكم الباصات.', [
            'stats' => [
                'monthly_revenue' => $monthlyRevenue,
                'total_bookings' => $totalBookings,
                'cashboxes' => [
                    'count' => $cashboxes->count(),
                    'balance' => $cashboxBalance,
                ],
                'banks' => [
                    'count' => $banks->count(),
                    'balance' => $bankBalance,
                ],
                'wallets' => [
                    'count' => $wallets->count(),
                    'balance' => $walletBalance,
                ],
            ],
            'recent_bookings' => $recentBookings,
            'liquidity' => [
                'total' => $cashboxBalance + $bankBalance + $walletBalance,
            ],
            'total_company_debt' => abs($totalCompanyDebt),
        ]);
    }
}
