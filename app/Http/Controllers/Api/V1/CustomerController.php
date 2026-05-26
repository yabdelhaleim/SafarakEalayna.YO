<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\CustomerService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    public function __construct(
        protected CustomerService $customerService
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['search', 'type', 'is_active', 'per_page']);
            $paginator = $this->customerService->getAllCustomers($filters);

            return ApiResponse::paginated(
                'Customers retrieved successfully.',
                CustomerResource::collection($paginator),
                $paginator
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        try {
            $customer = $this->customerService->createCustomer($request->validated());

            return ApiResponse::success(
                'Customer created successfully.',
                new CustomerResource($customer),
                201
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function show(Customer $customer): JsonResponse
    {
        try {
            // Load relations on the route model bound instance
            $customer->load(['createdBy']);

            return ApiResponse::success(
                'Customer retrieved successfully.',
                new CustomerResource($customer)
            );
        } catch (ModelNotFoundException $e) {
            return ApiResponse::error('Customer not found.', null, 404);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        try {
            $customer = $this->customerService->updateCustomer($customer, $request->validated());

            return ApiResponse::success(
                'Customer updated successfully.',
                new CustomerResource($customer)
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function destroy(Customer $customer): JsonResponse
    {
        try {
            $this->customerService->deleteCustomer($customer);

            return ApiResponse::success('Customer deleted successfully.');
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function search(Request $request): JsonResponse
    {
        try {
            $query = $request->get('q') ?: $request->get('search');

            if (!$query) {
                return ApiResponse::success('Query is empty.', []);
            }

            $customers = Customer::where('full_name', 'like', "%{$query}%")
                ->orWhere('phone', 'like', "%{$query}%")
                ->limit(10)
                ->get();

            return ApiResponse::success(
                'Customers found.',
                CustomerResource::collection($customers)
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * كشف حساب تفصيلي مجمع للعميل أو شركة الكاونتر يشمل كافة الحجوزات والمدفوعات مرتبة زمنياً.
     */
    public function statement(Request $request, Customer $customer): JsonResponse
    {
        try {
            $customerAccount = null;
            if ($customer->account_id) {
                $customerAccount = \App\Models\Account::find($customer->account_id);
            }

            if (!$customerAccount) {
                return ApiResponse::success('تم استدعاء كشف حساب العميل التفصيلي بنجاح.', [
                    'customer' => new CustomerResource($customer),
                    'stats' => [
                        'opening_balance' => 0.0,
                        'period_credit' => 0.0,
                        'period_debit' => 0.0,
                        'closing_balance' => 0.0,
                    ],
                    'items' => [],
                    'pagination' => [
                        'total' => 0,
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => 20,
                        'from' => 0,
                        'to' => 0,
                    ]
                ]);
            }

            // Get all entries for the customer account
            $entries = \App\Models\AccountEntry::with([
                'transaction.createdBy'
            ])
            ->where('account_id', $customerAccount->id)
            ->orderBy('created_at', 'asc')
            ->get();

            $items = [];
            $totalDebit = 0.0;
            $totalCredit = 0.0;

            foreach ($entries as $entry) {
                $tx = $entry->transaction;
                if (!$tx) {
                    continue;
                }

                $debit = (float) $entry->debit;
                $credit = (float) $entry->credit;

                $totalDebit += $debit;
                $totalCredit += $credit;

                $module = $tx->module instanceof \App\Enums\TransactionModule ? $tx->module->value : $tx->module;
                $description = $tx->notes ?: 'معاملة مالية';

                // Try to resolve booking details if related is present
                $bookingDetails = null;
                if ($tx->related_type && $tx->related_id) {
                    try {
                        $related = $tx->related;
                        if ($related) {
                            if ($tx->related_type === \App\Models\Flight\FlightBooking::class) {
                                $bookingDetails = [
                                    'booking_id' => $related->id,
                                    'booking_number' => $related->booking_number,
                                    'pnr' => $related->pnr,
                                    'provider_name' => $related->airline_name,
                                    'route' => $related->route ?: ($related->from_airport . ' → ' . $related->to_airport),
                                    'passengers' => $related->passengers && $related->passengers->count() ? $related->passengers->map(fn($p) => trim(($p->first_name ?? '') . ' ' . ($p->last_name ?? '')))->filter()->implode('، ') : '—',
                                    'status' => $related->status instanceof \App\Enums\FlightBookingStatus ? $related->status->value : $related->status,
                                    'selling_price' => (float) $related->selling_price,
                                    'total_paid' => (float) $related->paid_amount,
                                    'remaining' => (float) $related->remaining_amount,
                                    'payment_status' => $related->computePaymentStatus(),
                                ];
                            }
                        }
                    } catch (\Exception $e) {
                        // ignore if related model cannot be loaded
                    }
                }

                $items[] = [
                    'id' => $entry->id,
                    'transaction_id' => $tx->id,
                    'created_at' => $entry->created_at->toDateTimeString(),
                    'date_human' => $entry->created_at->format('Y-m-d H:i'),
                    'user_name' => $tx->createdBy ? $tx->createdBy->name : 'النظام',
                    'reference_id' => $tx->id,
                    'entity_name' => $customer->full_name,
                    'description' => $description,
                    'process_type' => $debit > 0 ? 'سند قبض / سداد نقدية' : 'فاتورة مبيعات / مديونية',
                    'module' => $module,
                    'debit' => $debit,
                    'credit' => $credit,
                    'balance_after' => (float) $entry->balance_after,
                    'booking_details' => $bookingDetails,
                ];
            }

            return ApiResponse::success('تم استدعاء كشف حساب العميل التفصيلي بنجاح.', [
                'customer' => new CustomerResource($customer),
                'stats' => [
                    'opening_balance' => 0.0,
                    'period_credit' => $totalCredit,
                    'period_debit' => $totalDebit,
                    'closing_balance' => (float) $customerAccount->balance,
                ],
                'items' => array_reverse($items),
                'pagination' => [
                    'total' => count($items),
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => max(count($items), 20),
                    'from' => count($items) > 0 ? 1 : 0,
                    'to' => count($items),
                ]
            ]);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * Record a customer debt repayment (سند قبض) and transfer to treasury/bank.
     */
    public function payDebt(Request $request, Customer $customer): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'account_id' => 'required|exists:accounts,id',
            'notes' => 'nullable|string|max:500',
            'type' => 'nullable|string|in:receipt,payment',
        ]); 

        try {
            return \Illuminate\Support\Facades\DB::transaction(function () use ($customer, $validated) {
                // Ensure customer has a ledger account
                $customerAccount = $customer->account_id ? \App\Models\Account::find($customer->account_id) : null;
                if (!$customerAccount) {
                    $customerAccount = \App\Models\Account::create([
                        'name' => 'حساب العميل: ' . $customer->full_name,
                        'type' => \App\Enums\AccountType::Customer,
                        'balance' => 0,
                        'currency' => 'EGP',
                        'is_active' => true,
                        'owner_type' => \App\Models\Account::OWNER_TYPE_OWNER,
                        'module_type' => 'tourism',
                        'is_module_vault' => false,
                        'notes' => 'حساب تلقائي للعميل #' . $customer->id,
                        'created_by' => \Illuminate\Support\Facades\Auth::id() ?? 1,
                    ]);
                    $customer->update(['account_id' => $customerAccount->id]);
                }

                $toAccount = \App\Models\Account::findOrFail($validated['account_id']); // Treasury/Bank receiving the payment
                $fromAccount = $customerAccount; // Customer's ledger account

                $type = $validated['type'] ?? 'receipt'; // 'receipt' or 'payment'

                $fromId = $type === 'payment' ? $toAccount->id : $fromAccount->id;
                $toId = $type === 'payment' ? $fromAccount->id : $toAccount->id;

                $transactionService = app(\App\Services\Finance\TransactionService::class);
                $transaction = $transactionService->recordJournalTransfer([
                    'amount' => (float) $validated['amount'],
                    'from_account_id' => $fromId,
                    'to_account_id' => $toId,
                    'allow_from_negative' => true, // Customer debt can go negative (i.e. they pay extra, becoming in credit)
                    'module' => \App\Enums\TransactionModule::Flight->value,
                    'notes' => $validated['notes'] ?? ($type === 'payment' 
                        ? ('سند صرف - دفع للعميل: ' . $customer->full_name) 
                        : ('سند قبض - تسديد مديونية عميل طيران: ' . $customer->full_name)),
                    'created_by' => \Illuminate\Support\Facades\Auth::id() ?? 1,
                ]);

                return ApiResponse::success($type === 'payment' ? 'تم صرف المبلغ للعميل بنجاح وقيد سند الصرف.' : 'تم سداد المبلغ بنجاح وقيد سند القبض.', [
                    'transaction_id' => $transaction->id,
                    'new_balance' => (float) $fromAccount->fresh()->balance,
                ]);
            });
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }
}
