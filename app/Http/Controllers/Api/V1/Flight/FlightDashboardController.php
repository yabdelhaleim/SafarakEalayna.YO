<?php

namespace App\Http\Controllers\Api\V1\Flight;

use App\Enums\AccountType;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class FlightDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        // 1. Monthly Revenue — exclude cancelled / refunded bookings
        $monthlyRevenue = FlightBooking::query()
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->whereNotIn('status', [
                \App\Enums\FlightBookingStatus::CANCELLED->value,
                \App\Enums\FlightBookingStatus::REFUNDED->value,
            ])
            ->sum('selling_price');

        // 2. Total Bookings
        $totalBookings = FlightBooking::count();

        // 3. Accounts Balances (Cashboxes, Banks, Wallets)
        $accounts = Account::query()
            ->where('is_active', true)
            ->where('module_type', 'flights')
            ->get(['type', 'balance', 'currency']);

        $treasuryService = app(\App\Services\Finance\TreasuryService::class);

        // Safe enum comparison
        $cashboxes = $accounts->filter(fn ($a) => in_array(($a->type instanceof \BackedEnum ? $a->type->value : $a->type), [AccountType::Cashbox->value, AccountType::Treasury->value], true));
        $banks = $accounts->filter(fn ($a) => in_array(($a->type instanceof \BackedEnum ? $a->type->value : $a->type), [AccountType::Bank->value, AccountType::Post->value], true));
        $wallets = $accounts->filter(fn ($a) => ($a->type instanceof \BackedEnum ? $a->type->value : $a->type) === AccountType::Wallet->value);

        $cashboxCount = $cashboxes->count();
        $cashboxBalance = $cashboxes->sum(function ($a) use ($treasuryService) {
            $rate = $treasuryService->getAveragePurchaseRate($a->currency);
            return (float) $a->balance * $rate;
        });

        $bankCount = $banks->count();
        $bankBalance = $banks->sum(function ($a) use ($treasuryService) {
            $rate = $treasuryService->getAveragePurchaseRate($a->currency);
            return (float) $a->balance * $rate;
        });

        $walletCount = $wallets->count();
        $walletBalance = $wallets->sum(function ($a) use ($treasuryService) {
            $rate = $treasuryService->getAveragePurchaseRate($a->currency);
            return (float) $a->balance * $rate;
        });

        // 4. System & Carrier Liquidity — مقسّم حسب العملة (balance الفعلي فقط، بدون credit_limit)
        $systems = FlightSystem::query()->where('is_active', true)->get(['currency', 'balance', 'credit_limit']);
        $carriers = FlightCarrier::query()->where('is_active', true)->get(['currency', 'balance', 'credit_limit']);

        $liquidityByCurrency = [];

        foreach ($systems as $s) {
            $cur = strtoupper((string) $s->currency);
            $liquidityByCurrency[$cur]['systems_balance'] = ($liquidityByCurrency[$cur]['systems_balance'] ?? 0) + (float) $s->balance;
            $liquidityByCurrency[$cur]['systems_credit_limit'] = ($liquidityByCurrency[$cur]['systems_credit_limit'] ?? 0) + (float) $s->credit_limit;
        }
        foreach ($carriers as $c) {
            $cur = strtoupper((string) $c->currency);
            $liquidityByCurrency[$cur]['carriers_balance'] = ($liquidityByCurrency[$cur]['carriers_balance'] ?? 0) + (float) $c->balance;
            $liquidityByCurrency[$cur]['carriers_credit_limit'] = ($liquidityByCurrency[$cur]['carriers_credit_limit'] ?? 0) + (float) $c->credit_limit;
        }
        foreach ($accounts as $a) {
            $cur = strtoupper((string) ($a->currency ?? 'EGP'));
            $liquidityByCurrency[$cur]['accounts_balance'] = ($liquidityByCurrency[$cur]['accounts_balance'] ?? 0) + (float) $a->balance;
        }

        $liquiditySummary = [];
        foreach ($liquidityByCurrency as $cur => $vals) {
            $sb = $vals['systems_balance'] ?? 0;
            $sl = $vals['systems_credit_limit'] ?? 0;
            $cb = $vals['carriers_balance'] ?? 0;
            $cl = $vals['carriers_credit_limit'] ?? 0;
            $ab = $vals['accounts_balance'] ?? 0;

            $liquiditySummary[] = [
                'currency' => $cur,
                'systems_balance' => round($sb, 2),
                'systems_credit' => round($sl, 2),
                'carriers_balance' => round($cb, 2),
                'carriers_credit' => round($cl, 2),
                'accounts_balance' => round($ab, 2),
                'total_actual' => round($sb + $cb + $ab, 2),       // الرصيد الفعلي
                'total_available' => round($sb + $sl + $cb + $cl + $ab, 2), // الفعلي + الائتمان
            ];
        }

        usort($liquiditySummary, fn ($a, $b) => $a['currency'] === 'EGP' ? -1 : ($b['currency'] === 'EGP' ? 1 : strcmp($a['currency'], $b['currency'])));

        // الإجمالي المحوّل للجنيه المصري بالكامل للبطاقة الرئيسية
        $totalEgpActual = collect($liquiditySummary)->sum(function ($row) use ($treasuryService) {
            $rate = $treasuryService->getAveragePurchaseRate($row['currency']);
            return (float) $row['total_actual'] * $rate;
        });

        // 5. Recent Bookings (Limit 10)
        $recentBookings = FlightBooking::query()
            ->with([
                'customer:id,full_name',
                'flightSystem:id,name',
                'flightCarrier:id,name',
            ])
            ->latest()
            ->limit(10)
            ->get()
            ->map(function ($booking) {
                return [
                    'id' => $booking->id,
                    'booking_number' => $booking->booking_number,
                    'customer' => [
                        'name' => trim((string) ($booking->customer?->full_name ?? '')) ?: '—',
                    ],
                    'pnr'    => $booking->pnr,
                    'status' => $booking->status instanceof \App\Enums\FlightBookingStatus
                        ? $booking->status->value
                        : $booking->status,
                    'status_label' => $booking->status instanceof \App\Enums\FlightBookingStatus
                        ? $booking->status->label()
                        : $booking->status,
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
                'total' => $totalEgpActual,        // EGP فقط — الرصيد الفعلي
                'by_currency' => $liquiditySummary,      // تفصيل حسب العملة
                'note' => 'الإجمالي يعكس الرصيد الفعلي EGP فقط. العملات الأخرى موضّحة في by_currency.',
            ],
        ]);
    }
}
