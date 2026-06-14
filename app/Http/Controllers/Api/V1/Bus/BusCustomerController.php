<?php

namespace App\Http\Controllers\Api\V1\Bus;

use App\Http\Controllers\Controller;
use App\Helpers\ApiResponse;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class BusCustomerController extends Controller
{
    /**
     * Display a listing of bus customers with their total debt.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 30), 100);
        $search  = $request->query('search');

        // ── Base query (shared) ───────────────────────────────────────────────
        $baseQuery = Customer::query()
            ->whereHas('busBookings', function ($q) {
                $q->whereNotIn('status', ['cancelled']);
            });

        if ($search) {
            $baseQuery->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // ── DB-wide stats (before debt_only filter) ───────────────────────────
        $statsQuery = (clone $baseQuery)
            ->selectRaw('
                COALESCE(SUM(CASE WHEN (
                    SELECT COALESCE(SUM(bb.total_price),0)
                    FROM bus_bookings bb
                    WHERE bb.customer_id = customers.id
                      AND bb.status NOT IN (\'cancelled\')
                      AND bb.deleted_at IS NULL
                ) > (
                    SELECT COALESCE(SUM(bb2.paid_amount),0)
                    FROM bus_bookings bb2
                    WHERE bb2.customer_id = customers.id
                      AND bb2.status NOT IN (\'cancelled\')
                      AND bb2.deleted_at IS NULL
                ) THEN (
                    SELECT COALESCE(SUM(bb.total_price),0)
                    FROM bus_bookings bb
                    WHERE bb.customer_id = customers.id
                      AND bb.status NOT IN (\'cancelled\')
                      AND bb.deleted_at IS NULL
                ) - (
                    SELECT COALESCE(SUM(bb2.paid_amount),0)
                    FROM bus_bookings bb2
                    WHERE bb2.customer_id = customers.id
                      AND bb2.status NOT IN (\'cancelled\')
                      AND bb2.deleted_at IS NULL
                ) ELSE 0 END), 0) AS total_receivable,
                COALESCE(SUM(CASE WHEN (
                    SELECT COALESCE(SUM(bb.total_price),0)
                    FROM bus_bookings bb
                    WHERE bb.customer_id = customers.id
                      AND bb.status NOT IN (\'cancelled\')
                      AND bb.deleted_at IS NULL
                ) < (
                    SELECT COALESCE(SUM(bb2.paid_amount),0)
                    FROM bus_bookings bb2
                    WHERE bb2.customer_id = customers.id
                      AND bb2.status NOT IN (\'cancelled\')
                      AND bb2.deleted_at IS NULL
                ) THEN (
                    SELECT COALESCE(SUM(bb2.paid_amount),0)
                    FROM bus_bookings bb2
                    WHERE bb2.customer_id = customers.id
                      AND bb2.status NOT IN (\'cancelled\')
                      AND bb2.deleted_at IS NULL
                ) - (
                    SELECT COALESCE(SUM(bb.total_price),0)
                    FROM bus_bookings bb
                    WHERE bb.customer_id = customers.id
                      AND bb.status NOT IN (\'cancelled\')
                      AND bb.deleted_at IS NULL
                ) ELSE 0 END), 0) AS total_payable,
                COUNT(*) AS total_customers,
                SUM(CASE WHEN (
                    SELECT COALESCE(SUM(bb.total_price),0)
                    FROM bus_bookings bb
                    WHERE bb.customer_id = customers.id
                      AND bb.status NOT IN (\'cancelled\')
                      AND bb.deleted_at IS NULL
                ) > (
                    SELECT COALESCE(SUM(bb2.paid_amount),0)
                    FROM bus_bookings bb2
                    WHERE bb2.customer_id = customers.id
                      AND bb2.status NOT IN (\'cancelled\')
                      AND bb2.deleted_at IS NULL
                ) THEN 1 ELSE 0 END) AS customers_with_debt,
                SUM(CASE WHEN (
                    SELECT COALESCE(SUM(bb.total_price),0)
                    FROM bus_bookings bb
                    WHERE bb.customer_id = customers.id
                      AND bb.status NOT IN (\'cancelled\')
                      AND bb.deleted_at IS NULL
                ) < (
                    SELECT COALESCE(SUM(bb2.paid_amount),0)
                    FROM bus_bookings bb2
                    WHERE bb2.customer_id = customers.id
                      AND bb2.status NOT IN (\'cancelled\')
                      AND bb2.deleted_at IS NULL
                ) THEN 1 ELSE 0 END) AS customers_with_credit
            ');

        $statsRaw = DB::table(DB::raw("({$statsQuery->toSql()}) as sub"))
            ->mergeBindings($statsQuery->getQuery())
            ->selectRaw('
                COALESCE(SUM(total_receivable), 0) AS total_receivable,
                COALESCE(SUM(total_payable),    0) AS total_payable,
                COALESCE(SUM(total_customers),  0) AS total_customers,
                COALESCE(SUM(customers_with_debt),   0) AS customers_with_debt,
                COALESCE(SUM(customers_with_credit), 0) AS customers_with_credit
            ')
            ->first();

        $stats = [
            'total_receivable'      => (float) ($statsRaw->total_receivable   ?? 0),
            'total_payable'         => (float) ($statsRaw->total_payable       ?? 0),
            'total_customers'       => (int)   ($statsRaw->total_customers     ?? 0),
            'customers_with_debt'   => (int)   ($statsRaw->customers_with_debt ?? 0),
            'customers_with_credit' => (int)   ($statsRaw->customers_with_credit ?? 0),
        ];

        // ── Paginated query (with debt_only filter) ───────────────────────────
        $query = (clone $baseQuery)
            ->withCount(['busBookings as total_bus_bookings' => function ($q) {
                $q->whereNotIn('status', ['cancelled']);
            }])
            ->withSum(['busBookings as total_bus_amount' => function ($q) {
                $q->whereNotIn('status', ['cancelled']);
            }], 'total_price')
            ->withSum(['busBookings as total_bus_paid' => function ($q) {
                $q->whereNotIn('status', ['cancelled']);
            }], 'paid_amount');

        if ($request->boolean('debt_only') || $request->query('debt_only') === 'true') {
            $query->whereRaw(
                '(SELECT COALESCE(SUM(total_price), 0) FROM bus_bookings WHERE bus_bookings.customer_id = customers.id AND status NOT IN ("cancelled") AND deleted_at IS NULL)
                > (SELECT COALESCE(SUM(paid_amount), 0) FROM bus_bookings WHERE bus_bookings.customer_id = customers.id AND status NOT IN ("cancelled") AND deleted_at IS NULL)'
            );
        }

        $customers = $query->latest()->paginate($perPage);

        $customers->getCollection()->transform(function ($customer) {
            $totalAmount = (float) $customer->total_bus_amount;
            $totalPaid   = (float) $customer->total_bus_paid;

            $customer->bus_remaining_debt = $totalAmount - $totalPaid;

            return [
                'id'                 => $customer->id,
                'full_name'          => $customer->full_name,
                'phone'              => $customer->phone,
                'total_bus_bookings' => $customer->total_bus_bookings,
                'total_bus_amount'   => $totalAmount,
                'total_bus_paid'     => $totalPaid,
                'bus_remaining_debt' => $customer->bus_remaining_debt,
            ];
        });

        return ApiResponse::success('تم جلب عملاء الباصات.', [
            'customers' => $customers,
            'stats'     => $stats,
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }
}
