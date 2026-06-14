<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\HajjUmra\StoreHajjUmraBookingRequest;
use App\Http\Requests\HajjUmra\StoreHajjUmraPaymentRequest;
use App\Http\Requests\HajjUmra\UpdateHajjUmraBookingRequest;
use App\Http\Resources\HajjUmra\HajjUmraBookingResource;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\HajjUmraBooking;
use App\Models\HajjUmraPayment;
use App\Services\HajjUmra\HajjUmraBookingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HajjUmraController extends Controller
{
    public function __construct(protected HajjUmraBookingService $service) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'status', 'program_id', 'customer_id', 'from_date', 'to_date', 'search', 'per_page', 'program_type',
        ]);
        $filters['page'] = $request->get('page', 1);

        $cacheKey = 'hajj_umra_bookings_list_' . md5(serialize($filters));

        $data = \App\Helpers\CacheHelper::tags(['hajj_umra_bookings'])->remember($cacheKey, 60, function () use ($filters) {
            $bookings = $this->service->paginate($filters);
            return [
                'items' => HajjUmraBookingResource::collection($bookings)->resolve(),
                'pagination' => [
                    'total' => $bookings->total(),
                    'per_page' => $bookings->perPage(),
                    'current_page' => $bookings->currentPage(),
                    'last_page' => $bookings->lastPage(),
                    'has_more' => $bookings->hasMorePages(),
                ],
            ];
        });

        return ApiResponse::success('تم جلب حجوزات الحج/العمرة', $data);
    }

    public function store(StoreHajjUmraBookingRequest $request): JsonResponse
    {
        try {
            $booking = $this->service->create($request->validated());
        } catch (\Throwable $e) {
            return ApiResponse::error('فشل إنشاء الحجز: '.$e->getMessage());
        }

        return ApiResponse::success(
            'تم إنشاء الحجز بنجاح',
            new HajjUmraBookingResource($booking),
            201
        );
    }

    public function show(HajjUmraBooking $hajjUmra): JsonResponse
    {
        return ApiResponse::success(
            'تم جلب تفاصيل الحجز',
            new HajjUmraBookingResource($this->service->find($hajjUmra->id))
        );
    }

    public function update(UpdateHajjUmraBookingRequest $request, HajjUmraBooking $hajjUmra): JsonResponse
    {
        $booking = $this->service->update($hajjUmra, $request->validated());

        return ApiResponse::success('تم تحديث الحجز', new HajjUmraBookingResource($booking));
    }

    public function destroy(Request $request, HajjUmraBooking $hajjUmra): JsonResponse
    {
        $booking = $this->service->cancel($hajjUmra, $request->input('reason'));

        return ApiResponse::success('تم إلغاء الحجز', new HajjUmraBookingResource($booking));
    }

    public function addPayment(StoreHajjUmraPaymentRequest $request, HajjUmraBooking $hajjUmra): JsonResponse
    {
        try {
            $payment = $this->service->addPayment($hajjUmra, $request->validated());
        } catch (\Throwable $e) {
            return ApiResponse::error('فشل تسجيل الدفعة: '.$e->getMessage());
        }

        return ApiResponse::success('تم تسجيل الدفعة', [
            'payment' => $payment->load('account', 'transaction'),
            'booking' => new HajjUmraBookingResource($this->service->find($hajjUmra->id)),
        ], 201);
    }

    public function customerBalances(Request $request): JsonResponse
    {
        try {
            $search = $request->query('search');
            $status = $request->query('status', 'all');
            $dateFrom = $request->query('from_date');
            $dateTo = $request->query('to_date');

            $query = HajjUmraBooking::query()
                ->select([
                    'hajj_umra_bookings.customer_id',
                    DB::raw('COUNT(hajj_umra_bookings.id) as booking_count'),
                    DB::raw('SUM(hajj_umra_bookings.selling_price + COALESCE(hajj_umra_bookings.companion_selling_price, 0) + COALESCE(hajj_umra_bookings.accommodation_extra_charge, 0)) as total_sales'),
                    DB::raw('MAX(hajj_umra_bookings.created_at) as last_booking'),
                ])
                ->join('customers', 'hajj_umra_bookings.customer_id', '=', 'customers.id')
                ->where('hajj_umra_bookings.status', '!=', 'cancelled')
                ->groupBy('hajj_umra_bookings.customer_id');

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('customers.full_name', 'like', "%{$search}%")
                        ->orWhere('customers.phone', 'like', "%{$search}%");
                });
            }

            if ($dateFrom) {
                $query->whereDate('hajj_umra_bookings.created_at', '>=', $dateFrom);
            }
            if ($dateTo) {
                $query->whereDate('hajj_umra_bookings.created_at', '<=', $dateTo);
            }

            $records = $query->get();

            $formatted = $records->map(function ($r) {
                $customer = Customer::find($r->customer_id);

                $totalPaid = (float) HajjUmraPayment::whereHas('booking', function ($q) use ($r) {
                    $q->where('customer_id', $r->customer_id)
                        ->where('status', '!=', 'cancelled');
                })->sum('amount');

                $totalPaid += $this->generalHajjUmraReceiptsForCustomer((int) $r->customer_id);

                $totalSales = (float) $r->total_sales;
                $totalDebt = round($totalSales - $totalPaid, 2);

                return [
                    'client_id' => $r->customer_id,
                    'client_name' => $customer?->full_name ?? $customer?->name ?? '—',
                    'phone' => $customer?->phone ?? '—',
                    'total_sales' => $totalSales,
                    'total_paid' => $totalPaid,
                    'total_debt' => $totalDebt,
                    'booking_count' => $r->booking_count,
                    'last_booking' => $r->last_booking,
                ];
            });

            if ($status === 'debtors') {
                $formatted = $formatted->filter(fn ($r) => $r['total_debt'] > 0.009);
            } elseif ($status === 'creditors') {
                $formatted = $formatted->filter(fn ($r) => $r['total_debt'] < -0.009);
            }

            return ApiResponse::success('تم جلب مديونيات عملاء الحج والعمرة.', $formatted->values())
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function customerStatement(Request $request): JsonResponse
    {
        try {
            $clientId = $request->query('client_id');
            if (! $clientId) {
                return ApiResponse::error('client_id مطلوب.', null, 400);
            }

            $customer = Customer::findOrFail($clientId);
            $resolver = app(\App\Services\Finance\LedgerEntryDescriptionResolver::class);

            $items = [];

            // 1. Fetch bookings
            $bookings = HajjUmraBooking::where('customer_id', $customer->id)
                ->where('status', '!=', 'cancelled')
                ->with(['payments.createdBy', 'program', 'createdBy'])
                ->get();

            foreach ($bookings as $b) {
                $totalSelling = (float) $b->total_selling_price;
                $items[] = [
                    'id' => 'booking_'.$b->id,
                    'date' => $b->created_at,
                    'type' => 'invoice',
                    'type_label' => 'حجز برنامج',
                    'debit' => $totalSelling,
                    'credit' => 0.0,
                    'description' => $resolver->forHajjUmraBooking($b),
                    'employee' => $b->createdBy?->name ?? '—',
                ];

                foreach ($b->payments as $p) {
                    $items[] = [
                        'id' => 'payment_'.$p->id,
                        'date' => $p->payment_date ?: $p->created_at,
                        'type' => 'payment',
                        'type_label' => 'سداد دفعة',
                        'debit' => 0.0,
                        'credit' => (float) $p->amount,
                        'description' => 'سداد دفعة — '.$resolver->forHajjUmraBooking($b).' (طريقة: '.($p->payment_method_label ?? $p->payment_method).')',
                        'employee' => $p->createdBy?->name ?? '—',
                    ];
                }
            }

            // 2. Fetch general debt payments (journal entries)
            if ($customer->account_id) {
                $paymentTxIds = HajjUmraPayment::pluck('transaction_id')->filter()->toArray();
                $bookingTxIds = HajjUmraBooking::where('customer_id', $customer->id)
                    ->pluck('income_transaction_id')
                    ->filter()
                    ->toArray();
                $excludedTxIds = array_merge($paymentTxIds, $bookingTxIds);

                $entries = AccountEntry::with([
                    'transaction.createdBy',
                    'transaction.related' => function ($morph) {
                        $morph->morphWith([
                            HajjUmraBooking::class => ['customer', 'program'],
                        ]);
                    },
                ])
                    ->where('account_id', $customer->account_id)
                    ->whereHas('transaction', function ($q) use ($excludedTxIds) {
                        $q->where('module', 'hajj_umra');
                        if (! empty($excludedTxIds)) {
                            $q->whereNotIn('id', $excludedTxIds);
                        }
                    })
                    ->get();

                foreach ($entries as $entry) {
                    $tx = $entry->transaction;
                    if (! $tx) {
                        continue;
                    }

                    $ledgerDebit = (float) $entry->debit;
                    $ledgerCredit = (float) $entry->credit;
                    $isReceipt = $ledgerDebit > 0;
                    $statementDebit = $isReceipt ? 0.0 : $ledgerDebit;
                    $statementCredit = $isReceipt ? $ledgerDebit : $ledgerCredit;

                    $items[] = [
                        'id' => 'general_'.$tx->id,
                        'date' => $tx->created_at,
                        'type' => $isReceipt ? 'payment' : 'invoice',
                        'type_label' => $isReceipt ? 'سند قبض' : 'سند صرف',
                        'debit' => $statementDebit,
                        'credit' => $statementCredit,
                        'description' => $resolver->resolve($entry),
                        'employee' => $tx->createdBy?->name ?? '—',
                    ];
                }
            }

            // Sort all items chronologically
            usort($items, function ($a, $b) {
                return strtotime($a['date']) <=> strtotime($b['date']);
            });

            // Calculate running balance
            $running = 0.0;
            foreach ($items as &$item) {
                $running += ($item['debit'] - $item['credit']);
                $item['running_balance'] = $running;
                if ($item['date'] instanceof Carbon) {
                    $item['date'] = $item['date']->format('Y-m-d H:i');
                } else {
                    $item['date'] = date('Y-m-d H:i', strtotime($item['date']));
                }
            }

            $summary = [
                'total_sales' => array_sum(array_column($items, 'debit')),
                'total_paid' => array_sum(array_column($items, 'credit')),
                'total_debt' => $running,
            ];

            return ApiResponse::success('تم جلب كشف حساب العميل.', [
                'customer' => [
                    'id' => $customer->id,
                    'name' => $customer->full_name,
                    'phone' => $customer->phone,
                ],
                'summary' => $summary,
                'transactions' => $items,
            ]);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * سندات قبض عامة (مثل سداد مديونية من شاشة العملاء) غير مرتبطة بدفعة حجز محددة.
     */
    private function generalHajjUmraReceiptsForCustomer(int $customerId): float
    {
        $customer = Customer::query()->find($customerId);
        if (! $customer?->account_id) {
            return 0.0;
        }

        $paymentTxIds = HajjUmraPayment::query()->pluck('transaction_id')->filter()->all();
        $bookingTxIds = HajjUmraBooking::query()
            ->where('customer_id', $customerId)
            ->pluck('income_transaction_id')
            ->filter()
            ->all();
        $excludedTxIds = array_values(array_unique(array_merge($paymentTxIds, $bookingTxIds)));

        return (float) AccountEntry::query()
            ->where('account_id', $customer->account_id)
            ->where('debit', '>', 0)
            ->whereHas('transaction', function ($q) use ($excludedTxIds) {
                $q->where('module', 'hajj_umra');
                if ($excludedTxIds !== []) {
                    $q->whereNotIn('id', $excludedTxIds);
                }
            })
            ->sum('debit');
    }
}
