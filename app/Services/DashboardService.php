<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Enums\BusBookingStatus;
use App\Models\Account;
use App\Models\Bus\BusBooking;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Invoice;
use App\Models\Online\OnlineTransaction;
use App\Services\Reports\ReportFinanceService;
use App\Support\Finance\AccountModuleDivision;
use Carbon\Carbon;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardService
{
    public function getOverview(): array
    {
        $today = now()->toDateString();

        return [
            'today' => [
                'flights' => FlightBooking::whereDate('created_at', $today)->count(),
                'buses' => BusBooking::whereDate('created_at', $today)->count(),
                'services' => $this->countServiceOrders(fn ($q) => $q->whereDate('created_at', $today)),
                'online' => OnlineTransaction::whereDate('created_at', $today)->count(),
            ],
            'this_month' => [
                'flights' => FlightBooking::whereYear('created_at', now()->year)
                    ->whereMonth('created_at', now()->month)->count(),
                'buses' => BusBooking::whereYear('created_at', now()->year)
                    ->whereMonth('created_at', now()->month)->count(),
                'services' => $this->countServiceOrders(fn ($q) => $q->whereYear('created_at', now()->year)
                    ->whereMonth('created_at', now()->month)),
                'online' => OnlineTransaction::whereYear('created_at', now()->year)
                    ->whereMonth('created_at', now()->month)->count(),
            ],
            'total_customers' => Customer::count(),
            'total_employees' => Employee::count(),
            'pending_invoices' => Invoice::whereIn('status', ['sent', 'partially_paid'])->count(),
            'overdue_invoices' => Invoice::where('status', 'overdue')->count(),
        ];
    }

    public function getFinancialStats(string $from, string $to): array
    {
        $summary = app(ReportFinanceService::class)->getFinancialSummary([
            'from_date' => $from,
            'to_date' => $to,
        ]);

        $income = (float) ($summary['total_income'] ?? 0);
        $expense = (float) ($summary['total_expense'] ?? 0);
        $netProfit = (float) ($summary['net_profit'] ?? 0);

        $transactionsCount = (int) DB::table('transactions')
            ->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->count();

        return [
            'total_income' => round($income, 2),
            'total_cogs' => round((float) ($summary['total_cogs'] ?? 0), 2),
            'total_operating_expenses' => round((float) ($summary['total_operating_expenses'] ?? 0), 2),
            'total_expense' => round($expense, 2),
            'net_profit' => round($netProfit, 2),
            'profit_margin' => $income > 0 ? round(($netProfit / $income) * 100, 2) : 0,
            'transactions_count' => $transactionsCount,
        ];
    }

    public function getBookingsStats(string $from, string $to): array
    {
        $fromDt = $from.' 00:00:00';
        $toDt = $to.' 23:59:59';

        return [
            'flights' => [
                'total' => FlightBooking::whereBetween('created_at', [$fromDt, $toDt])->count(),
                'confirmed' => FlightBooking::whereBetween('created_at', [$fromDt, $toDt])
                    ->where('status', 'CONFIRMED')->count(),
            ],
            'buses' => [
                'total' => BusBooking::whereBetween('created_at', [$fromDt, $toDt])->count(),
                'paid' => BusBooking::whereBetween('created_at', [$fromDt, $toDt])
                    ->where('status', 'paid')->count(),
            ],
            'services' => [
                'total' => $this->countServiceOrders(fn ($q) => $q->whereBetween('created_at', [$fromDt, $toDt])),
                'completed' => $this->countServiceOrders(fn ($q) => $q->whereBetween('created_at', [$fromDt, $toDt])
                    ->where('status', 'completed')),
            ],
            'online' => [
                'total' => OnlineTransaction::whereBetween('created_at', [$fromDt, $toDt])->count(),
                'success' => OnlineTransaction::whereBetween('created_at', [$fromDt, $toDt])
                    ->where('status', 'success')->count(),
            ],
        ];
    }

    public function getTopCustomers(int $limit = 5): array
    {
        $customers = Customer::withCount(['flightBookings'])
            ->orderBy('flight_bookings_count', 'desc')
            ->limit($limit)
            ->get();

        return $customers->map(function ($customer) {
            return [
                'id' => $customer->id,
                'name' => $customer->full_name,
                'phone' => $customer->phone,
                'total_bookings' => $customer->flight_bookings_count,
            ];
        })->toArray();
    }

    public function getRecentActivities(int $limit = 10): array
    {
        $activities = collect();

        $flights = FlightBooking::with('customer')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn ($b) => [
                'type' => 'flight',
                'id' => $b->id,
                'customer' => $b->customer->full_name ?? 'N/A',
                'description' => "حجز طيران #{$b->booking_number} - {$b->from_airport} → {$b->to_airport}",
                'amount' => (float) $b->selling_price,
                'status' => $b->status,
                'time' => $b->created_at?->diffForHumans(),
                'created_at' => $b->created_at->format('Y-m-d H:i:s'),
            ]);

        $activities = $activities->concat($flights);

        // Try to get bus bookings, ignore if model has issues
        try {
            $buses = BusBooking::with('customer')
                ->orderBy('created_at', 'desc')
                ->limit(3)
                ->get()
                ->map(fn ($b) => [
                    'type' => 'bus',
                    'id' => $b->id,
                    'customer' => $b->customer->full_name ?? 'N/A',
                    'description' => "حجز باص #{$b->id}",
                    'amount' => (float) ($b->total_price ?? 0),
                    'status' => $b->status ?? 'pending',
                    'time' => $b->created_at?->diffForHumans(),
                    'created_at' => $b->created_at->format('Y-m-d H:i:s'),
                ]);
            $activities = $activities->concat($buses);
        } catch (\Exception $e) {
            // Skip if bus model has issues
        }

        $hasServiceOrdersTable = \Illuminate\Support\Facades\Cache::remember('schema_has_table_service_orders', 3600, function () {
            return Schema::hasTable('service_orders');
        });

        if ($hasServiceOrdersTable) {
            try {
                $q = DB::table('service_orders')
                    ->leftJoin('customers', 'service_orders.customer_id', '=', 'customers.id');
                
                $hasDeletedAt = \Illuminate\Support\Facades\Cache::remember('schema_has_column_service_orders_deleted_at', 3600, function () {
                    return Schema::hasColumn('service_orders', 'deleted_at');
                });

                if ($hasDeletedAt) {
                    $q->whereNull('service_orders.deleted_at');
                }
                $serviceRows = $q
                    ->orderByDesc('service_orders.created_at')
                    ->limit(3)
                    ->select(
                        'service_orders.id',
                        'service_orders.selling_price',
                        'service_orders.status',
                        'service_orders.created_at',
                        'customers.full_name as customer_full_name'
                    )
                    ->get();

                $services = $serviceRows->map(fn ($b) => [
                    'type' => 'service',
                    'id' => (int) $b->id,
                    'customer' => $b->customer_full_name ?? 'N/A',
                    'description' => "طلب خدمة #{$b->id}",
                    'amount' => (float) ($b->selling_price ?? 0),
                    'status' => $b->status ?? 'pending',
                    'time' => Carbon::parse($b->created_at)->diffForHumans(),
                    'created_at' => Carbon::parse($b->created_at)->format('Y-m-d H:i:s'),
                ]);
                $activities = $activities->concat($services);
            } catch (\Exception $e) {
                // Skip if service_orders query fails
            }
        }

        return $activities
            ->sortByDesc('created_at')
            ->take($limit)
            ->values()
            ->toArray();
    }

    public function getAlerts(): array
    {
        $alerts = [];

        // Overdue invoices
        $overdueInvoices = Invoice::where('status', 'overdue')->count();
        if ($overdueInvoices > 0) {
            $alerts[] = [
                'type' => 'warning',
                'message' => "{$overdueInvoices} فاتورة متأخرة",
                'priority' => 'high',
            ];
        }

        // Pending flight bookings
        $pendingFlights = FlightBooking::where('status', 'PENDING')->count();
        if ($pendingFlights > 0) {
            $alerts[] = [
                'type' => 'info',
                'message' => "{$pendingFlights} حجز طيران معلق",
                'priority' => 'medium',
            ];
        }

        return $alerts;
    }

    public function getFullDashboard(?string $dateFrom = null, ?string $dateTo = null, ?string $carrierId = null, ?string $systemType = null): array
    {
        $from = $dateFrom ?: now()->startOfMonth()->toDateString();
        $to = $dateTo ?: now()->endOfMonth()->toDateString();

        $base = [
            'overview' => $this->getOverview(),
            'financial' => $this->getFinancialStats($from, $to),
            'bookings' => $this->getBookingsStats($from, $to),
            'top_customers' => $this->getTopCustomers(),
            'recent_activities' => $this->getRecentActivities(),
            'alerts' => $this->getAlerts(),
        ];

        $airline = $this->buildAirlineOperationsDashboard($from, $to, $carrierId, $systemType);
        $busOps = $this->buildBusOperationsDashboard($from, $to);

        // Hajj Stats
        $hajjStats = \App\Models\HajjUmraBooking::whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->selectRaw("
                COUNT(*) as count,
                COALESCE(SUM(selling_price), 0) as revenue,
                COALESCE(SUM(profit), 0) as profit
            ")
            ->first();
        $hajjCount = (int) $hajjStats->count;
        $hajjRevenue = (float) $hajjStats->revenue;
        $hajjProfit = (float) $hajjStats->profit;

        // Online Stats
        $onlineStats = \App\Models\Online\OnlineTransaction::whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->selectRaw("
                COUNT(*) as count,
                COALESCE(SUM(selling_price), 0) as revenue,
                COALESCE(SUM(profit), 0) as profit
            ")
            ->first();
        $onlineCount = (int) $onlineStats->count;
        $onlineRevenue = (float) $onlineStats->revenue;
        $onlineProfit = (float) $onlineStats->profit;

        // Fawry Stats
        $fawryStats = \App\Models\Fawry\FawryTransaction::whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->selectRaw("
                COUNT(*) as count,
                COALESCE(SUM(selling_price), 0) as revenue,
                COALESCE(SUM(profit), 0) as profit
            ")
            ->first();
        $fawryCount = (int) $fawryStats->count;
        $fawryRevenue = (float) $fawryStats->revenue;
        $fawryProfit = (float) $fawryStats->profit;

        // Treasury: liquidity accounts only (exclude customer/supplier ledgers)
        $liquidityQuery = Account::query()->where('is_active', true);
        AccountModuleDivision::applyLiquidityTreasuryScope($liquidityQuery);
        $accounts = $liquidityQuery
            ->whereIn('type', AccountModuleDivision::LIQUIDITY_TYPES)
            ->get();

        $cashboxBalance = 0.0;
        $bankBalance = 0.0;
        $walletBalance = 0.0;

        foreach ($accounts as $acc) {
            $val = (float) $acc->balance;
            $type = $acc->type instanceof AccountType
                ? $acc->type
                : AccountType::tryFrom((string) $acc->type);

            match ($type) {
                AccountType::Bank => $bankBalance += $val,
                AccountType::Wallet => $walletBalance += $val,
                default => $cashboxBalance += $val,
            };
        }

        $totalBalance = $cashboxBalance + $bankBalance + $walletBalance;

        $tourismSummary = [
            'flights' => [
                'count' => $airline['kpis']['total_bookings'] ?? 0,
                'revenue' => $airline['kpis']['revenue'] ?? 0,
                'profit' => $airline['kpis']['net_profit'] ?? 0,
            ],
            'hajj' => [
                'count' => $hajjCount,
                'revenue' => $hajjRevenue,
                'profit' => $hajjProfit,
            ],
            'total_count' => ($airline['kpis']['total_bookings'] ?? 0) + $hajjCount,
            'total_revenue' => ($airline['kpis']['revenue'] ?? 0) + $hajjRevenue,
            'total_profit' => ($airline['kpis']['net_profit'] ?? 0) + $hajjProfit,
        ];

        $officeSummary = [
            'bus' => [
                'count' => $busOps['bus_kpis']['total_bookings'] ?? 0,
                'revenue' => $busOps['bus_kpis']['revenue'] ?? 0,
                'profit' => $busOps['bus_kpis']['net_profit'] ?? 0,
            ],
            'fawry' => [
                'count' => $fawryCount,
                'revenue' => $fawryRevenue,
                'profit' => $fawryProfit,
            ],
            'online' => [
                'count' => $onlineCount,
                'revenue' => $onlineRevenue,
                'profit' => $onlineProfit,
            ],
            'total_count' => ($busOps['bus_kpis']['total_bookings'] ?? 0) + $fawryCount + $onlineCount,
            'total_revenue' => ($busOps['bus_kpis']['revenue'] ?? 0) + $fawryRevenue + $onlineRevenue,
            'total_profit' => ($busOps['bus_kpis']['net_profit'] ?? 0) + $fawryProfit + $onlineProfit,
        ];

        $extra = [
            'tourism_summary' => $tourismSummary,
            'office_summary' => $officeSummary,
            'treasury_summary' => [
                'total' => $totalBalance,
                'cashbox' => $cashboxBalance,
                'bank' => $bankBalance,
                'wallet' => $walletBalance,
            ],
        ];

        return array_merge($base, $airline, $busOps, $extra);
    }

    /**
     * Vue dashboard: حجوزات الباصات بنفس فكرة نطاق التاريخ (بدون فلاتر شركة طيران).
     *
     * @return array<string, mixed>
     */
    protected function buildBusOperationsDashboard(string $from, string $to): array
    {
        $today = now()->toDateString();
        $yesterday = Carbon::parse($today)->subDay()->toDateString();

        $pct = fn (float $cur, float $prev) => $prev > 0 ? round((($cur - $prev) / $prev) * 100, 1) : ($cur > 0 ? 100.0 : 0.0);

        $bookingQuery = BusBooking::query()->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59']);

        // Merge range queries
        $rangeStats = (clone $bookingQuery)
            ->selectRaw("
                COUNT(*) as total_bookings,
                COALESCE(SUM(CASE WHEN status != ? THEN total_price ELSE 0 END), 0) as revenue,
                COALESCE(SUM(CASE WHEN status != ? THEN profit ELSE 0 END), 0) as profit,
                COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as cancelled
            ", [
                BusBookingStatus::Cancelled->value,
                BusBookingStatus::Cancelled->value,
                BusBookingStatus::Cancelled->value
            ])
            ->first();

        $totalBookingsRange = (int) $rangeStats->total_bookings;
        $revenueRange = (float) $rangeStats->revenue;
        $profitRange = (float) $rangeStats->profit;
        $cancelled = (int) $rangeStats->cancelled;

        $pendingPayments = (float) BusBooking::query()
            ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->where('status', '!=', BusBookingStatus::Cancelled->value)
            ->selectRaw('COALESCE(SUM(total_price - paid_amount), 0) as aggregate')
            ->value('aggregate');

        // Merge today stats query
        $todayStats = BusBooking::whereBetween('created_at', [$today . ' 00:00:00', $today . ' 23:59:59'])
            ->selectRaw("
                COUNT(*) as count,
                COALESCE(SUM(CASE WHEN status != ? THEN total_price ELSE 0 END), 0) as revenue,
                COALESCE(SUM(CASE WHEN status != ? THEN profit ELSE 0 END), 0) as profit
            ", [BusBookingStatus::Cancelled->value, BusBookingStatus::Cancelled->value])
            ->first();

        $todayBookings = (int) $todayStats->count;
        $todayRevenue = (float) $todayStats->revenue;
        $todayProfit = (float) $todayStats->profit;

        // Merge yesterday stats query
        $yesterdayStats = BusBooking::whereBetween('created_at', [$yesterday . ' 00:00:00', $yesterday . ' 23:59:59'])
            ->selectRaw("
                COUNT(*) as count,
                COALESCE(SUM(CASE WHEN status != ? THEN total_price ELSE 0 END), 0) as revenue,
                COALESCE(SUM(CASE WHEN status != ? THEN profit ELSE 0 END), 0) as profit
            ", [BusBookingStatus::Cancelled->value, BusBookingStatus::Cancelled->value])
            ->first();

        $yesterdayBookings = (int) $yesterdayStats->count;
        $yesterdayRevenue = (float) $yesterdayStats->revenue;
        $yesterdayProfit = (float) $yesterdayStats->profit;

        $activeCompanies = (int) BusBooking::query()
            ->whereBetween('bus_bookings.created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->join('bus_inventories', 'bus_bookings.inventory_id', '=', 'bus_inventories.id')
            ->selectRaw('COUNT(DISTINCT bus_inventories.company_id) as c')
            ->value('c');

        $companyRows = DB::table('bus_bookings')
            ->join('bus_inventories', 'bus_bookings.inventory_id', '=', 'bus_inventories.id')
            ->join('bus_companies', 'bus_inventories.company_id', '=', 'bus_companies.id')
            ->whereBetween('bus_bookings.created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->where('bus_bookings.status', '!=', BusBookingStatus::Cancelled->value)
            ->groupBy('bus_companies.id', 'bus_companies.name')
            ->selectRaw('bus_companies.id as company_id, bus_companies.name as company_name, COUNT(bus_bookings.id) as booking_count, SUM(bus_bookings.total_price) as revenue_sum, SUM(bus_bookings.profit) as profit_sum')
            ->orderByDesc('profit_sum')
            ->limit(8)
            ->get();

        $busCompanyPerformance = $companyRows->map(function ($r) {
            $rev = (float) $r->revenue_sum;
            $profit = (float) $r->profit_sum;

            return [
                'id' => (int) $r->company_id,
                'name' => $r->company_name,
                'bookings' => (int) $r->booking_count,
                'revenue' => $rev,
                'profit' => $profit,
                'profit_margin' => $rev > 0 ? round(($profit / $rev) * 100, 1) : 0,
            ];
        })->values()->all();

        $bookingsChart = [];
        $revenueChart = [];
        $start = Carbon::parse($from);
        $end = Carbon::parse($to);
        if ($end->lt($start)) {
            $end = $start->copy();
        }
        $days = min(14, $start->diffInDays($end) + 1);
        $busStats = BusBooking::whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count, SUM(total_price) as revenue, SUM(profit) as profit')
            ->where('status', '!=', BusBookingStatus::Cancelled->value)
            ->groupBy('date')
            ->get()
            ->keyBy('date');

        for ($i = 0; $i < $days; $i++) {
            $d = $start->copy()->addDays($i)->toDateString();
            Carbon::setLocale('ar');
            $label = Carbon::parse($d)->translatedFormat('D j M');
            
            $stat = $busStats->get($d);
            $cnt = $stat ? (int) $stat->count : 0;
            $rev = $stat ? (float) $stat->revenue : 0.0;
            $prof = $stat ? (float) $stat->profit : 0.0;

            $bookingsChart[] = ['label' => $label, 'count' => $cnt];
            $revenueChart[] = ['label' => $label, 'revenue' => $rev, 'profit' => $prof];
        }

        $topRoutes = DB::table('bus_bookings')
            ->join('bus_inventories', 'bus_bookings.inventory_id', '=', 'bus_inventories.id')
            ->whereBetween('bus_bookings.created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->where('bus_bookings.status', '!=', BusBookingStatus::Cancelled->value)
            ->whereNotNull('bus_inventories.route')
            ->groupBy('bus_inventories.route')
            ->selectRaw('bus_inventories.route as route, COUNT(bus_bookings.id) as c, SUM(bus_bookings.total_price) as revenue')
            ->orderByDesc('c')
            ->limit(6)
            ->get()
            ->map(fn ($r) => [
                'route' => $r->route,
                'bookings' => (int) $r->c,
                'revenue' => (float) $r->revenue,
            ])
            ->all();

        $busRecent = BusBooking::with(['customer', 'inventory'])
            ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->orderByDesc('created_at')
            ->limit(8)
            ->get()
            ->map(function (BusBooking $b) {
                $route = $b->inventory?->route ?? '';
                $cust = $b->customer?->full_name ?? '—';

                return [
                    'type' => 'bus',
                    'description' => "حجز باص #{$b->id} — {$route} — {$cust}",
                    'time' => $b->created_at?->diffForHumans(),
                ];
            })
            ->all();

        return [
            'bus_kpis' => [
                'today_bookings' => $todayBookings,
                'today_bookings_change' => $pct((float) $todayBookings, (float) $yesterdayBookings),
                'today_revenue' => $todayRevenue,
                'today_revenue_change' => $pct($todayRevenue, $yesterdayRevenue),
                'today_profit' => $todayProfit,
                'today_profit_change' => $pct($todayProfit, $yesterdayProfit),
                'active_companies' => $activeCompanies,
                'cancelled_bookings' => $cancelled,
                'cancellation_rate' => $totalBookingsRange > 0 ? round(($cancelled / $totalBookingsRange) * 100, 1) : 0,
                'total_bookings' => $totalBookingsRange,
                'revenue' => $revenueRange,
                'net_profit' => $profitRange,
                'pending_payments' => max(0, $pendingPayments),
            ],
            'bus_bookings_chart' => $bookingsChart,
            'bus_revenue_chart' => $revenueChart,
            'bus_company_performance' => $busCompanyPerformance,
            'bus_top_routes' => $topRoutes,
            'bus_recent_activity' => $busRecent,
        ];
    }

    /**
     * Data for the Vue airline operations dashboard (no mock values).
     */
    protected function buildAirlineOperationsDashboard(string $from, string $to, ?string $carrierId, ?string $systemType): array
    {
        $today = now()->toDateString();

        $bookingQuery = FlightBooking::query()->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59']);
        if ($carrierId) {
            $bookingQuery->where('flight_carrier_id', (int) $carrierId);
        }
        if ($systemType !== null && $systemType !== '') {
            if (is_numeric($systemType)) {
                $bookingQuery->where('flight_system_id', (int) $systemType);
            } else {
                $bookingQuery->where('system_type', $systemType);
            }
        }

        $rangeStats = (clone $bookingQuery)
            ->selectRaw("
                COUNT(*) as total_bookings,
                COALESCE(SUM(selling_price), 0) as revenue,
                COALESCE(SUM(profit), 0) as profit,
                COALESCE(SUM(CASE WHEN status = 'CANCELLED' THEN 1 ELSE 0 END), 0) as cancelled
            ")
            ->first();

        $totalBookingsRange = (int) $rangeStats->total_bookings;
        $revenueRange = (float) $rangeStats->revenue;
        $profitRange = (float) $rangeStats->profit;
        $cancelled = (int) $rangeStats->cancelled;

        $todayBookings = FlightBooking::whereBetween('created_at', [$today . ' 00:00:00', $today . ' 23:59:59'])
            ->when($carrierId, fn ($q) => $q->where('flight_carrier_id', (int) $carrierId))
            ->when($systemType !== null && $systemType !== '', fn ($q) => is_numeric($systemType) ? $q->where('flight_system_id', (int) $systemType) : $q->where('system_type', $systemType))
            ->count();

        $paidSub = '(SELECT COALESCE(SUM(amount),0) FROM flight_payments WHERE flight_payments.flight_booking_id = flight_bookings.id)';
        $outstanding = (float) DB::table('flight_bookings')
            ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->when($carrierId, fn ($q) => $q->where('flight_carrier_id', (int) $carrierId))
            ->when($systemType !== null && $systemType !== '', fn ($q) => is_numeric($systemType) ? $q->where('flight_system_id', (int) $systemType) : $q->where('system_type', $systemType))
            ->whereRaw("selling_price - {$paidSub} > 0.01")
            ->sum(DB::raw("selling_price - {$paidSub}"));

        $yesterday = Carbon::parse($today)->subDay()->toDateString();
        $todayBookingsYesterday = FlightBooking::whereBetween('created_at', [$yesterday . ' 00:00:00', $yesterday . ' 23:59:59'])->count();
        $pct = fn (float $cur, float $prev) => $prev > 0 ? round((($cur - $prev) / $prev) * 100, 1) : ($cur > 0 ? 100.0 : 0.0);

        // Merge today stats
        $todayStats = FlightBooking::whereBetween('created_at', [$today . ' 00:00:00', $today . ' 23:59:59'])
            ->selectRaw("
                COALESCE(SUM(selling_price), 0) as revenue,
                COALESCE(SUM(profit), 0) as profit
            ")
            ->first();
        $todayRevenue = (float) $todayStats->revenue;
        $todayProfit = (float) $todayStats->profit;

        // Merge yesterday stats
        $yesterdayStats = FlightBooking::whereBetween('created_at', [$yesterday . ' 00:00:00', $yesterday . ' 23:59:59'])
            ->selectRaw("
                COALESCE(SUM(selling_price), 0) as revenue,
                COALESCE(SUM(profit), 0) as profit
            ")
            ->first();
        $yesterdayRevenue = (float) $yesterdayStats->revenue;
        $yesterdayProfit = (float) $yesterdayStats->profit;

        $cancelled = (clone $bookingQuery)->where('status', 'CANCELLED')->count();

        $carriersQ = FlightCarrier::query()->with('system')->where('is_active', true);
        if ($carrierId) {
            $carriersQ->where('id', (int) $carrierId);
        }
        $carriers = $carriersQ->orderBy('name')->get();

        $systemsQ = FlightSystem::query()->where('is_active', true);
        if ($systemType !== null && $systemType !== '' && is_numeric($systemType)) {
            $systemsQ->where('id', (int) $systemType);
        }
        $systems = $systemsQ->orderBy('name')->get();

        $systemCards = $systems->map(fn (FlightSystem $s) => [
            'id' => 'sys_'.$s->id,
            'company_name' => $s->name,
            'system_name' => 'نظام رئيسي',
            'balance' => (float) $s->balance,
            'available_balance' => (float) $s->available_balance,
            'currency' => $s->currency,
            'is_active' => (bool) $s->is_active,
        ])->values()->all();

        $carrierCardsList = $carriers->map(fn (FlightCarrier $c) => [
            'id' => 'car_'.$c->id,
            'company_name' => $c->name,
            'system_name' => $c->system?->name,
            'balance' => (float) $c->balance,
            'available_balance' => (float) $c->available_balance,
            'currency' => $c->currency,
            'is_active' => (bool) $c->is_active,
        ])->values()->all();

        $carrierCards = array_merge($systemCards, $carrierCardsList);

        // Fetch all system stats in one query
        $systemStats = FlightBooking::query()
            ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->when($carrierId, fn ($b) => $b->where('flight_carrier_id', (int) $carrierId))
            ->groupBy('flight_system_id')
            ->selectRaw("
                flight_system_id,
                COUNT(*) as count,
                COALESCE(SUM(selling_price), 0) as revenue,
                COALESCE(SUM(profit), 0) as profit
            ")
            ->get()
            ->keyBy('flight_system_id');

        $systemPerformanceList = $systems->map(function (FlightSystem $s) use ($systemStats) {
            $stat = $systemStats->get($s->id);
            $bookings = $stat ? (int) $stat->count : 0;
            $revSum = $stat ? (float) $stat->revenue : 0.0;
            $profitSum = $stat ? (float) $stat->profit : 0.0;

            return [
                'id' => 'sys_'.$s->id,
                'name' => $s->name . ' (نظام)',
                'bookings' => $bookings,
                'balance' => (float) $s->balance,
                'profit' => $profitSum,
                'profit_margin' => $revSum > 0 ? round(($profitSum / $revSum) * 100, 1) : 0,
            ];
        });

        // Fetch all carrier stats in one query
        $carrierStats = FlightBooking::query()
            ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->when($systemType !== null && $systemType !== '', fn ($b) => is_numeric($systemType) ? $b->where('flight_system_id', (int) $systemType) : $b->where('system_type', $systemType))
            ->groupBy('flight_carrier_id')
            ->selectRaw("
                flight_carrier_id,
                COUNT(*) as count,
                COALESCE(SUM(selling_price), 0) as revenue,
                COALESCE(SUM(profit), 0) as profit
            ")
            ->get()
            ->keyBy('flight_carrier_id');

        $carrierPerformanceList = $carriers->map(function (FlightCarrier $c) use ($carrierStats) {
            $stat = $carrierStats->get($c->id);
            $bookings = $stat ? (int) $stat->count : 0;
            $revSum = $stat ? (float) $stat->revenue : 0.0;
            $profitSum = $stat ? (float) $stat->profit : 0.0;

            return [
                'id' => 'car_'.$c->id,
                'name' => $c->name . ' (شركة)',
                'bookings' => $bookings,
                'balance' => (float) $c->balance,
                'profit' => $profitSum,
                'profit_margin' => $revSum > 0 ? round(($profitSum / $revSum) * 100, 1) : 0,
            ];
        });

        $carrierPerformance = collect($systemPerformanceList)->merge($carrierPerformanceList)
            ->sortByDesc('profit')
            ->take(8)
            ->values()
            ->all();

        $bookingsChart = [];
        $revenueChart = [];
        $start = Carbon::parse($from);
        $end = Carbon::parse($to);
        if ($end->lt($start)) {
            $end = $start->copy();
        }
        $days = min(14, $start->diffInDays($end) + 1);
        $flightStats = FlightBooking::whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count, SUM(selling_price) as revenue, SUM(profit) as profit')
            ->when($carrierId, fn ($q) => $q->where('flight_carrier_id', (int) $carrierId))
            ->when($systemType !== null && $systemType !== '', fn ($q) => is_numeric($systemType) ? $q->where('flight_system_id', (int) $systemType) : $q->where('system_type', $systemType))
            ->groupBy('date')
            ->get()
            ->keyBy('date');

        for ($i = 0; $i < $days; $i++) {
            $d = $start->copy()->addDays($i)->toDateString();
            Carbon::setLocale('ar');
            $label = Carbon::parse($d)->translatedFormat('D j M');

            $stat = $flightStats->get($d);
            $cnt = $stat ? (int) $stat->count : 0;
            $rev = $stat ? (float) $stat->revenue : 0.0;
            $prof = $stat ? (float) $stat->profit : 0.0;

            $bookingsChart[] = ['label' => $label, 'count' => $cnt];
            $revenueChart[] = ['label' => $label, 'revenue' => $rev, 'profit' => $prof];
        }

        $topRoutes = FlightBooking::query()
            ->selectRaw('from_airport, to_airport, COUNT(*) as c, SUM(selling_price) as revenue, SUM(profit) as profit')
            ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->whereNotNull('from_airport')
            ->whereNotNull('to_airport')
            ->when($carrierId, fn ($q) => $q->where('flight_carrier_id', (int) $carrierId))
            ->when($systemType !== null && $systemType !== '', fn ($q) => is_numeric($systemType) ? $q->where('flight_system_id', (int) $systemType) : $q->where('system_type', $systemType))
            ->groupBy('from_airport', 'to_airport')
            ->orderByDesc('c')
            ->limit(6)
            ->get()
            ->map(fn ($r) => [
                'from' => $r->from_airport,
                'to' => $r->to_airport,
                'bookings' => (int) $r->c,
                'revenue' => (float) $r->revenue,
                'profit' => (float) $r->profit,
            ])
            ->all();

        $recentActivity = FlightBooking::with('customer')
            ->when($carrierId, fn ($q) => $q->where('flight_carrier_id', (int) $carrierId))
            ->when($systemType !== null && $systemType !== '', fn ($q) => is_numeric($systemType) ? $q->where('flight_system_id', (int) $systemType) : $q->where('system_type', $systemType))
            ->orderByDesc('created_at')
            ->limit(8)
            ->get()
            ->map(fn (FlightBooking $b) => [
                'type' => 'booking',
                'description' => 'حجز '.$b->booking_number.' — '.($b->from_airport ?? '').' → '.($b->to_airport ?? ''),
                'time' => $b->created_at?->diffForHumans(),
            ])
            ->all();

        return [
            'kpis' => [
                'today_bookings' => $todayBookings,
                'today_bookings_change' => $pct((float) $todayBookings, (float) $todayBookingsYesterday),
                'today_revenue' => $todayRevenue,
                'today_revenue_change' => $pct($todayRevenue, $yesterdayRevenue),
                'today_profit' => $todayProfit,
                'today_profit_change' => $pct($todayProfit, $yesterdayProfit),
                'active_carriers' => $carriers->count(),
                'cancelled_bookings' => $cancelled,
                'cancellation_rate' => $totalBookingsRange > 0 ? round(($cancelled / $totalBookingsRange) * 100, 1) : 0,
                'total_bookings' => $totalBookingsRange,
                'revenue' => $revenueRange,
                'net_profit' => $profitRange,
                'outstanding_payments' => $outstanding,
            ],
            'carrier_balance_cards' => $carrierCards,
            'bookings_chart' => $bookingsChart,
            'revenue_chart' => $revenueChart,
            'carrier_performance' => $carrierPerformance,
            'top_routes' => $topRoutes,
            'recent_activity' => $recentActivity,
        ];
    }

    /**
     * طلبات الخدمة (جدول اختياري — أُزيل نموذج Eloquent مع إبقاء العداد إن وُجد الجدول).
     */
    protected function countServiceOrders(Closure $scope): int
    {
        $hasTable = \Illuminate\Support\Facades\Cache::remember('schema_has_table_service_orders', 3600, function () {
            return Schema::hasTable('service_orders');
        });

        if (!$hasTable) {
            return 0;
        }

        $query = DB::table('service_orders');
        
        $hasDeletedAt = \Illuminate\Support\Facades\Cache::remember('schema_has_column_service_orders_deleted_at', 3600, function () {
            return Schema::hasColumn('service_orders', 'deleted_at');
        });

        if ($hasDeletedAt) {
            $query->whereNull('deleted_at');
        }
        $scope($query);

        return (int) $query->count();
    }
}
