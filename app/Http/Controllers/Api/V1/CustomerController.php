<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountType;
use App\Enums\FlightBookingStatus;
use App\Enums\TransactionModule;
use App\Enums\TransactionType;
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
use App\Models\Transaction;
use App\Services\CustomerService;
use App\Services\Finance\LedgerEntryDescriptionResolver;
use App\Services\Finance\TransactionService;
use App\Support\Finance\AccountModuleDivision;
use App\Support\Finance\SplitReceiptMarker;
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
            'flight_booking_id' => 'nullable|integer|exists:flight_bookings,id',
            'allocations' => 'nullable|array',
            'allocations.*.flight_booking_id' => 'required_with:allocations|integer|exists:flight_bookings,id',
            'allocations.*.amount' => 'required_with:allocations|numeric|min:0.01',
            'allocations.*.notes' => 'nullable|string|max:500',
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
                $isReceipt = ($type === 'receipt');

                // ─────────────────────────────────────────────────────────────────
                // PHASE 8 (2026-11-03) — الأقفال التشاؤمية (Pessimistic Locking)
                // ─────────────────────────────────────────────────────────────────
                //
                // قبل أي قراءة للرصيد الدائن، نقفل نطاق العميل كاملاً:
                //   ① صف العميل (customers)
                //   ② حسابه المالي + حساب الخزينة (accounts) — بترتيب المعرّف
                //      تصاعدياً كما يفعل `recordJournalTransfer`، تفادياً للجمود
                //   ③ لاحقاً: قراءة دفتر الرصيد بـ FOR UPDATE
                //
                // الفائدة: طلبان متزامنان للصرف لنفس العميل لا يمرّان معاً —
                // الثاني ينتظر القفل، ثم يقرأ الرصيد المحدَّث فيُرفض إن لم
                // يعد كافٍ ⇒ لا رصيد دائن سالب ولا عجز نقدي في الخزينة.
                // ─────────────────────────────────────────────────────────────────
                $lockedCustomer = Customer::where('id', $customer->id)->lockForUpdate()->firstOrFail();

                // ترتيب تصاعدي بمعرّف الحساب (نفس ترتيب recordJournalTransfer)
                $accountIds = [$customerAccount->id, (int) $toAccount->id];
                sort($accountIds);
                $lockedAccounts = Account::whereIn('id', array_unique($accountIds))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $customerAccount = $lockedAccounts->get($customerAccount->id) ?? $customerAccount;
                $toAccount = $lockedAccounts->get((int) $toAccount->id) ?? $toAccount;
                $fromAccount = $customerAccount;
                $customer = $lockedCustomer;

                // ─────────────────────────────────────────────────────────────────
                // اتجاه القيد المالي (Phase 5 — 2026-11-03)
                //
                // سند القبض (receipt): العميل يدفع لنا
                //     مدين  حساب العميل  →  دائن الخزينة/البنك
                //     (من = حساب العميل، إلى = الخزينة)
                //
                // سند الصرف (payment): نحن نصرف للعميل من رصيده الدائن
                //     مدين  الخزينة/البنك  →  دائن حساب العميل
                //     (من = الخزينة، إلى = حساب العميل)
                //
                // `recordJournalTransfer` يترجم (من → إلى) إلى (مدين على from /
                // دائن على to) بحسب عرف المشروع: balance = credit − debit.
                //
                // ملاحظة (TX-201): الاتجاه "العميل دائماً من" كان يجعل سند
                // الصرف يُنزل رصيد حساب العميل بدل أن يرفعه. ومع دفتر الرصيد
                // الدائن الموحد (CustomerCreditLedger) صار هذا خللاً بنيوياً:
                // الصرف يجب أن (1) يرفع رصيد حساب العميل، و(2) ينقص الرصيد
                // الدائن المتاح بمقدار المبلغ المصروف — وإلا اختلف الدفتر عن
                // المعادلة المحاسبية (-Account.balance = L − D).
                // ─────────────────────────────────────────────────────────────────
                $fromId = $isReceipt ? $fromAccount->id : $toAccount->id;
                $toId   = $isReceipt ? $toAccount->id   : $fromAccount->id;

                $moduleStr = $validated['module'] ?? 'flight';
                $moduleEnum = TransactionModule::tryFrom($moduleStr) ?? TransactionModule::Flight;

                $moduleLabel = $moduleEnum->label();

                // ─────────────────────────────────────────────────────────────────
                // PHASE 8 (2026-11-03) — عملة المرجع القاطعة (Multi-Currency Guard)
                // ─────────────────────────────────────────────────────────────────
                //
                // تسمية المتغيرات هنا كانت ملتبسة (from/to حسب اتجاه القيد لا
                // حسب دور الحساب)، فكانت تُقرأ خطأً: عملة حساب العميل ليست
                // عملة الخزينة. لذلك نسمّيها صراحةً:
                //
                //   $customerCurrency = عملة حساب العميل (مرجع دفتر الرصيد الدائن)
                //   $treasuryCurrency = عملة حساب الخزينة/البنك (طرف السند)
                //
                // المرجع المحاسبي لقيد واحد: الرصيد الدائن يُقارَن ويُسجَّل
                // بعملة حساب العميل دائماً — لا بعملة الطرف الآخر.
                // ─────────────────────────────────────────────────────────────────
                $customerCurrency = strtoupper((string) ($customerAccount->currency ?: 'EGP'));
                $treasuryCurrency = strtoupper((string) ($toAccount->currency ?: 'EGP'));

                // أسماء قديمة داخل هذا النطاق فقط (لتفادي تعديل بقية الكود):
                //   fromAccount = حساب العميل، toAccount = حساب الخزينة.
                $fromCurrency = $customerCurrency;
                $toCurrency = $treasuryCurrency;

                $hasConversion = ($customerCurrency !== $treasuryCurrency);

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

                // ═════════════════════════════════════════════════════════════════
                // PHASE 5 (2026-11-03) — صرف رصيد العميل الدائن (سند صرف)
                // ═════════════════════════════════════════════════════════════════
                //
                // مبلغ القيد على حساب العميل ( بعملة حساب العميل ):
                //   - نفس العملة    : $journalAmount
                //   - مع تحويل عملة : $convertedAmount (قيمة حساب العميل)
                $customerLegAmount = $hasConversion ? (float) $convertedAmount : (float) $journalAmount;

                // نطاق الشركة: رصيد الشركة مُشترك بين كل موظفيها — نفس منطق
                // `Customer::getAvailableCreditAttribute` و`RefundService::resolveCompanyId`.
                $companyId = $customer->type === \App\Enums\CustomerType::Company
                    ? (int) $customer->id
                    : ($customer->company_id ? (int) $customer->company_id : null);

                $lockedAvailableCredit = null;

                if (! $isReceipt) {
                    // ── حارس ما قبل الترحيل (Pre-flight guard) ───────────────────
                    // لا يجوز صرف أكثر من الرصيد الدائن المتاح للعميل، وإلا
                    // صار رصيد العميل الدائن سالباً واختلف الدفتر عن المعادلة
                    // المحاسبية (-Account.balance = L − D).
                    //
                    // PHASE 8 — قراءتان قفلتان:
                    //   ① قراءة قفلية للرصيد الدائن (`lockedAvailableCredit`):
                    //      ترى آخر حالة مُلتزَمة وتمنع أي إدراج حركة دفتر لنفس
                    //      العميل حتى انتهاء المعاملة — فلا يمرّر طلبان
                    //      متزامنان معاً ⇒ لا صرف مزدوج ولا رصيد سالب.
                    //   ② قراءة قفلية لرصيد الخزينة: لا يجوز أن يقل رصيد
                    //      الخزينة عن الصفر بالصرف (نفس حارس `RefundService`
                    //      — البند 8) — فتبقى الخزينة آمنة نقدياً.
                    $availableCredit = \App\Models\CustomerCreditLedger::lockedAvailableCredit(
                        (int) $customer->id,
                        $companyId,
                        $customerCurrency
                    );
                    $lockedAvailableCredit = $availableCredit;

                    if ($customerLegAmount > $availableCredit + 0.005) {
                        throw new \App\Exceptions\BusinessLogicException(
                            "المبلغ المطلوب صرفه يتجاوز الرصيد الدائن المتاح للعميل ({$availableCredit})",
                            [
                                'customer_id' => (int) $customer->id,
                                'company_id' => $companyId,
                                'requested_amount' => $customerLegAmount,
                                'available_credit' => $availableCredit,
                                'currency' => $customerCurrency,
                            ]
                        );
                    }

                    // ── حارس النقد في الخزينة (Cash Safety — Phase 8) ───────────
                    // الطرف_OUT من الصرف هو الخزينة/البنك: `$journalAmount`
                    // بعملة الخزينة دائماً (سند الصرف: من = الخزينة).
                    $treasuryAvailable = (float) $toAccount->balance;
                    $cashOutAmount = (float) $journalAmount;

                    if ($treasuryAvailable < $cashOutAmount - 0.005) {
                        throw new \App\Exceptions\BusinessLogicException(
                            sprintf(
                                'رصيد الخزينة (%s %.2f) لا يكفي لصرف %.2f — لا يمكن أن يقل رصيد الخزينة عن الصفر.',
                                $treasuryCurrency,
                                $treasuryAvailable,
                                $cashOutAmount
                            ),
                            [
                                'account_id' => (int) $toAccount->id,
                                'available' => $treasuryAvailable,
                                'required' => $cashOutAmount,
                                'currency' => $treasuryCurrency,
                            ]
                        );
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

                // REVENUE RECOGNITION HANDLING:
                //
                // Office modules (bus, fawry, online, wallet_transfer) recognize revenue
                // on accrual basis when operations/bookings are created via income-clearing.
                // Repaying debt (سند قبض) is a balance-sheet asset exchange (AR -> Treasury)
                // and must NOT be recorded as type='income' (otherwise revenue is double-counted
                // in P&L, inflating profits and causing false deficits in the office trial balance).
                //
                // Tourism division modules (flight, hajj_umra, visas) use cash-basis revenue
                // recognition (FIN-3, 2026-08-29) where revenue is recognized upon debt payment.
                $resolvedModuleType = AccountModuleDivision::resolveModuleTypeKey(null, $moduleEnum->value);
                $isOfficeModule = in_array($resolvedModuleType, AccountModuleDivision::OFFICE, true)
                    || in_array($moduleEnum->value, ['bus', 'fawry', 'online', 'wallet', 'wallet_transfer', 'office'], true);

                // ═══════════════════════════════════════════════════════════════
                // PHASE 9 (2026-11-03) — عزل فائض سند القبض عن قائمة الأرباح
                // ═══════════════════════════════════════════════════════════════
                //
                // المشكلة المحاسبية التي يعالجها هذا الكود:
                //
                // سند قبض طيران بقيمة 15,000 ج.م، مخصَّص منه 5,000 ج.م لحجز
                // طيران، والمتبقي 10,000 ج.م رصيد متاح للعميل. قبل Phase 9
                // كان السند كاملاً يُسجَّل كإيراد:
                //
                //     $transactionService->recordIncome(['amount' => 15000, …]);
                //
                // و`ProfitLossReportService::classify()` يصنّف أي قيد يحمل
                // type='income' كإيراد مبيعات فوري ⇒ ضخّم الإيراد بـ 10,000
                // وصافي الربح بأرباح وهمية لم تتحقق.
                //
                // المعالجة المحاسبية الصحيحة (IFRS 15):
                //   • الجزء المخصَّص على الحجوزات فقط = إيراد محقق (Recognized
                //     Revenue) — هو ما يقابل التزام أداء مُنجز.
                //   • الجزء الفائض غير المطبَّق = التزام في الميزانية العمومية
                //     (Customer Deposit / Liability) — لا يجوز دخوله قائمة الأرباح
                //     لأنه لا يقابله أي التزام أداء حتى الآن.
                //
                // ملاحظة على الترتيب: خطة التخصيص لازم تُحسب **قبل** ترحيل
                // القيد، لأن القيد نفسه هو ما يجب تقسيمه إلى شقين داخل نفس
                // المعاملة (DB::transaction) — وإلا لقّدنا السند كاملاً كإيراد
                // ثم نضطر لعكس جزء منه (وهو ما سبّب تضخيم الأرباح).
                // ═══════════════════════════════════════════════════════════════
                $isFlightReceipt = ($moduleEnum === TransactionModule::Flight && $isReceipt && $journalAmount > 0);

                $allocationItems = null;
                $allocationBookingId = null;
                $plannedAllocationTotal = 0.0;

                if ($isFlightReceipt) {
                    if (! empty($validated['allocations'])) {
                        $allocationItems = $validated['allocations'];

                        foreach ($allocationItems as $item) {
                            $plannedAllocationTotal += (float) ($item['amount'] ?? 0);
                        }
                    } elseif (! empty($validated['flight_booking_id'])) {
                        $allocationBookingId = (int) $validated['flight_booking_id'];

                        // التخصيص يُقاس بعملة الحجز (ج.م) = قيمة شق حساب العميل
                        // من السند (وهو $journalAmount في حالة القبض).
                        $plannedAllocationTotal = (float) $journalAmount;
                    }

                    $plannedAllocationTotal = round($plannedAllocationTotal, 2);
                }

                // سقف التخطيط: لو طلب المُرسِل تخصيصاً أكبر من قيمة السند،
                // نترك شق الإيراد مساوياً للسند كاملاً — عندها يرمي
                // `FlightPaymentAllocationService` خطأ «التخصيص الأكبر من
                // السند» (422) وتُلغى العملية كاملة، تماماً كما قبل Phase 9.
                $splitRevenueAmount = min($plannedAllocationTotal, (float) $journalAmount);
                $splitSurplusAmount = round($journalAmount - $splitRevenueAmount, 2);

                // نسبة شق الخزينة (بعملة الحساب المستقبِل) لكل وحدة من شق حساب
                // العميل — تُستخدم لتقسيم المبلغ المحوّل على الشقين بلا فرق
                // (مجموع الشقين = المبلغ الأصلي بالضبط).
                $convertedRatio = null;
                if ($hasConversion && (float) $journalAmount > 0) {
                    $convertedRatio = (float) $journalConverted / (float) $journalAmount;
                }

                $revenueTransaction = null;
                $surplusTransaction = null;

                if ($isOfficeModule || ! $isReceipt) {
                    // ─────────────────────────────────────────────────────────────
                    // سند الصرف (payment) ليس إيراداً أبداً: هو تحويل نقدي من
                    // الخزينة/البنك إلى رصيد العميل الدائن. لو قُوّد عبر
                    // `recordIncome` لصُنِّف تلقائياً كـ "revenue" في قائمة
                    // الأرباح (ProfitLossReportService::classify) — أي إيراد
                    // مكرر عن دفعة سبق الاعتراف بإيرادها عند استلامها.
                    //
                    // ملاحظة: سند القبض لوحدات المكتب يبقى Transfer أيضاً
                    // (مقايضة أصول: ذمم → خزينة) كما كان قبل Phase 5.
                    // ─────────────────────────────────────────────────────────────
                    $transaction = $transactionService->recordJournalTransfer([
                        'amount' => $journalAmount,
                        'converted_amount' => $journalConverted,
                        'exchange_rate' => $validated['exchange_rate'] ?? null,
                        'from_account_id' => $fromId,
                        'to_account_id' => $toId,
                        'allow_from_negative' => true,
                        'module' => $moduleEnum->value,
                        'related_type' => Customer::class,
                        'related_id' => $customer->id,
                        'notes' => $notes,
                        'created_by' => Auth::id() ?? 1,
                    ]);
                } elseif ($splitSurplusAmount > 0.005) {
                    // ─────────────────────────────────────────────────────────────
                    // PHASE 9 — سند قبض فيه فائض: القيد يُقسَّم إلى شقين.
                    //
                    // ① شق الإيراد المحقق (type='income') — المبلغ المخصَّص
                    //    على حجوزات الطيران فقط. هذا هو الإيراد المحقق.
                    // ② شق الأمانات/الفائض (type='transfer') — مبلغ محتفظ به
                    //    للعميل (Customer Deposit / Liability) — محايد تماماً
                    //    في قائمة الأرباح.
                    //
                    // مجمع القيدين على حساب العميل = المبلغ كله، وعلى الخزينة
                    // = المبلغ كله — فلا فرق في الأرصدة، فقط في تصنيف الإيراد.
                    // ─────────────────────────────────────────────────────────────
                    $revenueConverted = null;

                    if ($splitRevenueAmount > 0.005) {
                        $revenueConverted = $convertedRatio !== null
                            ? round($splitRevenueAmount * $convertedRatio, 2)
                            : null;

                        $revenueTransaction = $transactionService->recordIncome([
                            'amount' => $splitRevenueAmount,
                            'converted_amount' => $revenueConverted,
                            'exchange_rate' => $hasConversion ? $exchangeRate : ($validated['exchange_rate'] ?? null),
                            'to_account_id' => $toId,
                            'contra_account_id' => $fromId,
                            'allow_contra_negative' => true, // preserve pre-fix AR-can-go-negative convention
                            'module' => $moduleEnum->value,
                            'related_type' => Customer::class,
                            'related_id' => $customer->id,
                            'notes' => $notes . " [إيراد مبيعات مخصص للحجوزات: {$splitRevenueAmount}]",
                            'created_by' => Auth::id() ?? 1,
                        ]);
                    }

                    // شق الأمانات: الباقي من شق الخزينة بعد قَطع شق الإيراد
                    // (حساب المبلغ المحوّل يتم بالطرح لضمان عدم وجود فرق).
                    $surplusConverted = null;
                    if ($convertedRatio !== null) {
                        $surplusConverted = round((float) $journalConverted - (float) ($revenueConverted ?? 0), 2);
                    }

                    $surplusTransaction = $transactionService->recordJournalTransfer([
                        'amount' => $splitSurplusAmount,
                        'converted_amount' => $surplusConverted,
                        'exchange_rate' => $hasConversion ? $exchangeRate : ($validated['exchange_rate'] ?? null),
                        'from_account_id' => $fromId,
                        'to_account_id' => $toId,
                        'allow_from_negative' => true,
                        'type' => TransactionType::Transfer->value,
                        'module' => $moduleEnum->value,
                        'related_type' => Customer::class,
                        'related_id' => $customer->id,
                        // ملاحظات السند الأصلية تُحفظ مع شق الأمانات — وإلا
                        // ضاع البحث في كشف الحساب بالعبارة الأصلية.
                        'notes' => $notes.' — فائض سند قبض محتفظ به كأمانات ورصيد متاح للعميل #'.$customer->id
                            . ($revenueTransaction instanceof Transaction
                                ? ' '.SplitReceiptMarker::stamp((int) $revenueTransaction->id)
                                : ''),
                        'created_by' => Auth::id() ?? 1,
                    ]);

                    $transaction = $revenueTransaction ?? $surplusTransaction;
                } else {
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

                    // سند غير مجزّأ: القيد نفسه هو شق الإيراد.
                    $revenueTransaction = $transaction;
                }

                // ✈️ Flight booking-level payment allocation (Phase 1):
                // Allocate the receipt to specific flight booking(s) if provided.
                // If not provided, the receipt remains unallocated on the customer account.
                //
                // PHASE 9: التخصيص يُربط دائماً بشق الإيراد (القيد من نوع
                // 'income') — لأنه الشق الذي يمثّل قيمة السند المطبَّقة على
                // الحجوزات، وهو المرجع الذي يحسبه `FlightPaymentAllocationService`
                // لتحديد سقف التخصيص المتاح.
                $flightAllocatedTotal = 0.0;
                if ($isFlightReceipt) {
                    $allocationService = app(\App\Services\Flight\FlightPaymentAllocationService::class);
                    $allocationTarget = $revenueTransaction ?? $transaction;

                    if ($allocationItems !== null) {
                        $createdAllocations = $allocationService->allocateMultiple($allocationTarget, $allocationItems, Auth::id() ?? 1);
                        $flightAllocatedTotal = (float) $createdAllocations->sum('amount');
                    } elseif ($allocationBookingId !== null) {
                        $createdAllocation = $allocationService->allocate(
                            $allocationTarget,
                            \App\Models\Flight\FlightBooking::findOrFail($allocationBookingId),
                            $plannedAllocationTotal,
                            Auth::id() ?? 1,
                            $validated['notes'] ?? null
                        );
                        $flightAllocatedTotal = (float) ($createdAllocation->amount ?? 0);
                    }
                }

                // ✈️ فائض سند القبض / دفعة مقدمة (Phase 4 — 2026-11-03):
                //
                // المعادلة المحاسبية (مصدر الحقيقة الوحيد):
                //     رصيد العميل الصافي = الرصيد الدائن المتاح (L) − الديون المفتوحة (D)
                //     ≡ -Account.balance
                //
                // السند قبض (receipt) يُنزل رصيد حساب العميل في الدفتر بمقدار
                // المبلغ كله. والجزء المخصَّص على حجوزات الطيران يُطفئ دين
                // الحجز (D ↓) — أما الجزء غير المخصَّص فهو مبلغ محتفظ به
                // للعميل (دفعة مقدمة / فائض)، فيجب أن يظهر كرصيد دائن متاح
                // في دفتر رصيد العميل الموحد، وإلا اختلف الدفتر عن المركز
                // المالي للعميل.
                //
                // PHASE 9: الفائض صار له **قيد مستقل** (شق الأمانات) هو
                // الذي يحمل حركة دفتر الرصيد — لا شق الإيراد. فحركة الدفتر
                // تُربط بمعرّف قيد الأمانات (وهو الذي يملك أصل النقد المحتفظ
                // به للعميل)، وبذلك يمكن عكسها عند إلغاء السند.
                if ($surplusTransaction instanceof Transaction) {
                    /** @var \App\Services\Finance\CustomerFinancialPositionService $positionService */
                    $positionService = app(\App\Services\Finance\CustomerFinancialPositionService::class);
                    $openDebt = $positionService->getOpenDebt((int) $customer->id);

                    $creditCurrency = $customerCurrency;
                    $excessOverDebt = round($splitSurplusAmount - $openDebt, 2);

                    \App\Models\CustomerCreditLedger::recordEntry([
                        'customer_id' => $customer->id,
                        'company_id'  => $companyId,
                        'source_type' => \App\Models\CustomerCreditLedger::SOURCE_OVERPAYMENT,
                        'source_id'   => $surplusTransaction->id,
                        'amount'      => $splitSurplusAmount,
                        'currency'    => $creditCurrency,
                        'notes'       => "فائض سند قبض #{$surplusTransaction->id} — رصيد أمانات متاح"
                            .($excessOverDebt > 0.005 ? " (فائض عن الديون المفتوحة: {$excessOverDebt})" : ''),
                        'created_by'  => Auth::id() ?? 1,
                    ]);
                }

                // 💸 صرف من رصيد العميل الدائن (Phase 5 — 2026-11-03):
                //
                // سند الصرف (payment) ينقل النقد من الخزينة/البنك إلى حساب
                // العميل (دائن) — أي أن رصيد العميل في الدفتر يرتفع.
                // في المقابل يجب أن ينقص الرصيد الدائن المتاح (L) بمقدار
                // المبلغ المصروف، وإلا اختلف الدفتر عن المعادلة المحاسبية:
                //     -Account.balance = AvailableCredit (L) − OpenDebt (D)
                //
                // الحركة تُسجَّل بقيمة القيد على حساب العميل (عملة حساب العميل)
                // وبإشارة سالبة.
                //
                // PHASE 8: العملة هنا هي `work currency` حساب العميل دائماً
                // (وليس عملة الخزينة) — حتى يبقى دفتر الرصيد الدائن مطابقاً
                // لمعادلة دفتر الأستاذ (-Account.balance = L − D) حتى مع
                // تعدد العملات. والرصيد اللاحق يأتي من القراءة القفلية
                // نفسها (لا من تجميع غير مقفل).
                if (! $isReceipt && $customerLegAmount > 0) {
                    \App\Models\CustomerCreditLedger::recordEntry([
                        'customer_id' => $customer->id,
                        'company_id'  => $companyId,
                        'source_type' => \App\Models\CustomerCreditLedger::SOURCE_DISBURSEMENT,
                        'source_id'   => $transaction->id,
                        'amount'      => -$customerLegAmount,
                        'currency'    => $customerCurrency,
                        'notes'       => "سند صرف #{$transaction->id} — صرف من رصيد العميل الدائن",
                        'created_by'  => Auth::id() ?? 1,
                        'balance_after' => $lockedAvailableCredit !== null
                            ? round($lockedAvailableCredit - $customerLegAmount, 2)
                            : null,
                    ]);
                }

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
                    // PHASE 9: عند تجزئة السند (إيراد + أمانات) نُرجع معرّفي
                    // الشقين صراحةً حتى يتتبّع السند كاملاً (الإلغاء مثلاً).
                    'revenue_transaction_id' => $revenueTransaction?->id,
                    'surplus_transaction_id' => $surplusTransaction?->id,
                    'new_balance' => (float) $fromAccount->fresh()->balance,
                    'fawry_allocated' => round($fawryAllocatedTotal, 2),
                ]);
            });
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }
}
