<?php

namespace App\Http\Controllers\Api\V1\Bus;

use App\Enums\AccountType;
use App\Enums\BusBookingStatus;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class BusDashboardController extends Controller
{
    /**
     * Canonical Bus currency (EGP-only contract — Phase 3).
     */
    private const BUS_CURRENCY = 'EGP';

    public function index(Request $request): JsonResponse
    {
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        // 1. Monthly Revenue (exclude cancelled / refunded)
        //
        // EGP-only contract: every Bus booking is EGP, so the dashboard
        // sums `total_price` directly. The previous code grouped by
        // `currency` and FX-converted each group via CurrencyService;
        // that helper is no longer invoked anywhere in the Bus module.
        $monthlyRevenue = (float) BusBooking::query()
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->whereNotIn('status', [
                BusBookingStatus::Cancelled->value,
                BusBookingStatus::Refunded->value,
                BusBookingStatus::PartiallyRefunded->value,
            ])
            ->where('currency', self::BUS_CURRENCY)
            ->sum('total_price');

        // 2. Total Bookings
        $totalBookings = BusBooking::count();

        // 3. Accounts Balances (Cashboxes, Banks, Wallets) for Bus Module.
        //
        // Bus module shares the office-division cashbox per the
        // AccountModuleContract (bus/fawry/online/wallet_transfer). Only
        // EGP accounts are summed (EGP-only contract).
        $accounts = Account::query()
            ->where('is_active', true)
            ->whereIn('module_type', ['bus', 'office'])
            ->where('currency', self::BUS_CURRENCY)
            ->get(['type', 'balance']);

        $cashboxes = $accounts->filter(fn ($a) => ($a->type instanceof \BackedEnum ? $a->type->value : $a->type) === AccountType::Cashbox->value);
        $banks = $accounts->filter(fn ($a) => ($a->type instanceof \BackedEnum ? $a->type->value : $a->type) === AccountType::Bank->value);
        $wallets = $accounts->filter(fn ($a) => ($a->type instanceof \BackedEnum ? $a->type->value : $a->type) === AccountType::Wallet->value);

        $cashboxBalance = $cashboxes->sum('balance');
        $bankBalance = $banks->sum('balance');
        $walletBalance = $wallets->sum('balance');

        // 4. Company Debts (Sum of balances from linked accounts).
        //
        // EGP-only contract: supplier AR accounts are always EGP, so we
        // sum balances directly. The previous code grouped by `currency`
        // and FX-converted each group; that helper is no longer invoked.
        $totalCompanyDebt = (float) Account::query()
            ->whereIn('id', BusCompany::query()->whereNotNull('account_id')->pluck('account_id'))
            ->where('currency', self::BUS_CURRENCY)
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
                    'customer' => [
                        'name' => $booking->customer?->full_name
                                  ?? $booking->customer_name
                                  ?? '—',
                    ],
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
