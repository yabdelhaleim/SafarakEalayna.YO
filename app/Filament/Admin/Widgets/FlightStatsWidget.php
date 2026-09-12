<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Account;
use App\Models\Flight\FlightBooking;
use App\Services\Reports\ProfitLossReportService;
use App\Support\Finance\AccountModuleDivision;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FlightStatsWidget extends BaseWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        // Liquidity accounts for the tourism division carry module_type
        // = 'tourism' (see AccountModuleContract::LIQUIDITY_TYPES). The
        // previous `where('module_type','flights')` only matched
        // type='customer' AR accounts whose module_type IS 'flights',
        // so "إجمالي أرصدة الطيران" was always wrong (under-reported).
        // Use the canonical tourism-division module_type list so we
        // include division-unified vaults (cashboxes named for flight)
        // AND specific 'flights'/customer AR rows.
        $tourismModuleTypes = AccountModuleDivision::TOURISM;

        $totalBalance = (float) Account::query()
            ->where('is_active', true)
            ->whereIn('module_type', $tourismModuleTypes)
            ->where(function ($q): void {
                // Only show actual liquidity (cashbox/wallet/bank) plus
                // internal clearing lines — leave customer/supplier AR
                // rows for the customer ledger card.
                $q->whereIn('type', AccountModuleDivision::LIQUIDITY_TYPES)
                    ->orWhereIn('type', ['expense', 'revenue', 'liability', 'owner']);
            })
            ->sum('balance');

        $totalBookings = FlightBooking::whereNull('deleted_at')->count();

        // Revenue must follow the double-entry P&L (cash-basis), not the
        // raw selling_price column. selling_price counts cancelled /
        // refund-pending bookings; the P&L service correctly nets out
        // cancellations and refunds. This matches the operator-visible
        // meaning of "إيرادات الشهر".
        $now = now();
        $plService = app(ProfitLossReportService::class);

        $revenueThisMonth = 0.0;
        $breakdown = $plService->moduleBreakdown([
            'from_date' => $now->copy()->startOfMonth()->toDateString(),
            'to_date'   => $now->copy()->endOfMonth()->toDateString(),
            'category'  => 'tourism',
        ]);
        foreach ($breakdown['by_module'] ?? [] as $row) {
            if ($this->normalizeModule($row['module'] ?? '') === 'flight') {
                $revenueThisMonth = (float) ($row['income'] ?? 0);
                break;
            }
        }

        $dailyBookings = $this->dailyBookingCounts();
        $dailyRevenue = $this->dailyRevenueTotals();

        return [
            Stat::make('إجمالي أرصدة الطيران', number_format($totalBalance, 2).' ج.م')
                ->description('السيولة + التسويات لقسم السياحة')
                ->descriptionIcon('heroicon-o-banknotes')
                ->color('primary')
                ->chart($dailyRevenue)
                ->extraAttributes([
                    'class' => 'hover:scale-105 transition-transform duration-300',
                ]),

            Stat::make('حجوزات الطيران', number_format($totalBookings))
                ->description('إجمالي الحجوزات النشطة')
                ->descriptionIcon('heroicon-o-paper-airplane')
                ->color('success')
                ->chart($dailyBookings)
                ->extraAttributes([
                    'class' => 'hover:scale-105 transition-transform duration-300',
                ]),

            Stat::make('إيرادات الشهر', number_format($revenueThisMonth, 2).' ج.م')
                ->description('صافي إيرادات الطيران (دفتر الأستاذ)')
                ->descriptionIcon('heroicon-o-arrow-trending-up')
                ->color('warning')
                ->chart($dailyRevenue)
                ->extraAttributes([
                    'class' => 'hover:scale-105 transition-transform duration-300',
                ]),
        ];
    }

    /**
     * @return array<int, int>
     */
    private function dailyBookingCounts(int $days = 7): array
    {
        return collect(range($days - 1, 0))
            ->map(fn (int $daysAgo): int => FlightBooking::query()
                ->whereNull('deleted_at')
                ->whereDate('created_at', now()->subDays($daysAgo))
                ->count())
            ->all();
    }

    /**
     * @return array<int, float>
     */
    private function dailyRevenueTotals(int $days = 7): array
    {
        $plService = app(ProfitLossReportService::class);

        return collect(range($days - 1, 0))
            ->map(function (int $daysAgo) use ($plService): float {
                $date = now()->subDays($daysAgo);
                $breakdown = $plService->moduleBreakdown([
                    'from_date' => $date->copy()->toDateString(),
                    'to_date'   => $date->copy()->toDateString(),
                    'category'  => 'tourism',
                ]);

                foreach ($breakdown['by_module'] ?? [] as $row) {
                    if ($this->normalizeModule($row['module'] ?? '') === 'flight') {
                        return (float) ($row['income'] ?? 0);
                    }
                }

                return 0.0;
            })
            ->all();
    }

    private function normalizeModule(string $module): string
    {
        $m = strtolower(trim($module));

        return match ($m) {
            'flights' => 'flight',
            'visas' => 'visa',
            'hajj', 'umrah', 'hajj_umra' => 'hajj_umra',
            default => $m,
        };
    }
}
