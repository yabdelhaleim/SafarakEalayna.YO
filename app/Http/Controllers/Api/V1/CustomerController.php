<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountType;
use App\Enums\FlightBookingStatus;
use App\Enums\TransactionModule;
use App\Helpers\ApiResponse;
use App\Helpers\CacheHelper;
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
use App\Models\Fawry\FawryTransaction;
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

            // Cache removed (2026-08-11): the 60s TTL was causing production
            // issues after deploys — the /api/v1/customers response kept
            // returning stale results until either the TTL expired or an
            // operator manually ran `php artisan cache:clear`. The query
            // itself is not slow enough to justify caching, and customers
            // are an actively-edited entity where freshness matters.
            //
            // If we ever need to re-add caching here, prefer:
            //   1. Tag-based invalidation via ClearsCache trait on Customer
            //      model (already wired) so writes invalidate the cache
            //      automatically — no manual cache:clear needed.
            //   2. Short TTL (5s max) and document the deploy caveat.
            $paginator = $this->customerService->getAllCustomers($filters);

            $data = [
                'items' => CustomerResource::collection($paginator)->resolve(),
                'pagination' => [
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'has_more' => $paginator->hasMorePages(),
                ],
            ];

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

                $type = $validated['type'] ?? 'receipt'; // 'receipt' (سند قبض) or 'payment' (سند صرف)

                // Both receipt and payment reduce the customer AR balance under
                // the project's convention (positive balance = receivable).
                // - سند قبض (receipt): customer pays us → customer balance ↓
                // - سند صرف (payment): we pay/refund customer → customer balance ↓
                // The label differs; the journal direction does not. Previously
                // this branch swapped from/to for type='payment', which made the
                // customer's balance grow by `amount` instead of shrink by `amount`
                // (Bug TX-201 — 119,047 + 50,000 = 169,047 instead of 69,047).
                $fromId = $fromAccount->id; // customer is always the journal "from"
                $toId   = $toAccount->id;   // treasury/bank is always the journal "to"

                $moduleStr = $validated['module'] ?? 'flight';
                $moduleEnum = TransactionModule::tryFrom($moduleStr) ?? TransactionModule::Flight;

                $moduleLabel = $moduleEnum->label();

                $fromCurrency = strtoupper((string) $fromAccount->currency);
                $toCurrency = strtoupper((string) $toAccount->currency);

                $hasConversion = ($fromCurrency !== $toCurrency);

                $journalAmount = (float) $validated['amount'];
                $journalConverted = null;

                if ($hasConversion) {
                    // ─────────────────────────────────────────────────────────
                    // SAFE FX RULE (FIX 2026-08-21): cross-currency debt
                    // settlements MUST carry explicit `converted_amount` +
                    // `exchange_rate`. Reject when the caller did not supply
                    // either, instead of silently coercing a missing rate to
                    // 1.0 (the pre-fix vulnerable behaviour).
                    //
                    // The caller can supply either:
                    //   (a) `converted_amount` (preferred — caller already
                    //        computed via CurrencyService::convert()), OR
                    //   (b) `exchange_rate` (we derive converted_amount from
                    //        the supplied rate + journalAmount).
                    // ─────────────────────────────────────────────────────────
                    $rawConverted = $validated['converted_amount'] ?? null;
                    $rawRate = $validated['exchange_rate'] ?? null;
                    $hasConverted = $rawConverted !== null
                        && is_numeric($rawConverted)
                        && (float) $rawConverted > 0;
                    $hasRate = $rawRate !== null
                        && is_numeric($rawRate)
                        && (float) $rawRate > 0;

                    if (! $hasConverted && ! $hasRate) {
                        return ApiResponse::error(
                            'لا يمكن تنفيذ عملية بعملات مختلفة دون تحديد سعر الصرف أو المبلغ المحوّل. '
                            .'عملة المصدر: '.$fromCurrency.'، عملة الهدف: '.$toCurrency.'. '
                            .'يجب استخدام CurrencyService::convert() لتحويل المبلغ، أو تمرير converted_amount/exchange_rate صراحةً بقيم موجبة.',
                            [
                                'from_account_id' => $fromId,
                                'to_account_id' => $toId,
                                'from_currency' => $fromCurrency,
                                'to_currency' => $toCurrency,
                                'amount' => $journalAmount,
                            ],
                            422
                        );
                    }

                    if ($hasConverted) {
                        $convertedAmount = (float) $rawConverted;
                        $exchangeRate = $hasRate ? (float) $rawRate : ($convertedAmount / max($journalAmount, 0.000001));
                    } else {
                        $exchangeRate = (float) $rawRate;
                        $convertedAmount = $journalAmount * $exchangeRate;
                    }

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
                    $rateStr = number_format($exchangeRate, 4);
                    
                    $conversionNote = sprintf(" (سعر الصرف: %s - المبلغ: %.2f %s = %.2f EGP)", 
                        $rateStr, 
                        $foreignAmount, 
                        $foreignCurrency, 
                        $egpAmount
                    );
                    $notes .= $conversionNote;
                }

                $transactionService = app(TransactionService::class);

                // REVENUE RECOGNITION FIX (FIN-3, 2026-08-29):
                //
                // The old code called `recordJournalTransfer` with `type='Transfer'`
                // (the default). For cash-basis revenue recognition in
                // ProfitLossReportService::classify() a transaction must either
                //   (a) be `type='Income'` (short-circuit → 'revenue'), or
                //   (b) touch the income_clearing account on one leg.
                //
                // A direct AR → treasury transfer satisfies NEITHER rule, so the
                // dashboard P&L silently dropped the revenue line and reported
                // profit = -cogs even though cash actually arrived. Switching to
                // `recordIncome` with `contra_account_id = customer AR` produces
                // exactly the same ledger shape (AR loses balance, treasury
                // gains) but with `type='Income'` — the engine's short-circuit
                // recognizes it as revenue, mirroring the proven
                // FlightBookingService::addPayment() pattern.
                //
                // Safety guarantees preserved:
                //   • Customer AR balance still decreases (from_account_id = AR).
                //   • Treasury still gains (to_account_id = treasury).
                //   • Multi-currency converted_amount + exchange_rate still threaded.
                //   • `allow_from_negative` (re-keyed as allow_contra_negative)
                //     preserves the convention that customer AR may go negative
                //     when they pay more than they owe.
                //   • related_type/related_id give the duplicate-Income guard a
                //     proper slot per debt settlement — a second payDebt() with
                //     the same (customer_id, ?) is correctly rejected as dup.
                $transaction = $transactionService->recordIncome([
                    'amount' => $journalAmount,
                    'converted_amount' => $journalConverted,
                    'exchange_rate' => $validated['exchange_rate'] ?? null,
                    'to_account_id' => $toId,
                    'contra_account_id' => $fromId,
                    'allow_contra_negative' => true, // preserve pre-fix AR-can-go-negative convention
                    'module' => $moduleEnum->value,
                    'related_type' => Customer::class,
                    'related_id' => $customer->id,
                    'notes' => $notes,
                    'created_by' => Auth::id() ?? 1,
                ]);

                // 🛡️ Fawry per-transaction amount sync (BUG FIX 2026-08-28):
                //
                // Before this fix, a registered Fawry customer's pay-debt
                // through /customers/{id}/pay-debt updated ONLY the GL
                // (customer AR → cashbox) but did NOT bump the
                // `fawry_transactions.amount` column on the underlying
                // transaction rows. This caused a desync between:
                //   • customerBalances endpoint  → reads GL → shows paid
                //   • FawryDashboard recent ops → reads fawry_transactions.amount
                //                                    → still shows "غير مكتمل"
                //   • total_payments KPI sum     → reads fawry_transactions.amount
                //                                    → still shows 0
                //
                // The walk-in flow (FawryWalkInPaymentController::payDebt) has
                // the same FIFO logic; this mirrors it for registered customers.
                //
                // Allocation is FIFO (oldest unpaid transaction first) and only
                // touches transactions where selling_price > amount (i.e. still
                // has outstanding debt). Soft-deleted rows are excluded so we
                // don't resurrect ghost balances.
                $fawryAllocatedTotal = 0.0;
                if ($moduleEnum === TransactionModule::Fawry && $type === 'receipt' && $journalAmount > 0) {
                    $remaining = (float) $journalAmount;

                    $fawryTxs = DB::table('fawry_transactions')
                        ->where('client_id', $customer->id)
                        ->whereNull('deleted_at')
                        ->whereRaw('selling_price > amount')
                        ->orderBy('created_at', 'asc')
                        ->orderBy('id', 'asc')
                        ->lockForUpdate()
                        ->get();

                    foreach ($fawryTxs as $fawryTx) {
                        if ($remaining <= 0.005) {
                            break;
                        }
                        $gap = (float) $fawryTx->selling_price - (float) $fawryTx->amount;
                        if ($gap <= 0) {
                            continue;
                        }
                        $allocate = min($remaining, $gap);
                        $allocate = round($allocate, 2);

                        DB::table('fawry_transactions')
                            ->where('id', $fawryTx->id)
                            ->update([
                                'amount' => DB::raw('amount + '.(float) $allocate),
                                'updated_at' => now(),
                            ]);

                        $fawryAllocatedTotal = round($fawryAllocatedTotal + $allocate, 2);
                        $remaining = round($remaining - $allocate, 2);
                    }

                    // Bust dashboard + transaction caches so the bumped
                    // `amount` is reflected in the next fetch (the
                    // /fawry/dashboard endpoint itself isn't cached, but
                    // /fawry/transactions, /customers and the finance
                    // listings are — flush them all to stay consistent).
                    if ($fawryAllocatedTotal > 0) {
                        CacheHelper::flushTags(['accounts', 'dashboard', 'fawry_transactions']);
                        CacheHelper::flushNamespace();
                    }
                }

                return ApiResponse::success($type === 'payment' ? 'تم صرف المبلغ للعميل بنجاح وقيد سند الصرف.' : 'تم سداد المبلغ بنجاح وقيد سند القبض.', [
                    'transaction_id' => $transaction->id,
                    'new_balance' => (float) $fromAccount->fresh()->balance,
                    'fawry_allocated' => round($fawryAllocatedTotal, 2),
                ]);
            });
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }
}
