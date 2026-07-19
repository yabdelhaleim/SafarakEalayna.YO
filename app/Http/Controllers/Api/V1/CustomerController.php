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
            $filters['page'] = $request->get('page', 1);

            $cacheKey = 'customers_list_' . md5(serialize($filters));

            $data = \App\Helpers\CacheHelper::tags(['customers'])->remember($cacheKey, 60, function () use ($filters) {
                $paginator = $this->customerService->getAllCustomers($filters);
                return [
                    'items' => CustomerResource::collection($paginator)->resolve(),
                    'pagination' => [
                        'total' => $paginator->total(),
                        'per_page' => $paginator->perPage(),
                        'current_page' => $paginator->currentPage(),
                        'last_page' => $paginator->lastPage(),
                        'has_more' => $paginator->hasMorePages(),
                    ],
                ];
            });

            return ApiResponse::success('Customers retrieved successfully.', $data);
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

            $accountService = app(\App\Services\Finance\AccountService::class);
            $data = $accountService->getAccountStatement($customerAccount, $request->all());

            return ApiResponse::success('تم استدعاء كشف حساب العميل التفصيلي بنجاح.', [
                'customer' => new CustomerResource($customer),
                'stats' => $data['stats'],
                'items' => \App\Http\Resources\Finance\AccountEntryResource::collection($data['items']),
                'pagination' => $data['pagination'],
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
            'exchange_rate' => 'nullable|numeric|min:0.000001',
            'converted_amount' => 'nullable|numeric|min:0.01',
        ]);

        try {
            return DB::transaction(function () use ($customer, $validated) {
                // Ensure customer has a ledger account
                $customerAccount = $customer->account_id ? Account::find($customer->account_id) : null;
                if (! $customerAccount) {
                    $moduleStrForType = $validated['module'] ?? 'flight';
                    $resolvedModuleType = match(strtolower($moduleStrForType)) {
                        'bus' => 'bus',
                        'fawry' => 'fawry',
                        'online', 'online_service' => 'online',
                        'wallet', 'wallet_transfer' => 'wallet_transfer',
                        'visa' => 'visas',
                        'hajj', 'umrah', 'hajj_umra' => 'hajj_umra',
                        default => 'flights', // specific module under tourism
                    };

                    $customerAccount = Account::create([
                        'name' => 'حساب العميل: '.$customer->full_name,
                        'type' => AccountType::Customer,
                        'balance' => 0,
                        'currency' => 'EGP',
                        'is_active' => true,
                        'owner_type' => Account::OWNER_TYPE_OWNER,
                        'module_type' => $resolvedModuleType,
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

                $fromCurrency = strtoupper((string) $fromAccount->currency);
                $toCurrency = strtoupper((string) $toAccount->currency);

                $hasConversion = ($fromCurrency !== $toCurrency);

                $journalAmount = (float) $validated['amount'];
                $journalConverted = null;

                if ($hasConversion) {
                    $exchangeRate = (float) ($validated['exchange_rate'] ?? 1.0);
                    $convertedAmount = (float) ($validated['converted_amount'] ?? ($journalAmount * $exchangeRate));

                    if ($type === 'receipt') {
                        // Customer (EGP) transfers money to Bank (foreign currency, e.g. KWD)
                        // amount deducted from Customer is $convertedAmount (EGP value)
                        // amount added to Bank is $journalAmount (KWD value)
                        $journalAmount = $convertedAmount;
                        $journalConverted = (float) $validated['amount'];
                    } else {
                        // Bank (foreign currency, e.g. KWD) transfers money to Customer (EGP)
                        // amount deducted from Bank is $journalAmount (KWD value)
                        // amount added to Customer is $convertedAmount (EGP value)
                        $journalConverted = $convertedAmount;
                    }
                }

                $notes = $validated['notes'] ?? ($type === 'payment'
                    ? ('سند صرف - دفع للعميل: '.$customer->full_name)
                    : ("سند قبض - تسديد مديونية عميل {$moduleLabel}: ".$customer->full_name));

                if ($hasConversion) {
                    $foreignCurrency = $fromCurrency === 'EGP' ? $toCurrency : $fromCurrency;
                    $foreignAmount = $type === 'payment' ? $journalAmount : $journalConverted;
                    $egpAmount = $type === 'payment' ? $journalConverted : $journalAmount;
                    $rateStr = number_format($exchangeRate ?? 1.0, 4);
                    
                    $conversionNote = sprintf(" (سعر الصرف: %s - المبلغ: %.2f %s = %.2f EGP)", 
                        $rateStr, 
                        $foreignAmount, 
                        $foreignCurrency, 
                        $egpAmount
                    );
                    $notes .= $conversionNote;
                }

                $transactionService = app(TransactionService::class);
                $transaction = $transactionService->recordJournalTransfer([
                    'amount' => $journalAmount,
                    'converted_amount' => $journalConverted,
                    'exchange_rate' => $validated['exchange_rate'] ?? null,
                    'from_account_id' => $fromId,
                    'to_account_id' => $toId,
                    'allow_from_negative' => true, // Customer debt can go negative (i.e. they pay extra, becoming in credit)
                    'module' => $moduleEnum->value,
                    'notes' => $notes,
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
