<?php

namespace App\Services\Reports;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportCustomerService
{
    /**
     * Get customer balances report.
     * Shows customers with positive or negative balances.
     *
     * @param  array  $filters  Keys: type, balance_sign, search, per_page
     *                          balance_sign: 'positive' | 'negative' | 'zero'
     */
    public function getCustomerBalances(array $filters): LengthAwarePaginator
    {
        $query = DB::table('customers')
            ->select('id', 'name', 'phone', 'type', 'balance');

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['balance_sign'])) {
            switch ($filters['balance_sign']) {
                case 'positive':
                    $query->where('balance', '>', 0);
                    break;
                case 'negative':
                    $query->where('balance', '<', 0);
                    break;
                case 'zero':
                    $query->where('balance', '=', 0);
                    break;
            }
        }

        if (! empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('name', 'like', '%'.$filters['search'].'%')
                    ->orWhere('phone', 'like', '%'.$filters['search'].'%');
            });
        }

        $perPage = $filters['per_page'] ?? 20;

        return $query->orderBy('balance', 'asc')->paginate($perPage);
    }

    /**
     * Get full activity report for a specific customer.
     * Shows all operations this customer was involved in.
     *
     *
     * @throws \Exception
     */
    public function getCustomerActivity(int $customerId): array
    {
        $customer = DB::table('customers')->find($customerId);

        if (! $customer) {
            throw new \Exception('Customer not found.');
        }

        // Flight bookings
        $flightBookings = DB::table('flight_bookings')
            ->where('customer_id', $customerId)
            ->select('booking_number', 'airline_name', 'selling_price', 'status', 'created_at')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($booking) {
                return [
                    'booking_number' => $booking->booking_number,
                    'airline_name' => $booking->airline_name,
                    'selling_price' => round((float) $booking->selling_price, 2),
                    'status' => $booking->status,
                    'created_at' => $booking->created_at,
                ];
            });

        // Bus bookings
        $busBookings = DB::table('bus_bookings')
            ->join('bus_inventories', 'bus_bookings.inventory_id', '=', 'bus_inventories.id')
            ->where('bus_bookings.customer_id', $customerId)
            ->select('bus_inventories.route', 'bus_inventories.travel_date', 'bus_bookings.total_price', 'bus_bookings.status', 'bus_bookings.created_at')
            ->orderBy('bus_bookings.created_at', 'desc')
            ->get()
            ->map(function ($booking) {
                return [
                    'route' => $booking->route,
                    'travel_date' => $booking->travel_date,
                    'total_price' => round((float) $booking->total_price, 2),
                    'status' => $booking->status,
                    'created_at' => $booking->created_at,
                ];
            });

        // Service orders
        $serviceOrders = DB::table('service_orders')
            ->join('services', 'service_orders.service_id', '=', 'services.id')
            ->where('service_orders.customer_id', $customerId)
            ->select('services.name as service_name', 'service_orders.selling_price', 'service_orders.status', 'service_orders.created_at')
            ->orderBy('service_orders.created_at', 'desc')
            ->get()
            ->map(function ($order) {
                return [
                    'service_name' => $order->service_name,
                    'selling_price' => round((float) $order->selling_price, 2),
                    'status' => $order->status,
                    'created_at' => $order->created_at,
                ];
            });

        // Online transactions
        $onlineTransactions = DB::table('online_transactions')
            ->join('online_service_types', 'online_transactions.type_id', '=', 'online_service_types.id')
            ->where('online_transactions.customer_id', $customerId)
            ->select('online_service_types.name as service_type_name', 'online_transactions.amount', 'online_transactions.fee', 'online_transactions.status', 'online_transactions.created_at')
            ->orderBy('online_transactions.created_at', 'desc')
            ->get()
            ->map(function ($transaction) {
                return [
                    'service_type_name' => $transaction->service_type_name,
                    'amount' => round((float) $transaction->amount, 2),
                    'fee' => round((float) $transaction->fee, 2),
                    'status' => $transaction->status,
                    'created_at' => $transaction->created_at,
                ];
            });

        // Summary calculations
        $totalFlightSpent = $flightBookings->where('status', '!=', 'cancelled')->sum('selling_price');
        $totalBusSpent = $busBookings->where('status', '!=', 'cancelled')->sum('total_price');
        $totalServiceSpent = $serviceOrders->where('status', '!=', 'cancelled')->sum('selling_price');
        $totalOnlineSpent = $onlineTransactions->where('status', 'completed')->sum('amount');

        return [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'type' => $customer->type,
                'balance' => round((float) $customer->balance, 2),
            ],
            'flight_bookings' => $flightBookings,
            'bus_bookings' => $busBookings,
            'service_orders' => $serviceOrders,
            'online_transactions' => $onlineTransactions,
            'summary' => [
                'total_flight_spent' => round($totalFlightSpent, 2),
                'total_bus_spent' => round($totalBusSpent, 2),
                'total_service_spent' => round($totalServiceSpent, 2),
                'total_online_spent' => round($totalOnlineSpent, 2),
                'grand_total_spent' => round($totalFlightSpent + $totalBusSpent + $totalServiceSpent + $totalOnlineSpent, 2),
            ],
        ];
    }

    /**
     * Get top customers by total spending.
     *
     * @param  array  $filters  Keys: from_date, to_date, limit (default 10)
     */
    public function getTopCustomers(array $filters): Collection
    {
        $limit = $filters['limit'] ?? 10;

        $customers = DB::table('customers')
            ->select('id', 'name', 'phone', 'type', 'balance')
            ->get()
            ->keyBy('id');

        $spending = [];

        // Flight spending
        $flightSpending = DB::table('flight_bookings')
            ->where('status', '!=', 'cancelled')
            ->selectRaw('customer_id, SUM(selling_price) as total')
            ->when(! empty($filters['from_date']), fn ($q) => $q->whereDate('created_at', '>=', $filters['from_date']))
            ->when(! empty($filters['to_date']), fn ($q) => $q->whereDate('created_at', '<=', $filters['to_date']))
            ->groupBy('customer_id')
            ->pluck('total', 'customer_id')
            ->toArray();

        // Bus spending
        $busSpending = DB::table('bus_bookings')
            ->where('status', '!=', 'cancelled')
            ->selectRaw('customer_id, SUM(total_price) as total')
            ->when(! empty($filters['from_date']), fn ($q) => $q->whereDate('created_at', '>=', $filters['from_date']))
            ->when(! empty($filters['to_date']), fn ($q) => $q->whereDate('created_at', '<=', $filters['to_date']))
            ->groupBy('customer_id')
            ->pluck('total', 'customer_id')
            ->toArray();

        // Service spending
        $serviceSpending = DB::table('service_orders')
            ->where('status', '!=', 'cancelled')
            ->selectRaw('customer_id, SUM(selling_price) as total')
            ->when(! empty($filters['from_date']), fn ($q) => $q->whereDate('created_at', '>=', $filters['from_date']))
            ->when(! empty($filters['to_date']), fn ($q) => $q->whereDate('created_at', '<=', $filters['to_date']))
            ->groupBy('customer_id')
            ->pluck('total', 'customer_id')
            ->toArray();

        // Online spending
        $onlineSpending = DB::table('online_transactions')
            ->where('status', 'completed')
            ->selectRaw('customer_id, SUM(amount) as total')
            ->when(! empty($filters['from_date']), fn ($q) => $q->whereDate('created_at', '>=', $filters['from_date']))
            ->when(! empty($filters['to_date']), fn ($q) => $q->whereDate('created_at', '<=', $filters['to_date']))
            ->groupBy('customer_id')
            ->pluck('total', 'customer_id')
            ->toArray();

        // Combine all spending
        $allCustomerIds = array_unique(array_merge(
            array_keys($flightSpending),
            array_keys($busSpending),
            array_keys($serviceSpending),
            array_keys($onlineSpending)
        ));

        foreach ($allCustomerIds as $customerId) {
            $totalSpent = (float) ($flightSpending[$customerId] ?? 0) +
                         (float) ($busSpending[$customerId] ?? 0) +
                         (float) ($serviceSpending[$customerId] ?? 0) +
                         (float) ($onlineSpending[$customerId] ?? 0);

            if ($totalSpent > 0 && isset($customers[$customerId])) {
                $customer = $customers[$customerId];
                $spending[] = [
                    'customer_id' => $customerId,
                    'customer_name' => $customer->name,
                    'customer_phone' => $customer->phone,
                    'customer_type' => $customer->type,
                    'total_spent' => round($totalSpent, 2),
                    'balance' => round((float) $customer->balance, 2),
                ];
            }
        }

        // Sort by total_spent DESC and take limit
        usort($spending, fn ($a, $b) => $b['total_spent'] <=> $a['total_spent']);

        return collect(array_slice($spending, 0, $limit));
    }
}
