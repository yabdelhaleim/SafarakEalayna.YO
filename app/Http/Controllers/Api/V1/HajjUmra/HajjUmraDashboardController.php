<?php

namespace App\Http\Controllers\Api\V1\HajjUmra;

use App\Enums\AccountType;
use App\Enums\HajjUmraStatus;
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

        // استبعاد الحجوزات الملغاة والمستردة من إيراد الشهر — يطابق Flight dashboard
        $excludedStatuses = [
            HajjUmraStatus::Cancelled->value,
            HajjUmraStatus::Refunded->value,
        ];

        $monthlyRevenue = (float) HajjUmraBooking::query()
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->whereNotIn('status', $excludedStatuses)
            ->selectRaw('COALESCE(SUM(selling_price + COALESCE(companion_selling_price, 0) + COALESCE(accommodation_extra_charge, 0)), 0) as total')
            ->value('total');

        $totalBookings = HajjUmraBooking::query()->count();

        // استخدام AccountModuleDivision::TOURISM لضمان التقاط كل الحسابات السياحية
        // (tourism, flights, hajj_umra, visas) — يطابق Flight dashboard
        $accounts = Account::query()
            ->where('is_active', true)
            ->whereIn('module_type', \App\Support\Finance\AccountModuleDivision::TOURISM)
            // نقتصر على liquidity types فقط (cashbox, bank, wallet) — لا نضع clearing/supplier/customer في الإجمالي
            ->whereIn('type', [AccountType::Cashbox->value, AccountType::Bank->value, AccountType::Wallet->value])
            ->get(['type', 'balance', 'currency']);

        $treasuryService = app(\App\Services\Finance\TreasuryService::class);

        // Safe enum comparison — one type per bucket so bank rows are not double-counted
        $cashboxes = $accounts->filter(fn ($a) => ($a->type instanceof \BackedEnum ? $a->type->value : $a->type) === AccountType::Cashbox->value);
        $banks = $accounts->filter(fn ($a) => ($a->type instanceof \BackedEnum ? $a->type->value : $a->type) === AccountType::Bank->value);
        $wallets = $accounts->filter(fn ($a) => ($a->type instanceof \BackedEnum ? $a->type->value : $a->type) === AccountType::Wallet->value);

        // تحويل الأرصدة إلى EGP باستخدام متوسط سعر الشراء — يطابق Flight dashboard
        $cashboxBalance = $cashboxes->sum(function ($a) use ($treasuryService) {
            $rate = $treasuryService->getAveragePurchaseRate($a->currency);
            return (float) $a->balance * $rate;
        });
        $bankBalance = $banks->sum(function ($a) use ($treasuryService) {
            $rate = $treasuryService->getAveragePurchaseRate($a->currency);
            return (float) $a->balance * $rate;
        });
        $walletBalance = $wallets->sum(function ($a) use ($treasuryService) {
            $rate = $treasuryService->getAveragePurchaseRate($a->currency);
            return (float) $a->balance * $rate;
        });

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
