<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountType;
use App\Enums\FlightBookingStatus;
use App\Enums\TransactionModule;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Http\Resources\Bus\BusBookingResource;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\Fawry\FawryTransactionResource;
use App\Http\Resources\Flight\FlightBookingResource;
use App\Http\Resources\HajjUmra\HajjUmraBookingResource;
use App\Http\Resources\Online\OnlineTransactionResource;
use App\Http\Resources\Visa\VisaBookingResource;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Services\CustomerService;
use App\Services\Finance\LedgerEntryDescriptionResolver;
use App\Services\Finance\TransactionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    public function __construct(
        protected CustomerService $customerService
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['search', 'type', 'is_active', 'per_page', 'module', 'customer_tier', 'balance_status']);
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

            if (! $query) {
                return ApiResponse::success('Query is empty.', []);
            }

            $customers = Customer::query()
                ->with('ledgerAccount')
                ->where('full_name', 'like', "%{$query}%")
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
                $customerAccount = Account::find($customer->account_id);
            }

            if (! $customerAccount) {
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
                    ],
                ]);
            }

            // Get all entries for the customer account
            $resolver = app(LedgerEntryDescriptionResolver::class);

            $entries = AccountEntry::with([
                'transaction.createdBy',
                'transaction.fromAccount',
                'transaction.toAccount',
                'transaction.related' => function ($morph) {
                    $morph->morphWith([
                        FlightBooking::class => ['customer', 'passengers', 'fromAirport', 'toAirport'],
                        \App\Models\Bus\BusBooking::class => ['customer', 'inventory.company'],
                        \App\Models\Online\OnlineTransaction::class => ['serviceType', 'provider'],
                        \App\Models\VisaBooking::class => ['customer', 'visaDetail'],
                        \App\Models\HajjUmraBooking::class => ['customer', 'program'],
                    ]);
                },
            ])
                ->where('account_id', $customerAccount->id)
                ->orderBy('created_at', 'asc')
                ->get();

            $items = [];
            $totalDebit = 0.0;
            $totalCredit = 0.0;

            foreach ($entries as $entry) {
                $tx = $entry->transaction;
                if (! $tx) {
                    continue;
                }

                $debit = (float) $entry->debit;
                $credit = (float) $entry->credit;

                $totalDebit += $debit;
                $totalCredit += $credit;

                $module = $tx->module instanceof TransactionModule ? $tx->module->value : $tx->module;

                $items[] = [
                    'id' => $entry->id,
                    'transaction_id' => $tx->id,
                    'created_at' => $entry->created_at->toDateTimeString(),
                    'date_human' => $entry->created_at->format('Y-m-d H:i'),
                    'user_name' => $tx->createdBy ? $tx->createdBy->name : 'النظام',
                    'reference_id' => $tx->related_id ?: $tx->id,
                    'entity_name' => $customer->full_name,
                    'description' => $resolver->resolve($entry),
                    'notes' => trim((string) ($entry->notes ?: ($tx->notes ?? ''))) ?: null,
                    'process_type' => $debit > 0 ? 'سند قبض / سداد نقدية' : 'فاتورة مبيعات / مديونية',
                    'module' => $module,
                    'debit' => $debit,
                    'credit' => $credit,
                    'balance_after' => (float) $entry->balance_after,
                    'booking_details' => $resolver->bookingDetails($tx),
                ];
            }

            $customer->load([
                'flightBookings' => fn ($q) => $q->orderBy('created_at', 'desc'),
                'flightBookings.passengers',
                'flightBookings.createdBy',
                'flightBookings.airlineAccount',
                'flightBookings.fromAirport',
                'flightBookings.toAirport',

                'visaBookings' => fn ($q) => $q->orderBy('created_at', 'desc'),
                'visaBookings.visaDetail',
                'visaBookings.visaDetail.agent',
                'visaBookings.visaDetail.durationRow',
                'visaBookings.createdBy',

                'hajjUmraBookings' => fn ($q) => $q->orderBy('created_at', 'desc'),
                'hajjUmraBookings.program',
                'hajjUmraBookings.supplier',
                'hajjUmraBookings.createdBy',
                'hajjUmraBookings.companion',

                'busBookings' => fn ($q) => $q->orderBy('created_at', 'desc'),
                'busBookings.inventory',
                'busBookings.inventory.company',
                'busBookings.createdBy',

                'fawryTransactions' => fn ($q) => $q->orderBy('created_at', 'desc'),
                'fawryTransactions.operationTypeRow',
                'fawryTransactions.paymentMethodRow',
                'fawryTransactions.machine',
                'fawryTransactions.employee',

                'onlineTransactions' => fn ($q) => $q->orderBy('created_at', 'desc'),
                'onlineTransactions.serviceType',
                'onlineTransactions.provider',
                'onlineTransactions.paymentMethodRow',
                'onlineTransactions.createdBy',
            ]);

            return ApiResponse::success('تم استدعاء كشف حساب العميل التفصيلي بنجاح.', [
                'customer' => new CustomerResource($customer),
                'stats' => [
                    'opening_balance' => 0.0,
                    'period_credit' => $totalCredit,
                    'period_debit' => $totalDebit,
                    'closing_balance' => (float) $customerAccount->balance,
                ],
                'bookings' => [
                    'flight' => FlightBookingResource::collection($customer->flightBookings),
                    'visa' => VisaBookingResource::collection($customer->visaBookings),
                    'hajj_umra' => HajjUmraBookingResource::collection($customer->hajjUmraBookings),
                    'bus' => BusBookingResource::collection($customer->busBookings),
                    'fawry' => FawryTransactionResource::collection($customer->fawryTransactions),
                    'online' => OnlineTransactionResource::collection($customer->onlineTransactions),
                ],
                'items' => array_reverse($items),
                'pagination' => [
                    'total' => count($items),
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => max(count($items), 20),
                    'from' => count($items) > 0 ? 1 : 0,
                    'to' => count($items),
                ],
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
            'module' => 'nullable|string',
        ]);

        try {
            return DB::transaction(function () use ($customer, $validated) {
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

                $toAccount = Account::findOrFail($validated['account_id']); // Treasury/Bank receiving the payment
                $fromAccount = $customerAccount; // Customer's ledger account

                $type = $validated['type'] ?? 'receipt'; // 'receipt' or 'payment'

                $fromId = $type === 'payment' ? $toAccount->id : $fromAccount->id;
                $toId = $type === 'payment' ? $fromAccount->id : $toAccount->id;

                $moduleStr = $validated['module'] ?? 'flight';
                $moduleEnum = TransactionModule::tryFrom($moduleStr) ?? TransactionModule::Flight;

                $moduleLabel = $moduleEnum->label();

                $transactionService = app(TransactionService::class);
                $transaction = $transactionService->recordJournalTransfer([
                    'amount' => (float) $validated['amount'],
                    'from_account_id' => $fromId,
                    'to_account_id' => $toId,
                    'allow_from_negative' => true, // Customer debt can go negative (i.e. they pay extra, becoming in credit)
                    'module' => $moduleEnum->value,
                    'notes' => $validated['notes'] ?? ($type === 'payment'
                        ? ('سند صرف - دفع للعميل: '.$customer->full_name)
                        : ("سند قبض - تسديد مديونية عميل {$moduleLabel}: ".$customer->full_name)),
                    'created_by' => Auth::id() ?? 1,
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
