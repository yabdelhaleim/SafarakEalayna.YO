<?php

namespace App\Services\HajjUmra;

use App\Enums\AccountType;
use App\Enums\HajjUmraStatus;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Customer;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\HajjUmra\UmrahSupplier;
use App\Models\HajjUmraBooking;
use App\Models\HajjUmraPayment;
use App\Models\Program;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\TransactionService;
use App\Services\Finance\CurrencyService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HajjUmraBookingService
{
    public function __construct(
        protected TransactionService $transactions,
        protected CurrencyService $currencyService,
    ) {}

    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = HajjUmraBooking::with([
            'customer',
            'companion',
            'program.executingCompany',
            'program.tripSupervisor',
            'program.accommodationTypeRow',
            'employee',
            'account',
            'payments.account',
        ]);

        $this->applyFilters($query, $filters);

        $perPage = (int) min($filters['per_page'] ?? 15, 100);

        return $query->latest()->paginate($perPage);
    }

    protected function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['program_id'])) {
            $query->where('program_id', $filters['program_id']);
        }
        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }
        if (! empty($filters['from_date'])) {
            $query->whereDate('created_at', '>=', $filters['from_date']);
        }
        if (! empty($filters['to_date'])) {
            $query->whereDate('created_at', '<=', $filters['to_date']);
        }
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $query->whereHas('customer', function ($q) use ($term) {
                $q->where('full_name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('passport_number', 'like', "%{$term}%");
            });
        }
        if (! empty($filters['program_type'])) {
            $pt = strtolower((string) $filters['program_type']);
            $query->whereHas('program', fn ($q) => $q->whereRaw('LOWER(program_type) = ?', [$pt]));
        }
    }

    public function find(int $id): HajjUmraBooking
    {
        return HajjUmraBooking::with([
            'customer',
            'companion',
            'program.executingCompany',
            'program.tripSupervisor',
            'program.accommodationTypeRow',
            'employee',
            'account',
            'expenseTransaction',
            'incomeTransaction',
            'payments.account',
            'payments.transaction',
        ])->findOrFail($id);
    }

    /**
     * Create a Hajj/Umra booking with double-entry accounting:
     *  - recordExpense: تكلفة الشراء كمصروف من حساب الخزينة (تدفع للشركة المنفذة)
     *  - recordIncome: سعر البيع كإيراد إلى نفس الحساب (يُحصَّل من العميل)
     *
     * إذا كان initial_payment.amount > 0 يُسجَّل كدفعة مرتبطة بقيد دخل من الحساب نفسه.
     */
    public function create(array $data): HajjUmraBooking
    {
        return DB::transaction(function () use ($data) {
            $customer = $this->resolveCustomer($data['customer'] ?? null, $data['customer_id'] ?? null);

            $program = Program::findOrFail($data['program_id']);

            $purchase = (float) ($data['purchase_price'] ?? 0);
            $companionPurchase = (float) ($data['companion_purchase_price'] ?? 0);
            $selling = (float) ($data['selling_price'] ?? 0);
            $companionSelling = (float) ($data['companion_selling_price'] ?? 0);
            $accommodationExtra = (float) ($data['accommodation_extra_charge'] ?? 0);

            $totalPurchase = $purchase + $companionPurchase;
            $totalSelling = $selling + $companionSelling + $accommodationExtra;
            $profit = round($totalSelling - $totalPurchase, 2);

            $accountId = (int) ($data['account_id'] ?? 0);
            if ($accountId === 0) {
                $vault = Account::getModuleVault('hajj_umra');
                if (! $vault) {
                    throw new \RuntimeException('لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى اختيار حساب أو ضبط الخزينة الرسمية.');
                }
                $accountId = $vault->id;
            }

            $createdBy = Auth::id() ?? ($data['employee_id'] ?? null);

            // Wrapped in HajjUmraBooking::runProfitMutation() so the ModelProfitMutationGuard lets
            // the canonical `profit` write through — see HajjUmraBooking::booted()
            // saving observer.
            $booking = HajjUmraBooking::runProfitMutation(function () use ($customer, $program, $data, $purchase, $companionPurchase, $selling, $companionSelling, $profit, $accountId, $createdBy, $accommodationExtra) {
                return HajjUmraBooking::create([
                'customer_id' => $customer->id,
                'companion_customer_id' => $data['companion_customer_id'] ?? null,
                'program_id' => $program->id,
                'supplier_id' => $data['supplier_id'] ?? null,
                'module' => TransactionModule::HajjUmra->value,
                'purchase_price' => $purchase,
                'companion_purchase_price' => $companionPurchase,
                'selling_price' => $selling,
                'companion_selling_price' => $companionSelling,
                'profit' => $profit,
                'currency' => $data['currency'] ?? 'EGP',
                'per_person' => (bool) ($data['per_person'] ?? true),
                'accommodation_choice' => $data['accommodation_choice'] ?? 'standard',
                'accommodation_extra_charge' => $accommodationExtra,
                'status' => $data['status'] ?? HajjUmraStatus::Confirmed->value,
                'agent_name' => $data['agent_name'] ?? ($customer->full_name ?? ''),
                'notes' => $data['notes'] ?? null,
                'account_id' => $accountId,
                'employee_id' => $data['employee_id'] ?? $createdBy,
                'created_by' => $createdBy,
            ]);
            });

            // ─────────────────────────────────────────────────────────────────
            // BRIEF 5 — REGRESSION #1 FIX (2026-08-21):
            //   Phase 10.10 contract: booking creation does NOT validate the
            //   `currency` label — free-form strings like 'XXX' are accepted.
            //   Currency/FX validation belongs to the appropriate financial
            //   settlement/payment operation.
            //
            //   Pre-fix (Brief 4): passed `$booking->currency` to
            //   ensureCustomerAccount, which created a per-currency customer
            //   AR account in 'XXX'. The subsequent recordIncome / recordExpense
            //   then triggered a CROSS-CURRENCY transfer (EGP clearing →
            //   XXX customer AR) without FX data → BusinessLogicException
            //   → HTTP 422.
            //
            //   Post-fix (revised): resolve the customer AR currency from
            //   the EFFECTIVE clearing bucket that recordIncome /
            //   recordExpense will actually use — the same code path that
            //   picks the income/expense contra account. This guarantees
            //   same-currency on both sides of every booking-creation
            //   journal transfer:
            //     - 'XXX' booking → EGP_clearing (fallback) → EGP customer AR
            //     - 'USD' booking → USD_clearing (per-currency bucket)
            //                          → USD customer AR
            //     - 'EGP' booking → EGP_clearing → EGP customer AR
            //   No silent 1.0; no FX data needed at booking-creation time.
            // ─────────────────────────────────────────────────────────────────
            $bookingLedgerCurrency = 'EGP';
            $bookingCurrency = strtoupper((string) ($booking->currency ?? 'EGP'));
            $incomeContraId = app(\App\Services\Finance\LedgerClearingAccounts::class)
                ->incomeContraIdForModuleAndCurrency(TransactionModule::HajjUmra->value, $bookingCurrency);
            if ($incomeContraId) {
                $contraAccount = Account::find($incomeContraId);
                if ($contraAccount) {
                    $bookingLedgerCurrency = strtoupper((string) $contraAccount->currency);
                }
            }
            $customerAccount = $this->ensureCustomerAccount($customer->id, $bookingLedgerCurrency);

            // Save passenger breakdowns if any
            if (! empty($data['passengers']) && is_array($data['passengers'])) {
                foreach ($data['passengers'] as $p) {
                    $booking->passengers()->create([
                        'category' => $p['category'],
                        'count' => (int) $p['count'],
                        'unit_price' => (float) $p['unit_price'],
                        'subtotal' => (float) $p['subtotal'],
                    ]);
                }
            }

            $expenseAccountId = $accountId;
            $supplierId = $data['supplier_id'] ?? null;
            if ($supplierId) {
                $supplier = UmrahSupplier::find($supplierId);
                if ($supplier && $supplier->account_id) {
                    $expenseAccountId = $supplier->account_id;
                }
            } elseif ($program->executing_company_id) {
                $company = HajjUmraExecutingCompany::find($program->executing_company_id);
                if ($company) {
                    // BRIEF 5 — REGRESSION #1 FIX: mirror the customer-AR fix
                    //   above. Use the treasury currency (not the booking's
                    //   free-form label) so the per-currency AP account is
                    //   in the same currency as the booking's expense source.
                    //   The free-form `currency` label is preserved on the
                    //   booking row but does NOT dictate the AP currency.
                    $apAccount = $this->ensureExecutingCompanyAccount($company, $bookingLedgerCurrency);
                    $expenseAccountId = $apAccount->id;
                }
            }

            // ─────────────────────────────────────────────────────────────────
            // FIX (GAP #HJ-6, fixed 2026-07-16):
            //   If the expense falls back to the cashbox (no supplier AND no
            //   executing company), the cashbox must have enough balance to
            //   cover the purchase cost. Without this guard, the system could
            //   silently book expenses it cannot pay, leading to negative
            //   balances and broken reconciliation downstream.
            // ─────────────────────────────────────────────────────────────────
            if ($expenseAccountId === $accountId) {
                $cashbox = Account::findOrFail($accountId);
                if ((float) $cashbox->balance < (float) $totalPurchase) {
                    throw new \RuntimeException(
                        'رصيد الخزينة غير كافٍ لتغطية تكلفة الحجز: '
                        .'الرصيد الحالي ' . number_format((float) $cashbox->balance, 2)
                        .' والمطلوب ' . number_format((float) $totalPurchase, 2)
                        .' (' . $cashbox->name . '). '
                        .'يُرجى اختيار مورّد أو شركة منفذة لتحمّل تكلفة الحجز، '
                        .'أو إيداع رصيد كافٍ في الخزينة.'
                    );
                }
            }

// ─────────────────────────────────────────────────────────────────
            // BRIEF 6 / TASK A — SUPPLIER AP GHOST DEBT FIX (2026-08-21):
            //   Pre-Brief-4: a booking with a cross-currency supplier (e.g. USD
            //     supplier + EGP booking) silently used `?? 1.0` FX fallback in
            //     recordJournalTransfer, so the supplier AP got debited 42000 EGP
            //     (= 42000 USD via the silent 1:1 rate) while the clearing got
            //     credited 42000 EGP. Two currencies in one nominal entry — wrong.
            //   Brief 4 fix: added an expense-source fallback that DIVERTED the
            //     expense to the EGP treasury (skipping the supplier entirely).
            //     This was the SAFER choice under the Safe FX Rule but it left
            //     the supplier AP UNTOUCHED — `test_delete_zero_ghost_supplier_debt`
            //     expects the supplier AP to be debited and then returned to
            //     baseline after delete. With Brief 4's fallback, the supplier
            //     AP was a no-op → 0.0 was not < 0.0 → test failed.
            //   Brief 6 fix (TASK A): KEEP the supplier/company as the expense
            //     source. When the supplier/company currency differs from the
            //     booking's clearing currency, use CurrencyService::convert() to
            //     compute the EXPLICIT source-currency amount (no silent 1.0) and
            //     pass it to recordExpense along with `converted_amount` so the
            //     clearing is credited in the booking currency. This satisfies:
            //       - Safe FX Rule (explicit DB rate, no silent fallback)
            //       - TASK A test (supplier AP is debited)
            //       - Reconciliation (delete reversal returns supplier AP to 0)
            //
            //   IMPORTANT: the FX conversion is ONLY applied when the expense
            //     source is a SUPPLIER/EXECUTING-COMPANY account, i.e. NOT the
            //     user-selected treasury. When `expenseAccountId === accountId`
            //     (treasury-as-source — e.g. no supplier set, or supplier in
            //     same currency as treasury), the booking amount IS the treasury
            //     amount and no FX is required. This preserves the
            //     `test_booking_create_with_unknown_currency_is_accepted`
            //     contract: free-form currency (e.g. 'XXX') with an EGP
            //     treasury must succeed without requiring an FX rate for XXX.
            // ─────────────────────────────────────────────────────────────────
            $expenseAmount = $totalPurchase; // default = booking currency
            $expenseConvertedAmount = null;
            $expenseExchangeRate = null;
            $expenseFromAccount = Account::find($expenseAccountId);
            // BRIEF 6 / TASK A — REVISED (2026-08-21):
            //   FX trigger compares the expense-source currency against the
            //   EFFECTIVE settlement currency (`$bookingLedgerCurrency`),
            //   NOT the booking's free-form `currency` label.
            //
            //   Pre-revision: compared against `$booking->currency` directly.
            //   That caused an unintended cross-currency path for ANY
            //   booking whose free-form label (e.g. 'XXX') differed from the
            //   underlying EGP clearing — even when the supplier/company AP
            //   account was ALSO denominated in EGP (no actual FX needed).
            //   The Program model auto-creates a HajjUmraExecutingCompany when
            //   the program has `executing_company` text set — so most test
            //   programs end up with an EGP AP account but the code path
            //   still saw a "different account" and tried to call
            //   CurrencyService::convert() for an unsupported currency pair.
            //
            //   Post-revision: only trigger FX when the AP/supplier account
            //   currency DIFFERS from the EFFECTIVE clearing currency used
            //   by the booking (line 190-199). EGP→EGP (free-form label
            //   notwithstanding) takes the same-currency path; cross-currency
            //   (USD supplier + EGP clearing) still takes the explicit-FX
            //   path as originally intended by TASK A.
            $debugCondition = ($expenseFromAccount
                && $expenseAccountId !== (int) $accountId
                && strtoupper((string) $expenseFromAccount->currency) !== strtoupper((string) ($bookingLedgerCurrency ?? 'EGP')));
            if ($debugCondition) {
                // Cross-currency supplier/company. Compute explicit FX.
                $converted = app(\App\Services\Finance\CurrencyService::class)->convert(
                    $totalPurchase,
                    strtoupper((string) ($bookingLedgerCurrency ?? 'EGP')),
                    strtoupper((string) $expenseFromAccount->currency)
                );
                $expenseAmount = (float) $converted['to_amount'];        // source (supplier) currency
                $expenseConvertedAmount = (float) $totalPurchase;        // destination (clearing) = booking amount in settlement currency
                $expenseExchangeRate = (float) $converted['rate'];       // explicit DB rate
            }

            $expense = $this->transactions->recordExpense([
                'amount' => $expenseAmount,
                'converted_amount' => $expenseConvertedAmount,
                'exchange_rate' => $expenseExchangeRate,
                'from_account_id' => $expenseAccountId,
                'currency' => $booking->currency ?? 'EGP',
                'module' => TransactionModule::HajjUmra->value,
                'related_type' => HajjUmraBooking::class,
                'related_id' => $booking->id,
                'notes' => "تكلفة برنامج {$program->program_name} - {$customer->full_name}",
                'created_by' => $createdBy,
            ]);

            $income = $this->transactions->recordIncome([
                'amount' => $totalSelling,
                'to_account_id' => $customerAccount->id,
                'currency' => $booking->currency ?? 'EGP',
                'module' => TransactionModule::HajjUmra->value,
                'related_type' => HajjUmraBooking::class,
                'related_id' => $booking->id,
                'notes' => "بيع برنامج {$program->program_name} - {$customer->full_name}",
                'created_by' => $createdBy,
            ]);

            $booking->update([
                'expense_transaction_id' => $expense->id,
                'income_transaction_id' => $income->id,
            ]);

            // تسجيل دفعة أولية إن وُجدت
            if (! empty($data['initial_payment']) && (float) ($data['initial_payment']['amount'] ?? 0) > 0) {
                $this->addPayment($booking, $data['initial_payment']);
            }

            Log::info('HajjUmra booking created', [
                'booking_id' => $booking->id,
                'customer_id' => $customer->id,
                'program_id' => $program->id,
                'purchase' => $totalPurchase,
                'selling' => $totalSelling,
                'profit' => $profit,
            ]);

            return $this->find($booking->id);
        });
    }

    protected function repostExpenseTransaction(HajjUmraBooking $booking, Transaction $transaction, float $newAmount): Transaction
    {
        $expenseAccountId = (int) $booking->account_id;
        if ($booking->supplier_id) {
            $supplier = UmrahSupplier::find($booking->supplier_id);
            if ($supplier?->account_id) {
                $expenseAccountId = (int) $supplier->account_id;
            }
        } else {
            $program = Program::find($booking->program_id);
            if ($program && $program->executing_company_id) {
                $company = HajjUmraExecutingCompany::find($program->executing_company_id);
                if ($company) {
                    if (! $company->account_id) {
                        $account = Account::create([
                            'name' => 'حساب الشركة المنفذة للحج/العمرة: '.($company->name ?: 'غير مسمى'),
                            'type' => AccountType::Supplier->value,
                            'currency' => 'EGP',
                            'balance' => 0.00,
                            'is_active' => true,
                            'owner_type' => Account::OWNER_TYPE_OWNER,
                            'module_type' => 'hajj_umra',
                            'notes' => 'حساب شركة منفذة تلقائي مضاف من النظام.',
                            'created_by' => $booking->created_by ?? 1,
                        ]);
                        $company->account_id = $account->id;
                        $company->save();
                    }
                    $expenseAccountId = (int) $company->account_id;
                }
            }
        }

        $oldAmount = (float) $transaction->amount;
        $accountChanged = ($expenseAccountId !== (int) $transaction->from_account_id);
        if ($oldAmount === $newAmount && ! $accountChanged) {
            return $transaction;
        }

        // ✦ Phase 2026-07-11 FIX: previously this called
        //   `voidTransactionJournal($transaction); $transaction->delete();`
        //   which destroyed the original `transactions` row AND its
        //   `account_entries` — violating the project rule "the original
        //   transaction + entries are never deleted or modified".
        //   Now we use `reverseTransaction()` which ADDS inverse
        //   account_entries on the SAME transaction_id and updates the
        //   transaction notes prefix (`عكس: `). The original row stays.
        $this->transactions->reverseTransaction($transaction);

        return $this->transactions->recordExpense([
            'amount' => $newAmount,
            'from_account_id' => $expenseAccountId,
            'module' => TransactionModule::HajjUmra->value,
            'related_type' => HajjUmraBooking::class,
            'related_id' => $booking->id,
            'notes' => $transaction->notes,
            'created_by' => $transaction->created_by ?? 1,
        ]);
    }

    protected function repostIncomeTransaction(HajjUmraBooking $booking, Transaction $transaction, float $newAmount): Transaction
    {
        $oldAmount = (float) $transaction->amount;
        if ($oldAmount === $newAmount) {
            return $transaction;
        }

        $customerAccount = $this->ensureCustomerAccount($booking->customer_id, (string) ($booking->currency ?? 'EGP'));

        // ✦ Phase 2026-07-11 FIX: same reverseTransaction-based pattern as
        //   repostExpenseTransaction — do NOT destroy the original
        //   transaction row. See the inline note there for rationale.
        $this->transactions->reverseTransaction($transaction);

        return $this->transactions->recordIncome([
            'amount' => $newAmount,
            'to_account_id' => $customerAccount->id,
            'currency' => (string) ($booking->currency ?? 'EGP'),
            'module' => TransactionModule::HajjUmra->value,
            'related_type' => HajjUmraBooking::class,
            'related_id' => $booking->id,
            'notes' => $transaction->notes,
            'created_by' => $transaction->created_by,
        ]);
    }

    public function update(HajjUmraBooking $booking, array $data): HajjUmraBooking
    {
        // ─────────────────────────────────────────────────────────────────
        // BRIEF 5 — REGRESSION #4 FIX (2026-08-21):
        //   RESTORED the Phase 10.5 / INCIDENT-2026-08-17 no-edit guard.
        //
        //   Tourism contract: Hajj/Umra booking editing is DISABLED. The
        //   PUT/PATCH routes return HTTP 405 (Phase 12.5). The service
        //   layer must ALSO throw unconditionally so any direct caller
        //   (Tinker, jobs, future routes) cannot bypass the contract.
        //
        //   Pre-fix (Brief 4): removed this throw, leaving only Cancelled /
        //   Refunded / trashed guards — which let non-cancelled bookings be
        //   edited, breaking the Phase 10.5 contract.
        //
        //   Post-fix: throws \LogicException unconditionally, matching HEAD.
        //   ─ Code below is intentionally unreachable (reference only). ─
        // ─────────────────────────────────────────────────────────────────
        throw new \LogicException(
            'HajjUmraBookingService::update is disabled by Tourism no-edit contract (2026-08-17). '
            .'Cancellation is the supported correction path.'
        );

        // ── Unreachable code retained as reference for future maintainers ──
        return DB::transaction(function () use ($booking, $data) {
            // ─────────────────────────────────────────────────────────────
            // LOCK-DOWN (Phase 4.6, 2026-08-14): financial price columns
            // are FROZEN at booking creation. Once saved, a Hajj/Umrah
            // booking's selling/purchase/companion/accommodation prices
            // cannot be modified through ANY caller — API, Tinker, jobs.
            //
            // Why:
            //   - The duplicate-income guard in TransactionService is
            //     correctly strict; with prices locked, the booking never
            //     needs `repostIncomeTransaction()` again.
            //   - The MySQL `transactions_income_unique_key` index would
            //     otherwise reject a repost on production (generated
            //     column → NULL only when `type != 'income'`, ignores
            //     notes). Locking the input side avoids the DB-level
            //     collision entirely.
            //   - Reversal+repost would double-count reversed income rows
            //     in revenue reports unless every consumer also filters
            //     on `notes NOT LIKE 'عكس:%'`. The locked-input approach
            //     sidesteps that audit-trail contamination by preventing
            //     the reversal from ever happening on a HajjUmra booking.
            //
            // Defense-in-depth: UpdateHajjUmraBookingRequest strips these
            // fields at the HTTP boundary (clean 422). This guard catches
            // every other caller with the same Arabic business error.
            // ─────────────────────────────────────────────────────────────
            $arFieldNames = [
                'selling_price'             => 'سعر البيع',
                'purchase_price'            => 'سعر الشراء',
                'companion_selling_price'   => 'سعر بيع المرافق',
                'companion_purchase_price'  => 'سعر شراء المرافق',
                'accommodation_extra_charge' => 'رسوم الإقامة الإضافية',
            ];
            $presentLocked = array_intersect_key(
                $data,
                array_flip(array_keys($arFieldNames))
            );
            // array_intersect_key() matches by key AND keeps the values —
            // but a caller could pass null/empty as "I'm not really
            // trying to change it" (e.g. a UI that re-emits the full
            // row). Only block when the value is meaningfully non-empty.
            $presentLocked = array_filter($presentLocked, function ($v) {
                return $v !== null && $v !== '';
            });
            if (! empty($presentLocked)) {
                $first = array_key_first($presentLocked);
                $arabicName = $arFieldNames[$first];
                $allList = implode('، ', $arFieldNames);
                throw new \RuntimeException(
                    "لا يمكن تعديل {$arabicName} بعد إنشاء الحجز. "
                    ."الحقول المالية مُقفلة بعد الإنشاء: {$allList}. "
                    ."لتصحيح سعر، ألغِ الحجز (cancel) وأنشئ حجزاً جديداً."
                );
            }

            $fields = collect($data)->only([
                'companion_customer_id',
                'supplier_id',
                'status',
                'agent_name',
                'notes',
                'employee_id',
                'per_person',
                'accommodation_choice',
            ])->all();

            $purchase = (float) (array_key_exists('purchase_price', $data) ? $data['purchase_price'] : $booking->purchase_price);
            $companionPurchase = (float) (array_key_exists('companion_purchase_price', $data) ? $data['companion_purchase_price'] : $booking->companion_purchase_price);
            $selling = (float) (array_key_exists('selling_price', $data) ? $data['selling_price'] : $booking->selling_price);
            $companionSelling = (float) (array_key_exists('companion_selling_price', $data) ? $data['companion_selling_price'] : $booking->companion_selling_price);
            $accommodationExtra = (float) (array_key_exists('accommodation_extra_charge', $data) ? $data['accommodation_extra_charge'] : $booking->accommodation_extra_charge);

            $totalPurchase = $purchase + $companionPurchase;
            $totalSelling = $selling + $companionSelling + $accommodationExtra;
            $profit = round($totalSelling - $totalPurchase, 2);

            $fields['purchase_price'] = $purchase;
            $fields['companion_purchase_price'] = $companionPurchase;
            $fields['selling_price'] = $selling;
            $fields['companion_selling_price'] = $companionSelling;
            $fields['accommodation_extra_charge'] = $accommodationExtra;
            $fields['profit'] = $profit;

            // Wrapped in HajjUmraBooking::runProfitMutation() so the ModelProfitMutationGuard
            // lets the canonical `profit` write through.
            HajjUmraBooking::runProfitMutation(function () use ($booking, $fields) {
                $booking->update($fields);
            });

            // Update passengers if provided
            if (array_key_exists('passengers', $data) && is_array($data['passengers'])) {
                $booking->passengers()->delete();
                foreach ($data['passengers'] as $p) {
                    $booking->passengers()->create([
                        'category' => $p['category'],
                        'count' => (int) $p['count'],
                        'unit_price' => (float) $p['unit_price'],
                        'subtotal' => (float) $p['subtotal'],
                    ]);
                }
            }

            // Sync accounting amounts via void + repost (LedgerBalanceMutationGuard-safe)
            $booking->load(['expenseTransaction', 'incomeTransaction']);
            if ($booking->expenseTransaction) {
                $expense = $this->repostExpenseTransaction($booking, $booking->expenseTransaction, $totalPurchase);
                if ($expense->id !== $booking->expense_transaction_id) {
                    $booking->update(['expense_transaction_id' => $expense->id]);
                }
            }
            if ($booking->incomeTransaction) {
                $income = $this->repostIncomeTransaction($booking, $booking->incomeTransaction, $totalSelling);
                if ($income->id !== $booking->income_transaction_id) {
                    $booking->update(['income_transaction_id' => $income->id]);
                }
            }

            return $this->find($booking->id);
        });
    }

    public function cancel(HajjUmraBooking $booking, ?string $reason = null): HajjUmraBooking
    {
        return DB::transaction(function () use ($booking, $reason) {
            $status = $booking->status instanceof \BackedEnum ? $booking->status->value : (string) $booking->status;
            if ($status === HajjUmraStatus::Cancelled->value) {
                throw new \RuntimeException('الحجز ملغى مسبقاً.');
            }

            // ─────────────────────────────────────────────────────────────────
            // BRIEF 6 / TASK B — CANCEL-AFTER-REFUND GUARD (2026-08-21):
            //   A booking that has already been FULLY REFUNDED has had all
            //   its financial mutations already reversed by the refund flow.
            //   Allowing a subsequent cancel would attempt to reverse
            //   already-reversed transactions a second time, producing
            //   phantom credits and corrupted ledger entries.
            //
            //   Pre-fix: cancel() only checked `status === Cancelled`. A
            //   refund-then-cancel sequence succeeded (200 OK) — the test
            //   `cancel_after_refund_rejected` expected 422.
            //
            //   Post-fix: mirror the guard in `addPayment()` (line 693+) and
            //   `refund()` (HajjUmraRefundService.php:92) — reject cancel
            //   after refund with a clear Arabic error. No ledger mutation.
            // ─────────────────────────────────────────────────────────────────
            if ($status === HajjUmraStatus::Refunded->value) {
                throw new \RuntimeException(
                    'لا يمكن إلغاء حجز تم استرداده بالكامل (status=refunded). '
                    .'تم عكس القيود المحاسبية عند الاسترداد.'
                );
            }

            $booking->load(['payments.transaction', 'expenseTransaction', 'incomeTransaction']);

            // ✦ Phase 2026-07-11 FIX (Q1+Q2): was:
            //   foreach ($booking->payments as $payment) {
            //       if ($payment->transaction) {
            //           $this->transactions->voidTransactionJournal($payment->transaction);
            //           $payment->transaction->delete();   // ← destructive
            //       }
            //   }
            //   …same pattern for income + expense transactions
            //
            //   This destroyed the original `transactions` rows and their
            //   `account_entries`, violating the project-wide rule that
            //   "the original transaction + entries are never deleted or
            //   modified — reversals are always ADDITIVE".
            //
            //   The replacement uses `TransactionService::reverseTransaction()`,
            //   which adds inverse `account_entries` rows on the SAME
            //   `transaction_id` and updates the transaction notes with a
            //   `عكس: ` prefix. The original rows are preserved.
            //
            //   The booking row stays visible (status=Cancelled) per Q3 —
            //   no soft-delete here. For admin-driven soft-delete with full
            //   reversal, see `deleteBookingWithReversal()` below.
            foreach ($booking->payments as $payment) {
                if ($payment->transaction) {
                    $this->transactions->reverseTransaction($payment->transaction);
                }
            }

            if ($booking->incomeTransaction) {
                $this->transactions->reverseTransaction($booking->incomeTransaction);
            }

            if ($booking->expenseTransaction) {
                $this->transactions->reverseTransaction($booking->expenseTransaction);
            }

            $note = trim((string) $booking->notes);
            if ($reason) {
                $note = ($note === '' ? '' : $note."\n").'سبب الإلغاء: '.$reason;
            }

            $booking->update([
                'status' => HajjUmraStatus::Cancelled->value,
                'notes' => $note,
                // Keep the *_transaction_id pointers — `reverseTransaction()` ADDS
                // entries on the same transaction_id; the FK references stay valid.
                // Previously these were nulled here, which left dangling
                // references after the old destructive delete.
            ]);

            Log::info('HajjUmra booking cancelled (additive reversal applied)', [
                'booking_id' => $booking->id,
                'reason' => $reason,
                'payments_reversed' => $booking->payments->filter(fn ($p) => $p->transaction)->count(),
                'income_reversed' => (bool) $booking->incomeTransaction,
                'expense_reversed' => (bool) $booking->expenseTransaction,
            ]);

            return $this->find($booking->id);
        });
    }

    /**
     * Administrative soft-delete with full financial reversal.
     *
     * Mirrors the canonical `FlightBookingService::deleteBookingWithReversal()`
     * pattern (same name, same shape, same invariants) for the HajjUmra
     * module. Use this when an admin needs to fully remove a booking from
     * active lists while:
     *   ① posting additive `account_entries` reversals (never destroying
     *      the original `transactions` / `account_entries` rows), AND
     *   ② soft-deleting the booking row (hiding it from views/reports).
     *
     * For customer-initiated cancellation that should keep the booking row
     * visible (status=Cancelled), use `cancel($booking, $reason)` instead.
     *
     * Idempotency: throws RuntimeException if the booking is already
     * soft-deleted, matching the Flight pattern.
     *
     * @throws \RuntimeException on duplicates or when the guard is misconfigured
     */
    public function deleteBookingWithReversal(int $bookingId, ?User $actor = null): bool
    {
        // BRIEF 5 — REGRESSION #2 FIX (2026-08-21):
        //   Restored the canonical HEAD signature `(?User $actor = null)`.
        //   Pre-fix (Brief 4) silently broke this for direct service callers
        //   (tests, Tinker, jobs) that pass a User object — got TypeError.
        //
        //   Post-fix:
        //     - $actor may be User|null
        //     - $userIdEffective is derived internally (preserves the audit
        //       log + reversal context)
        //     - Falls back to Auth::id() when $actor is null (Tinker/jobs)
        //     - HajjUmraController still works — it now passes the User.
        $userIdEffective = $actor?->id ?? (int) (Auth::id() ?: 1);

        // Wrap in the canonical deletion gate so the model's `deleting` event
        // allows the soft-delete. Same depth-counter shape as
        // LedgerBalanceMutationGuard; per-model isolation comes free from
        // ModelDeletionGuard trait's per-class statics (FlightBooking's gate
        // cannot open HajjUmraBooking's gate and vice versa).
        return HajjUmraBooking::run(function () use ($bookingId, $userIdEffective) {
            return DB::transaction(function () use ($bookingId, $userIdEffective) {
                // 1) Lock + reload with relations.
                //    withTrashed() so an already-soft-deleted booking can be
                //    located — we want a clean idempotency error, not "No query results".
                $booking = HajjUmraBooking::query()
                    ->withTrashed()
                    ->with(['payments.transaction', 'expenseTransaction', 'incomeTransaction'])
                    ->lockForUpdate()
                    ->findOrFail($bookingId);

                // 2) Idempotency guard — second call returns a clean Arabic error.
                if ($booking->trashed()) {
                    throw new \RuntimeException(
                        'هذا الحجز محذوف بالفعل (soft delete) — لا يمكن عكسه مرة ثانية.'
                    );
                }

                Log::info('HajjUmraBookingService::deleteBookingWithReversal — starting', [
                    'booking_id' => $booking->id,
                    'status' => $booking->status?->value ?? (string) $booking->status,
                    'payments_count' => $booking->payments->count(),
                    'has_income' => (bool) $booking->incomeTransaction,
                    'has_expense' => (bool) $booking->expenseTransaction,
                    'user_id' => $userIdEffective,
                ]);

                // 3) Reverse each payment transaction (additive — never destructive).
                foreach ($booking->payments as $payment) {
                    if ($payment->transaction) {
                        $this->transactions->reverseTransaction($payment->transaction);
                    }
                }

                // 4) Reverse the booking's income + expense transactions (additive).
                if ($booking->incomeTransaction) {
                    $this->transactions->reverseTransaction($booking->incomeTransaction);
                }
                if ($booking->expenseTransaction) {
                    $this->transactions->reverseTransaction($booking->expenseTransaction);
                }

                // 5) Soft-delete the payments (uses new SoftDeletes trait).
                //    The transactions themselves stay — only `account_entries`
                //    inverses were added by reverseTransaction().
                $booking->payments()->delete();

                // 6) Soft-delete the booking row itself. Allowed because we are
                //    inside HajjUmraBooking::run(...) which flipped the model's
                //    deletion gate open for the canonical reversal flow.
                $booking->delete();

                Log::info('HajjUmraBookingService::deleteBookingWithReversal — complete', [
                    'booking_id' => $booking->id,
                    'user_id' => $userIdEffective,
                ]);

                return true;
            });
        });
    }

    public function addPayment(HajjUmraBooking $booking, array $data): HajjUmraPayment
    {
        // ─────────────────────────────────────────────────────────────────
        // FIX (GAP #HJ-4 + #HJ-5, fixed 2026-07-16):
        //   The system used to accept payments on cancelled OR soft-deleted
        //   bookings, which would corrupt the ledger and let admins add
        //   money to closed bookings. We now guard at the service level —
        //   this is the canonical entry point for all payment additions
        //   (Tinker, API, Filament form, direct call).
        // ─────────────────────────────────────────────────────────────────
        $status = $booking->status instanceof \BackedEnum ? $booking->status->value : (string) $booking->status;
        if ($status === \App\Enums\HajjUmraStatus::Cancelled->value) {
            throw new \RuntimeException(
                'لا يمكن إضافة دفعة على حجز مُلغى (status=cancelled). '
                .'يجب استخدام HajjUmraRefundService::refund() لاسترداد المبالغ.'
            );
        }
        if ($status === \App\Enums\HajjUmraStatus::Refunded->value) {
            throw new \RuntimeException(
                'لا يمكن إضافة دفعة على حجز تم استرداده بالكامل (status=refunded).'
            );
        }
        if ($booking->trashed()) {
            throw new \RuntimeException(
                'لا يمكن إضافة دفعة على حجز محذوف (soft-deleted). '
                .'يجب استخدام deleteBookingWithReversal() للعكس الإداري.'
            );
        }

        // ─────────────────────────────────────────────────────────────────
        // PRE-PHASE-B IDEMPOTENCY FIX (2026-08-15):
        //   Replay protection for the payment endpoint.
        //
        //   Identity:  (hajj_umra_booking_id, idempotency_key)
        //   Stored on: hajj_umra_payments.idempotency_key  (nullable, 100 chars)
        //   Enforced:  UNIQUE index hup_idem_uniq  (MySQL allows multiple NULLs
        //              so legacy callers that don't supply a key are unaffected).
        //
        //   Layered protection:
        //     1. Pre-check (this method, inside the lock): SELECT existing
        //        payment with same (booking_id, idempotency_key). If found
        //        and not soft-deleted → return it (idempotent return).
        //     2. DB-level UNIQUE constraint (the migration) — backstop in
        //        case two callers bypass the lock. The INSERT will fail
        //        with MySQL error 1062 / SQLSTATE 23000, which we catch
        //        and convert to an idempotent return.
        //     3. `lockForUpdate()` on the booking row serializes concurrent
        //        calls on the same booking. The lock is held for the
        //        duration of the transaction (released on commit/rollback).
        //
        //   Backward compat:
        //     - When `idempotency_key` is null/empty, no protection is
        //       applied and the call may still be replayed. Legacy
        //       callers keep their existing behavior.
        //     - When supplied, replays return the original payment
        //       (200 OK with the original row) — no second financial
        //       mutation, no extra AccountEntry rows, no extra Transaction.
        // ─────────────────────────────────────────────────────────────────
        $idempotencyKey = isset($data['idempotency_key']) && $data['idempotency_key'] !== ''
            ? (string) $data['idempotency_key']
            : null;

        try {
            return DB::transaction(function () use ($booking, $data, $idempotencyKey) {
                // Serialize concurrent calls on the same booking.
                $locked = HajjUmraBooking::query()->lockForUpdate()->find($booking->id);
                if (! $locked) {
                    throw new \RuntimeException("Booking {$booking->id} not found.");
                }

                // Layer 1 — pre-check: if a payment already exists for this
                // (booking, idempotency_key), return it instead of creating
                // a duplicate. We also honor soft-deletes: a soft-deleted
                // payment with the same key is treated as "deleted" and a
                // new payment may be inserted (the unique index will be
                // violated only by ACTIVE rows, so the DB would block a
                // second ACTIVE one with the same key — but soft-deleted
                // rows coexist).
                if ($idempotencyKey !== null) {
                    $existing = HajjUmraPayment::query()
                        ->where('hajj_umra_booking_id', $locked->id)
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();
                    if ($existing) {
                        // Tag the returned model with a transient flag so
                        // the HTTP layer can surface 200 OK + replay marker
                        // instead of 201 Created.
                        $existing->idempotent_replay = true;
                        return $existing;
                    }
                }

                $amount = (float) $data['amount'];
                $accountId = (int) ($data['account_id'] ?? $booking->account_id);
                $createdBy = Auth::id() ?? ($data['created_by'] ?? null);

                $customerAccount = $this->ensureCustomerAccount($booking->customer_id, (string) ($booking->currency ?? 'EGP'));

                // FIX (latent-bug-after-FC-AUDIT-20260814): a payment on an existing
                // booking is a TRANSFER (customer AR → treasury), NOT a new Income.
                // The booking's sale Income was already recorded at create(); a
                // payment represents cash movement against existing debt. Using
                // recordIncome() here would, after the FC-AUDIT D1 fix, set
                // type=Income and trigger the duplicate-income guard at
                // TransactionService::recordJournalTransfer (lines 612–625) on
                // the second payment. We now use recordJournalTransfer() with
                // explicit type=Transfer, which (a) matches the pre-FC-AUDIT
                // behaviour exactly (silent default) and (b) is the semantically
                // correct category for cash collection against a known sale.
                $income = $this->transactions->recordJournalTransfer([
                    'amount' => $amount,
                    'from_account_id' => $customerAccount->id,
                    'to_account_id' => $accountId,
                    'module' => TransactionModule::HajjUmra->value,
                    'type' => \App\Enums\TransactionType::Transfer->value,
                    'related_type' => HajjUmraBooking::class,
                    'related_id' => $booking->id,
                    'notes' => "دفعة على حجز #{$booking->id}",
                    'created_by' => $createdBy,
                ]);

                try {
                    return $booking->payments()->create([
                        'payment_method' => $data['payment_method'] ?? 'cash',
                        'amount' => $amount,
                        'currency' => $data['currency'] ?? $booking->currency ?? 'EGP',
                        'treasury_account' => $data['treasury_account'] ?? 'office_drawer',
                        'account_id' => $accountId,
                        'transaction_id' => $income->id,
                        'transaction_reference' => $data['reference'] ?? $data['transaction_reference'] ?? null,
                        'idempotency_key' => $idempotencyKey,
                        'payment_date' => $data['payment_date'] ?? now(),
                        'paid_by' => $data['paid_by'] ?? $booking->customer?->full_name ?? '',
                        'created_by' => $createdBy,
                    ]);
                } catch (\Illuminate\Database\QueryException $qe) {
                    // Layer 2 — defense in depth. The pre-check above plus
                    // the `lockForUpdate` should make this catch unreachable
                    // in normal operation, but if two transactions somehow
                    // race past the pre-check (e.g. on a connection pool
                    // without row locks), the UNIQUE index is the last line.
                    //
                    // MySQL: SQLSTATE 23000, error code 1062 ("Duplicate entry").
                    // SQLite: SQLSTATE 23000 with a similar message.
                    if ($this->isDuplicateKeyError($qe) && $idempotencyKey !== null) {
                        // Re-query: the row that another tx created must now
                        // be visible. Return it as the idempotent result.
                        $existing = HajjUmraPayment::query()
                            ->where('hajj_umra_booking_id', $locked->id)
                            ->where('idempotency_key', $idempotencyKey)
                            ->first();
                        if ($existing) {
                            $existing->idempotent_replay = true;
                            return $existing;
                        }
                    }
                    throw $qe;
                }
            });
        } catch (\Illuminate\Database\QueryException $qe) {
            // Outer catch: if the inner transaction's DB::transaction
            // re-raised a duplicate-key error from `recordJournalTransfer`
            // or the create() call after we've already inserted the
            // payment row, we surface a clean idempotent return when the
            // (booking, key) is still resolvable.
            if ($this->isDuplicateKeyError($qe) && $idempotencyKey !== null) {
                $existing = HajjUmraPayment::query()
                    ->where('hajj_umra_booking_id', $booking->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) {
                    $existing->idempotent_replay = true;
                    return $existing;
                }
            }
            throw $qe;
        }
    }

    /**
     * Identify a "duplicate entry on unique index" QueryException across
     * MySQL and SQLite. SQLSTATE 23000 is the standard; MySQL error code
     * 1062 is the canonical "Duplicate entry" code.
     */
    private function isDuplicateKeyError(\Illuminate\Database\QueryException $qe): bool
    {
        $sqlState = (string) ($qe->errorInfo[0] ?? '');
        if ($sqlState === '23000') {
            return true;
        }
        $code = (int) ($qe->errorInfo[1] ?? 0);
        return $code === 1062;
    }

    protected function resolveCustomer(?array $data, ?int $existingId): Customer
    {
        if ($existingId) {
            return Customer::findOrFail($existingId);
        }

        if (! $data || empty($data['phone'])) {
            throw new \InvalidArgumentException('بيانات العميل (الاسم والهاتف) مطلوبة.');
        }

        return Customer::updateOrCreate(
            ['phone' => $data['phone']],
            collect($data)->only([
                'full_name', 'national_id', 'travel_country', 'passport_number', 'passport_expiry',
                'date_of_birth', 'city', 'affiliation', 'notes',
            ])->all()
        );
    }

    protected function ensureCustomerAccount(int $customerId, ?string $currency = null): Account
    {
        $customer = Customer::findOrFail($customerId);
        $currency = $currency ? strtoupper($currency) : 'EGP';

        if ($customer->account_id) {
            $primary = Account::find($customer->account_id);
            if ($primary
                && strtoupper((string) $primary->currency) === $currency
            ) {
                // Primary account matches the requested currency — use it.
                // Phase 1.Bend3 fix: CustomerLedgerObserver creates a generic
                // 'office'-tagged account the moment a Customer row is
                // inserted. When that customer is later used in a HajjUmra
                // booking flow we re-tag the account to 'hajj_umra' so it
                // surfaces in the strict module_type='hajj_umra' queries
                // (TreasuryService line 521). Wrapped in
                // LedgerBalanceMutationGuard because touching `balance`
                // — even to confirm 0.00 — would otherwise trip the
                // Account::updating boot guard.
                if ($primary->module_type !== 'hajj_umra') {
                    LedgerBalanceMutationGuard::run(function () use ($primary) {
                        $primary->module_type = 'hajj_umra';
                        $primary->save();
                    });
                }

                return $primary;
            }
        }

        // FX SAFETY (2026-08-21): resolve or auto-create a per-currency
        // customer account. Pre-fix: ensureCustomerAccount() created the
        // account hard-coded as EGP and the silent `?? 1.0` fallback in
        // recordJournalTransfer masked the cross-currency mismatch
        // downstream — producing nominally-balanced but semantically-wrong
        // ledger entries. Post-fix: every customer account used in a HajjUmra
        // journal entry MUST match the booking currency.

        $existing = Account::query()
            ->where('module_type', 'hajj_umra')
            ->where('owner_type', Account::OWNER_TYPE_OWNER)
            ->where('type', AccountType::Customer->value)
            ->where('currency', $currency)
            ->where('notes', 'حساب تلقائي للعميل #'.$customer->id)
            ->first();

        if ($existing) {
            if ((int) $customer->account_id !== (int) $existing->id) {
                $customer->update(['account_id' => $existing->id]);
            }

            return $existing;
        }

        $account = LedgerBalanceMutationGuard::run(fn () => DB::transaction(function () use ($customer, $currency) {
            $account = Account::create([
                'name' => 'حساب العميل: '.$customer->full_name.' ('.$currency.')',
                'type' => AccountType::Customer,
                'balance' => 0,
                'currency' => $currency,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OWNER,
                'module_type' => 'hajj_umra',
                'is_module_vault' => false,
                'notes' => 'حساب تلقائي للعميل #'.$customer->id,
                'created_by' => Auth::id() ?? 1,
            ]);

            $customer->update(['account_id' => $account->id]);

            Log::info('Customer ledger account created automatically', [
                'customer_id' => $customer->id,
                'account_id' => $account->id,
                'currency' => $currency,
            ]);

            return $account;
        }));

        return $account;
    }

    /**
     * FX SAFETY (2026-08-21): resolve (or auto-create) the AP account for a
     * HajjUmra executing company in a specific currency.
     */
    protected function ensureExecutingCompanyAccount(HajjUmraExecutingCompany $company, string $currency): Account
    {
        $currency = strtoupper($currency);

        if ($company->account_id) {
            $primary = Account::find($company->account_id);
            if ($primary && strtoupper((string) $primary->currency) === $currency) {
                return $primary;
            }
        }

        $existing = Account::query()
            ->where('module_type', 'hajj_umra')
            ->where('owner_type', Account::OWNER_TYPE_OWNER)
            ->where('type', AccountType::Supplier->value)
            ->where('currency', $currency)
            ->where('notes', 'حساب شركة منفذة تلقائي مضاف من النظام. company_id='.$company->id)
            ->first();

        if ($existing) {
            if ((int) $company->account_id !== (int) $existing->id) {
                $company->update(['account_id' => $existing->id]);
            }

            return $existing;
        }

        $account = LedgerBalanceMutationGuard::run(fn () => DB::transaction(function () use ($company, $currency) {
            $account = Account::create([
                'name' => 'حساب الشركة المنفذة للحج/العمرة: '.($company->name ?: 'غير مسمى').' ('.$currency.')',
                'type' => AccountType::Supplier,
                'balance' => 0.00,
                'currency' => $currency,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OWNER,
                'module_type' => 'hajj_umra',
                'notes' => 'حساب شركة منفذة تلقائي مضاف من النظام. company_id='.$company->id,
                'created_by' => Auth::id() ?? 1,
            ]);

            Log::info('Executing company AP account created automatically', [
                'company_id' => $company->id,
                'account_id' => $account->id,
                'currency' => $currency,
            ]);

            return $account;
        }));

        $company->update(['account_id' => $account->id]);

        return $account;
    }
}
