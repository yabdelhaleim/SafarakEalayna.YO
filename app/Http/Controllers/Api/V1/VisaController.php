<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Enums\VisaType;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Visa\StoreVisaBookingRequest;
use App\Http\Requests\Visa\StoreVisaPaymentRequest;
use App\Http\Requests\Visa\UpdateVisaBookingRequest;
use App\Http\Resources\Visa\VisaBookingResource;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\VisaBooking;
use App\Models\VisaPayment;
use App\Services\Finance\TransactionService;
use App\Services\Visa\VisaBookingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class VisaController extends Controller
{
    public function __construct(protected VisaBookingService $service) {}

    public function index(Request $request): JsonResponse
    {
        $bookings = $this->service->paginate($request->only([
            'status', 'country', 'visa_type', 'from_date', 'to_date', 'search', 'per_page',
        ]));

        return ApiResponse::paginated(
            'تم جلب طلبات التأشيرات',
            VisaBookingResource::collection($bookings),
            $bookings
        );
    }

    public function store(StoreVisaBookingRequest $request): JsonResponse
    {
        try {
            $booking = $this->service->create($request->validated());
        } catch (\Throwable $e) {
            return ApiResponse::error('فشل إنشاء طلب التأشيرة: '.$e->getMessage());
        }

        return ApiResponse::success(
            'تم إنشاء طلب التأشيرة بنجاح',
            new VisaBookingResource($booking),
            201
        );
    }

    public function show(VisaBooking $visa): JsonResponse
    {
        return ApiResponse::success(
            'تم جلب تفاصيل التأشيرة',
            new VisaBookingResource($this->service->find($visa->id))
        );
    }

    public function update(UpdateVisaBookingRequest $request, VisaBooking $visa): JsonResponse
    {
        $booking = $this->service->update($visa, $request->validated());

        return ApiResponse::success('تم تحديث طلب التأشيرة', new VisaBookingResource($booking));
    }

    public function destroy(Request $request, VisaBooking $visa): JsonResponse
    {
        $booking = $this->service->cancel($visa, $request->input('reason'));

        return ApiResponse::success('تم إلغاء طلب التأشيرة', new VisaBookingResource($booking));
    }

    public function addPayment(StoreVisaPaymentRequest $request, VisaBooking $visa): JsonResponse
    {
        try {
            $payment = $this->service->addPayment($visa, $request->validated());
        } catch (\Throwable $e) {
            return ApiResponse::error('فشل تسجيل الدفعة: '.$e->getMessage());
        }

        return ApiResponse::success('تم تسجيل الدفعة', [
            'payment' => $payment->load('account', 'transaction'),
            'booking' => new VisaBookingResource($this->service->find($visa->id)),
        ], 201);
    }

    /** مديونيات عملاء التأشيرات - ملخص */
    public function customerBalances(Request $request): JsonResponse
    {
        try {
            $search = $request->query('search');
            $status = $request->query('status', 'all');

            $query = VisaBooking::query()
                ->select([
                    'visa_bookings.customer_id',
                    DB::raw('COUNT(visa_bookings.id) as booking_count'),
                    DB::raw('SUM(visa_bookings.selling_price + COALESCE(visa_bookings.service_fee,0)) as total_sales'),
                    DB::raw('MAX(visa_bookings.created_at) as last_booking'),
                ])
                ->join('customers', 'visa_bookings.customer_id', '=', 'customers.id')
                ->whereNull('visa_bookings.deleted_at')
                ->whereNotIn('visa_bookings.status', ['cancelled', 'rejected', 'refunded'])
                ->groupBy('visa_bookings.customer_id');

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('customers.full_name', 'like', "%{$search}%")
                        ->orWhere('customers.phone', 'like', "%{$search}%");
                });
            }

            $formatted = $query->get()->map(function ($r) {
                $customer = Customer::find($r->customer_id);
                $totalPaid = (float) VisaPayment::whereHas('booking', function ($q) use ($r) {
                    $q->where('customer_id', $r->customer_id)
                        ->whereNotIn('status', ['cancelled', 'rejected', 'refunded']);
                })->sum('amount');

                $totalSales = (float) $r->total_sales;
                $totalDebt = round($totalSales - $totalPaid, 2);

                return [
                    'client_id' => $r->customer_id,
                    'client_name' => $customer?->full_name ?? 'مجهول',
                    'phone' => $customer?->phone ?? '—',
                    'total_sales' => $totalSales,
                    'total_paid' => $totalPaid,
                    'total_debt' => $totalDebt,
                    'booking_count' => (int) $r->booking_count,
                    'last_booking' => $r->last_booking,
                ];
            });

            if ($status === 'debtors') {
                $formatted = $formatted->filter(fn ($r) => $r['total_debt'] > 0.009);
            }
            if ($status === 'creditors') {
                $formatted = $formatted->filter(fn ($r) => $r['total_debt'] < -0.009);
            }

            return ApiResponse::success('تم جلب مديونيات عملاء التأشيرات.', $formatted->values());
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /** كشف حساب تفصيلي لعميل تأشيرة */
    public function customerStatement(Request $request): JsonResponse
    {
        try {
            $clientId = $request->query('client_id');
            if (! $clientId) {
                return ApiResponse::error('client_id مطلوب.', null, 400);
            }

            $customer = Customer::findOrFail($clientId);
            $items = [];

            $bookings = VisaBooking::where('customer_id', $customer->id)
                ->with(['payments.createdBy', 'visaDetail', 'createdBy'])
                ->whereNotIn('status', ['cancelled', 'rejected', 'refunded'])
                ->get();

            foreach ($bookings as $b) {
                $totalSelling = (float) $b->selling_price + (float) ($b->service_fee ?? 0);
                $country = $b->visaDetail?->country ?? 'تأشيرة';
                $visaType = $b->visaDetail?->visa_type;
                $visaTypeStr = '';
                if ($visaType instanceof VisaType) {
                    $visaTypeStr = $visaType->label();
                } elseif (is_string($visaType)) {
                    $visaTypeStr = $visaType;
                }

                $items[] = [
                    'id' => 'booking_'.$b->id, 'date' => $b->created_at,
                    'type' => 'invoice', 'type_label' => 'حجز تأشيرة',
                    'debit' => $totalSelling, 'credit' => 0.0,
                    'description' => "تأشيرة {$country} ".($visaTypeStr ? "({$visaTypeStr})" : '')." — حجز #{$b->id}",
                    'employee' => $b->createdBy?->name ?? '—',
                ];

                foreach ($b->payments as $p) {
                    $items[] = [
                        'id' => 'payment_'.$p->id, 'date' => $p->payment_date ?: $p->created_at,
                        'type' => 'payment', 'type_label' => 'دفعة سداد',
                        'debit' => 0.0, 'credit' => (float) $p->amount,
                        'description' => "دفعة لحجز #{$b->id}",
                        'employee' => $p->createdBy?->name ?? '—',
                    ];
                }
            }

            if ($customer->account_id) {
                $bookingTxIds = VisaBooking::where('customer_id', $customer->id)->pluck('income_transaction_id')->filter()->toArray();
                $paymentTxIds = VisaPayment::whereHas('booking', fn ($q) => $q->where('customer_id', $customer->id))->pluck('transaction_id')->filter()->toArray();
                $excluded = array_merge($bookingTxIds, $paymentTxIds);

                $entries = AccountEntry::with(['transaction.createdBy'])
                    ->where('account_id', $customer->account_id)
                    ->whereHas('transaction', function ($q) use ($excluded) {
                        $q->where('module', 'visa');
                        if (! empty($excluded)) {
                            $q->whereNotIn('id', $excluded);
                        }
                    })->get();

                foreach ($entries as $entry) {
                    if (! $entry->transaction) {
                        continue;
                    }
                    $tx = $entry->transaction;
                    $items[] = [
                        'id' => 'general_'.$tx->id, 'date' => $tx->created_at,
                        'type' => (float) $entry->credit > 0 ? 'payment' : 'invoice',
                        'type_label' => (float) $entry->credit > 0 ? 'سند قبض' : 'سند صرف',
                        'debit' => (float) $entry->debit, 'credit' => (float) $entry->credit,
                        'description' => $tx->notes ?: ((float) $entry->credit > 0 ? 'سداد مديونية' : 'صرف للعميل'),
                        'employee' => $tx->createdBy?->name ?? '—',
                    ];
                }
            }

            usort($items, fn ($a, $b) => strtotime($a['date']) <=> strtotime($b['date']));
            $running = 0.0;
            foreach ($items as &$item) {
                $running += ($item['debit'] - $item['credit']);
                $item['running_balance'] = round($running, 2);
                $item['date'] = $item['date'] instanceof Carbon
                    ? $item['date']->format('Y-m-d H:i')
                    : date('Y-m-d H:i', strtotime($item['date']));
            }

            return ApiResponse::success('تم جلب كشف الحساب.', [
                'customer' => ['id' => $customer->id, 'name' => $customer->full_name, 'phone' => $customer->phone],
                'summary' => [
                    'total_sales' => round(array_sum(array_column($items, 'debit')), 2),
                    'total_paid' => round(array_sum(array_column($items, 'credit')), 2),
                    'total_debt' => round($running, 2),
                ],
                'transactions' => $items,
            ]);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /** تسديد مديونية عميل تأشيرة - سند قبض */
    public function payCustomerDebt(Request $request, Customer $customer): JsonResponse
    {
        $v = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'account_id' => 'required|exists:accounts,id',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            return DB::transaction(function () use ($customer, $v) {
                // Ensure customer has a ledger account
                $customerAccount = $customer->account_id ? Account::find($customer->account_id) : null;
                if (! $customerAccount) {
                    $customerAccount = Account::create([
                        'name' => 'حساب العميل: '.$customer->full_name,
                        'type' => AccountType::Customer,
                        'balance' => 0,
                        'currency' => 'EGP',
                        'is_active' => true,
                        'owner_type' => Account::OWNER_TYPE_OWNER,
                        'module_type' => 'tourism',
                        'is_module_vault' => false,
                        'notes' => 'حساب تلقائي للعميل #'.$customer->id,
                        'created_by' => Auth::id() ?? 1,
                    ]);
                    $customer->update(['account_id' => $customerAccount->id]);
                }

                $toAccount = Account::findOrFail($v['account_id']); // Treasury/Bank receiving the payment
                $fromAccount = $customerAccount; // Customer's ledger account

                $transactionService = app(TransactionService::class);
                $transaction = $transactionService->recordJournalTransfer([
                    'amount' => (float) $v['amount'],
                    'from_account_id' => $fromAccount->id,
                    'to_account_id' => $toAccount->id,
                    'allow_from_negative' => true,
                    'module' => TransactionModule::Visa->value,
                    'notes' => $v['notes'] ?? ('سند قبض - تسديد مديونية عميل تأشيرة: '.$customer->full_name),
                    'created_by' => Auth::id() ?? 1,
                ]);

                return ApiResponse::success('تم سداد المبلغ بنجاح وقيد سند القبض.', [
                    'transaction_id' => $transaction->id,
                    'new_balance' => (float) $fromAccount->fresh()->balance,
                ]);
            });
        } catch (\Exception $e) {
            return ApiResponse::error('فشل تسجيل السند: '.$e->getMessage(), null, 422);
        }
    }
}
