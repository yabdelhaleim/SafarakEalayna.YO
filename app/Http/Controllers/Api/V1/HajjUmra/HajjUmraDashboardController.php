<?php

namespace App\Http\Controllers\Api\V1\HajjUmra;

use App\Enums\AccountType;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\HajjUmraBooking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class HajjUmraDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        $monthlyRevenue = (float) HajjUmraBooking::query()
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->where('status', '!=', 'cancelled')
            ->selectRaw('COALESCE(SUM(selling_price + COALESCE(companion_selling_price, 0) + COALESCE(accommodation_extra_charge, 0)), 0) as total')
            ->value('total');

        $totalBookings = HajjUmraBooking::query()->count();

        $accounts = Account::query()
            ->where('is_active', true)
            ->where('module_type', 'hajj_umra')
            ->get(['type', 'balance']);

        $cashboxes = $accounts->filter(fn ($a) => ($a->type instanceof \BackedEnum ? $a->type->value : $a->type) === AccountType::Cashbox->value);
        $banks = $accounts->filter(fn ($a) => ($a->type instanceof \BackedEnum ? $a->type->value : $a->type) === AccountType::Bank->value);
        $wallets = $accounts->filter(fn ($a) => ($a->type instanceof \BackedEnum ? $a->type->value : $a->type) === AccountType::Wallet->value);

        $cashboxBalance = (float) $cashboxes->sum('balance');
        $bankBalance = (float) $banks->sum('balance');
        $walletBalance = (float) $wallets->sum('balance');

        $recentBookings = HajjUmraBooking::query()
            ->with(['customer', 'program'])
            ->latest()
            ->limit(10)
            ->get()
            ->map(function (HajjUmraBooking $b) {
                return [
                    'id' => $b->id,
                    'customer' => [
                        'name' => $b->customer?->full_name ?? $b->customer?->name ?? 'غير محدد',
                        'phone' => $b->customer?->phone,
                    ],
                    'program' => $b->program ? [
                        'id' => $b->program->id,
                        'program_name' => $b->program->program_name,
                        'program_type' => $b->program->program_type,
                    ] : null,
                    'status' => $b->status instanceof \BackedEnum ? $b->status->value : $b->status,
                    'selling_price' => (float) $b->selling_price,
                    'profit' => (float) $b->profit,
                    'currency' => $b->currency,
                    'created_at' => $b->created_at,
                ];
            });

        return ApiResponse::success('Hajj & Umra dashboard data fetched', [
            'stats' => [
                'monthly_revenue' => (float) $monthlyRevenue,
                'total_bookings' => (int) $totalBookings,
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
        ]);
    }
}
