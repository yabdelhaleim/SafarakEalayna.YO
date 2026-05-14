<?php

namespace App\Services\Reports;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ReportOperationsService
{
    /**
     * Get profit summary across all operation modules.
     *
     * @param  array  $filters  Keys: from_date, to_date
     */
    public function getProfitSummary(array $filters): array
    {
        $dateCondition = '';
        $params = [];
        if (! empty($filters['from_date'])) {
            $dateCondition .= ' AND created_at >= ?';
            $params[] = $filters['from_date'].' 00:00:00';
        }
        if (! empty($filters['to_date'])) {
            $dateCondition .= ' AND created_at <= ?';
            $params[] = $filters['to_date'].' 23:59:59';
        }

        // Flight
        $flight = DB::selectOne("
            SELECT
                COUNT(*) as total_bookings,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN status != 'cancelled' THEN selling_price ELSE 0 END) as total_revenue,
                SUM(CASE WHEN status != 'cancelled' THEN purchase_price ELSE 0 END) as total_cost,
                SUM(CASE WHEN status != 'cancelled' THEN profit ELSE 0 END) as total_profit
            FROM flight_bookings
            WHERE 1=1 {$dateCondition}
        ", $params);

        // Bus
        $bus = DB::selectOne("
            SELECT
                COUNT(*) as total_bookings,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN status = 'paid' THEN total_price ELSE 0 END) as total_revenue,
                SUM(CASE WHEN status = 'paid' THEN profit ELSE 0 END) as total_profit
            FROM bus_bookings
            WHERE 1=1 {$dateCondition}
        ", $params);

        // Services
        $services = DB::selectOne("
            SELECT
                COUNT(*) as total_orders,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN status != 'cancelled' THEN selling_price ELSE 0 END) as total_revenue,
                SUM(CASE WHEN status != 'cancelled' THEN profit ELSE 0 END) as total_profit
            FROM service_orders
            WHERE 1=1 {$dateCondition}
        ", $params);

        // Online
        $online = DB::selectOne("
            SELECT
                COUNT(*) as total_transactions,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_amount,
                SUM(CASE WHEN status = 'completed' THEN fee ELSE 0 END) as total_fees
            FROM online_transactions
            WHERE 1=1 {$dateCondition}
        ", $params);

        $grandTotalProfit = (float) ($flight->total_profit ?? 0) +
                           (float) ($bus->total_profit ?? 0) +
                           (float) ($services->total_profit ?? 0) +
                           (float) ($online->total_fees ?? 0);

        return [
            'flight' => [
                'total_bookings' => (int) ($flight->total_bookings ?? 0),
                'confirmed' => (int) ($flight->total_bookings ?? 0) - (int) ($flight->cancelled ?? 0),
                'cancelled' => (int) ($flight->cancelled ?? 0),
                'total_revenue' => round((float) ($flight->total_revenue ?? 0), 2),
                'total_cost' => round((float) ($flight->total_cost ?? 0), 2),
                'total_profit' => round((float) ($flight->total_profit ?? 0), 2),
            ],
            'bus' => [
                'total_bookings' => (int) ($bus->total_bookings ?? 0),
                'paid' => (int) ($bus->total_bookings ?? 0) - (int) ($bus->cancelled ?? 0),
                'cancelled' => (int) ($bus->cancelled ?? 0),
                'total_revenue' => round((float) ($bus->total_revenue ?? 0), 2),
                'total_profit' => round((float) ($bus->total_profit ?? 0), 2),
            ],
            'services' => [
                'total_orders' => (int) ($services->total_orders ?? 0),
                'completed' => (int) ($services->total_orders ?? 0) - (int) ($services->cancelled ?? 0),
                'cancelled' => (int) ($services->cancelled ?? 0),
                'total_revenue' => round((float) ($services->total_revenue ?? 0), 2),
                'total_profit' => round((float) ($services->total_profit ?? 0), 2),
            ],
            'online' => [
                'total_transactions' => (int) ($online->total_transactions ?? 0),
                'completed' => (int) ($online->total_transactions ?? 0) - (int) ($online->failed ?? 0),
                'failed' => (int) ($online->failed ?? 0),
                'total_amount' => round((float) ($online->total_amount ?? 0), 2),
                'total_fees' => round((float) ($online->total_fees ?? 0), 2),
            ],
            'grand_total_profit' => round($grandTotalProfit, 2),
        ];
    }

    /**
     * Get flight bookings report with filters.
     *
     * @param  array  $filters  Keys: status, from_date, to_date, airline_name, per_page
     */
    public function getFlightReport(array $filters): LengthAwarePaginator
    {
        $query = DB::table('flight_bookings')
            ->join('customers', 'flight_bookings.customer_id', '=', 'customers.id')
            ->join('employees', 'flight_bookings.employee_id', '=', 'employees.id')
            ->join('users', 'employees.user_id', '=', 'users.id')
            ->select(
                'flight_bookings.booking_number',
                'flight_bookings.airline_name',
                'customers.name as customer_name',
                'customers.phone as customer_phone',
                'users.name as employee_name',
                'flight_bookings.purchase_price',
                'flight_bookings.selling_price',
                'flight_bookings.profit',
                'flight_bookings.status',
                'flight_bookings.created_at'
            );

        if (! empty($filters['status'])) {
            $query->where('flight_bookings.status', $filters['status']);
        }

        if (! empty($filters['airline_name'])) {
            $query->where('flight_bookings.airline_name', 'like', '%'.$filters['airline_name'].'%');
        }

        if (! empty($filters['from_date'])) {
            $query->whereDate('flight_bookings.created_at', '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $query->whereDate('flight_bookings.created_at', '<=', $filters['to_date']);
        }

        $perPage = $filters['per_page'] ?? 20;

        return $query->orderBy('flight_bookings.created_at', 'desc')->paginate($perPage);
    }

    /**
     * Get bus bookings report with filters.
     *
     * @param  array  $filters  Keys: status, company_id, from_date, to_date, per_page
     */
    public function getBusReport(array $filters): LengthAwarePaginator
    {
        $query = DB::table('bus_bookings')
            ->join('bus_inventories', 'bus_bookings.inventory_id', '=', 'bus_inventories.id')
            ->join('bus_companies', 'bus_inventories.company_id', '=', 'bus_companies.id')
            ->join('customers', 'bus_bookings.customer_id', '=', 'customers.id')
            ->join('employees', 'bus_bookings.employee_id', '=', 'employees.id')
            ->join('users', 'employees.user_id', '=', 'users.id')
            ->select(
                'bus_inventories.route',
                'bus_inventories.travel_date',
                'bus_companies.name as company_name',
                'customers.name as customer_name',
                'users.name as employee_name',
                'bus_bookings.quantity',
                'bus_bookings.unit_price',
                'bus_bookings.total_price',
                'bus_bookings.profit',
                'bus_bookings.status',
                'bus_bookings.created_at'
            );

        if (! empty($filters['status'])) {
            $query->where('bus_bookings.status', $filters['status']);
        }

        if (! empty($filters['company_id'])) {
            $query->where('bus_inventories.company_id', $filters['company_id']);
        }

        if (! empty($filters['from_date'])) {
            $query->whereDate('bus_bookings.created_at', '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $query->whereDate('bus_bookings.created_at', '<=', $filters['to_date']);
        }

        $perPage = $filters['per_page'] ?? 20;

        return $query->orderBy('bus_bookings.created_at', 'desc')->paginate($perPage);
    }

    /**
     * Get services orders report with filters.
     *
     * @param  array  $filters  Keys: status, category, from_date, to_date, per_page
     */
    public function getServicesReport(array $filters): LengthAwarePaginator
    {
        $query = DB::table('service_orders')
            ->join('services', 'service_orders.service_id', '=', 'services.id')
            ->join('customers', 'service_orders.customer_id', '=', 'customers.id')
            ->join('employees', 'service_orders.employee_id', '=', 'employees.id')
            ->join('users', 'employees.user_id', '=', 'users.id')
            ->select(
                'services.name as service_name',
                'services.category',
                'customers.name as customer_name',
                'users.name as employee_name',
                'service_orders.selling_price',
                'service_orders.cost_price',
                'service_orders.profit',
                'service_orders.status',
                'service_orders.created_at'
            );

        if (! empty($filters['status'])) {
            $query->where('service_orders.status', $filters['status']);
        }

        if (! empty($filters['category'])) {
            $query->where('services.category', $filters['category']);
        }

        if (! empty($filters['from_date'])) {
            $query->whereDate('service_orders.created_at', '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $query->whereDate('service_orders.created_at', '<=', $filters['to_date']);
        }

        $perPage = $filters['per_page'] ?? 20;

        return $query->orderBy('service_orders.created_at', 'desc')->paginate($perPage);
    }

    /**
     * Get online transactions report with filters.
     *
     * @param  array  $filters  Keys: status, type_id, from_date, to_date, per_page
     */
    public function getOnlineReport(array $filters): LengthAwarePaginator
    {
        $query = DB::table('online_transactions')
            ->join('online_service_types', 'online_transactions.type_id', '=', 'online_service_types.id')
            ->join('customers', 'online_transactions.customer_id', '=', 'customers.id')
            ->join('employees', 'online_transactions.employee_id', '=', 'employees.id')
            ->join('users', 'employees.user_id', '=', 'users.id')
            ->select(
                'online_service_types.name as service_type_name',
                'customers.name as customer_name',
                'users.name as employee_name',
                'online_transactions.amount',
                'online_transactions.fee',
                'online_transactions.total_collected',
                'online_transactions.status',
                'online_transactions.created_at'
            );

        if (! empty($filters['status'])) {
            $query->where('online_transactions.status', $filters['status']);
        }

        if (! empty($filters['type_id'])) {
            $query->where('online_transactions.type_id', $filters['type_id']);
        }

        if (! empty($filters['from_date'])) {
            $query->whereDate('online_transactions.created_at', '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $query->whereDate('online_transactions.created_at', '<=', $filters['to_date']);
        }

        $perPage = $filters['per_page'] ?? 20;

        return $query->orderBy('online_transactions.created_at', 'desc')->paginate($perPage);
    }

    /**
     * Get monthly profit breakdown for all modules for a given year.
     * Used for annual profit chart.
     */
    public function getMonthlyProfitChart(int $year): array
    {
        $months = [];
        for ($month = 1; $month <= 12; $month++) {
            $months[$month] = [
                'month' => $month,
                'month_name' => date('F', mktime(0, 0, 0, $month, 1)),
                'flight' => 0.00,
                'bus' => 0.00,
                'service' => 0.00,
                'online' => 0.00,
                'total' => 0.00,
            ];
        }

        // Flight profits
        $flightProfits = DB::table('flight_bookings')
            ->selectRaw('MONTH(created_at) as month, SUM(profit) as profit')
            ->whereYear('created_at', $year)
            ->where('status', '!=', 'cancelled')
            ->groupByRaw('MONTH(created_at)')
            ->pluck('profit', 'month')
            ->toArray();

        // Bus profits
        $busProfits = DB::table('bus_bookings')
            ->selectRaw('MONTH(created_at) as month, SUM(profit) as profit')
            ->whereYear('created_at', $year)
            ->where('status', 'paid')
            ->groupByRaw('MONTH(created_at)')
            ->pluck('profit', 'month')
            ->toArray();

        // Service profits
        $serviceProfits = DB::table('service_orders')
            ->selectRaw('MONTH(created_at) as month, SUM(profit) as profit')
            ->whereYear('created_at', $year)
            ->where('status', '!=', 'cancelled')
            ->groupByRaw('MONTH(created_at)')
            ->pluck('profit', 'month')
            ->toArray();

        // Online fees
        $onlineProfits = DB::table('online_transactions')
            ->selectRaw('MONTH(created_at) as month, SUM(fee) as profit')
            ->whereYear('created_at', $year)
            ->where('status', 'completed')
            ->groupByRaw('MONTH(created_at)')
            ->pluck('profit', 'month')
            ->toArray();

        foreach ($months as $month => &$data) {
            $data['flight'] = round((float) ($flightProfits[$month] ?? 0), 2);
            $data['bus'] = round((float) ($busProfits[$month] ?? 0), 2);
            $data['service'] = round((float) ($serviceProfits[$month] ?? 0), 2);
            $data['online'] = round((float) ($onlineProfits[$month] ?? 0), 2);
            $data['total'] = round($data['flight'] + $data['bus'] + $data['service'] + $data['online'], 2);
        }

        return array_values($months);
    }
}
