<?php

namespace App\Services\Visa;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Enums\VisaStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\HajjUmra\VisaAgent;
use App\Models\Transaction;
use App\Models\VisaBooking;
use App\Models\VisaDetail;
use App\Models\VisaPayment;
use App\Services\Finance\TransactionService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Refactored 2026-07-20:
 *   - cancel()/refund()/deleteBookingWithReversal() moved to VisaRefundService
 *   - repostExpenseTransaction()/repostIncomeTransaction() moved to VisaModificationService
 *   - This class now only owns: paginate/find/create/update/addPayment/
 *     ensureCustomerAccount/resolveCustomer (the "happy path" CRUD + payments).
 *
 * Backwards compatibility: keep thin `cancel()` shim that delegates to
 * VisaRefundService so existing callers (Filament pages, tests) do not break.
 */
class VisaBookingService
{
    public function __construct(protected TransactionService $transactions) {}

    /**
     * @deprecated Use App\Services\Visa\VisaRefundService::cancel() directly.
     * Kept as a thin shim so legacy Filament / tests keep working.
     */
    public function cancel(VisaBooking $booking, ?string $reason = null): VisaBooking
    {
        return app(VisaRefundService::class)->cancel($booking, $reason);
    }

    /**
     * @deprecated Use App\Services\Visa\VisaRefundService::deleteWithReversal().
     */
    public function deleteBookingWithReversal(int $bookingId, int $userId): bool
    {
        return app(VisaRefundService::class)->deleteWithReversal($bookingId, $userId);
    }

    /**
     * @deprecated Use App\Services\Visa\VisaModificationService::repostExpense().
     */
    public function repostExpenseTransaction(VisaBooking $booking, Transaction $transaction, float $newAmount): Transaction
    {
        return app(VisaModificationService::class)->repostExpense($booking, $transaction, $newAmount);
    }

    /**
     * @deprecated Use App\Services\Visa\VisaModificationService::repostIncome().
     */
    public function repostIncomeTransaction(VisaBooking $booking, Transaction $transaction, float $newAmount): Transaction
    {
        $customerAccount = $this->ensureCustomerAccount($booking->customer_id);

        return app(VisaModificationService::class)
            ->repostIncome($booking, $transaction, $newAmount, $customerAccount->id);
    }

    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = VisaBooking::with([
            'customer',
            'visaDetail.agent',
            'visaDetail.durationRow',
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
        if (! empty($filters['country'])) {
            $query->whereHas('visaDetail', fn ($q) => $q->where('country', $filters['country']));
        }
        if (! empty($filters['visa_type'])) {
            $query->whereHas('visaDetail', fn ($q) => $q->where('visa_type', $filters['visa_type']));
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
    }

    public function find(int $id): VisaBooking
    {
        return VisaBooking::with([
            'customer',
            'visaDetail.agent',
            'visaDetail.durationRow',
            'employee',
            'account',
            'expenseTransaction',
            'incomeTransaction',
            'payments.account',
            'payments.transaction',
        ])->findOrFail($id);
    }

    public function create(array $data): VisaBooking
    {
        return DB::transaction(function () use ($data) {
            $customer = $this->resolveCustomer($data['customer'] ?? null, $data['customer_id'] ?? null);

            $detailData = $data['visa_details'] ?? [];
            $detail = VisaDetail::create([
                'visa_type' => $detailData['visa_type'] ?? null,
                'country' => $detailData['country'] ?? null,
                'duration' => $detailData['duration'] ?? null,
                'visa_duration_id' => $detailData['visa_duration_id'] ?? null,
                'entry_type' => $detailData['entry_type'] ?? null,
                'validity_from' => $detailData['validity_from'] ?? null,
                'validity_to' => $detailData['validity_to'] ?? null,
                'executing_company' => $detailData['executing_company'] ?? null,
                'executing_agent' => $detailData['executing_agent'] ?? null,
                'executing_agent_contact' => $detailData['executing_agent_contact'] ?? null,
                'visa_agent_id' => $detailData['visa_agent_id'] ?? null,
                'submission_date' => $detailData['submission_date'] ?? now(),
                'expected_result_date' => $detailData['expected_result_date'] ?? null,
                'visa_number' => $detailData['visa_number'] ?? null,
                'status' => $detailData['status'] ?? VisaStatus::Submitted->value,
            ]);

            $purchase = (float) $data['purchase_price'];
            $selling  = (float) $data['selling_price'];
            $serviceFee = (float) ($data['service_fee'] ?? 0);

            // ─────────────────────────────────────────────────────────────
            // FIX VISA-D02 (Class-B, 2026-08-15):
            //   Service-layer price boundary validation must occur BEFORE
            //   any DB insert, financial mutation, or account balance change.
            //   HTTP validation (StoreVisaBookingRequest) catches negative
            //   prices from the API, but programmatic / Filament callers that
            //   bypass the FormRequest could previously persist negative prices
            //   when selling_price + service_fee > 0 kept the total positive.
            //   This guard is the authoritative service-level invariant.
            // ─────────────────────────────────────────────────────────────
            if ($purchase < 0) {
                throw new \InvalidArgumentException('سعر الشراء لا يمكن أن يكون سالباً (purchase_price=' . $purchase . ').');
            }
            if ($selling < 0) {
                throw new \InvalidArgumentException('سعر البيع لا يمكن أن يكون سالباً (selling_price=' . $selling . ').');
            }
            if ($serviceFee < 0) {
                throw new \InvalidArgumentException('رسوم الخدمة لا يمكن أن تكون سالبة (service_fee=' . $serviceFee . ').');
            }

            $profit = round(($selling + $serviceFee) - $purchase, 2);

            $accountId = (int) ($data['account_id'] ?? 0);
            if ($accountId === 0) {
                $vault = Account::getModuleVault('visas');
                if (! $vault) {
                    throw new \RuntimeException('لم يتم العثور على الخزينة الرسمية لموديول التأشيرات. يرجى اختيار حساب أو ضبط الخزينة الرسمية.');
                }
                $accountId = $vault->id;
            }

            $createdBy = Auth::id() ?? ($data['employee_id'] ?? null);

            // Wrapped in VisaBooking::runProfitMutation() so the ModelProfitMutationGuard lets
            // the canonical `profit` write through.
            $booking = VisaBooking::runProfitMutation(function () use ($customer, $detail, $data, $purchase, $selling, $serviceFee, $profit, $accountId, $createdBy) {
                return VisaBooking::create([
                'customer_id' => $customer->id,
                'visa_detail_id' => $detail->id,
                'module' => TransactionModule::Visa->value,
                'purchase_price' => $purchase,
                'selling_price' => $selling,
                'service_fee' => $serviceFee,
                'profit' => $profit,
                'currency' => $data['currency'] ?? 'EGP',
                'status' => $data['status'] ?? VisaStatus::Submitted->value,
                'agent_name' => $data['agent_name'] ?? ($customer->full_name ?? ''),
                'notes' => $data['notes'] ?? null,
                'account_id' => $accountId,
                'employee_id' => $data['employee_id'] ?? $createdBy,
                'created_by' => $createdBy,
            ]);
            });

            $customerAccount = $this->ensureCustomerAccount($customer->id);

            $expenseAccountId = $accountId;
            $agentId = $detailData['visa_agent_id'] ?? null;
            if ($agentId) {
                $agent = VisaAgent::find($agentId);
                if ($agent && $agent->account_id) {
                    $expenseAccountId = $agent->account_id;
                }
            }

            $expenseId = null;
            if ($purchase > 0) {
                $expense = $this->transactions->recordExpense([
                    'amount' => $purchase,
                    'from_account_id' => $expenseAccountId,
                    'currency' => $booking->currency,           // Phase 7: per-currency clearing routing
                    'module' => TransactionModule::Visa->value,
                    'related_type' => VisaBooking::class,
                    'related_id' => $booking->id,
                    'notes' => "تكلفة تأشيرة {$detail->country} - {$customer->full_name}",
                    'created_by' => $createdBy,
                ]);
                $expenseId = $expense->id;
            }

            $incomeId = null;
            if (($selling + $serviceFee) > 0) {
                $income = $this->transactions->recordIncome([
                    'amount' => $selling + $serviceFee,
                    'to_account_id' => $customerAccount->id,
                    'currency' => $booking->currency,           // Phase 7: per-currency clearing routing
                    'module' => TransactionModule::Visa->value,
                    'related_type' => VisaBooking::class,
                    'related_id' => $booking->id,
                    'notes' => "بيع تأشيرة {$detail->country} - {$customer->full_name}",
                    'created_by' => $createdBy,
                ]);
                $incomeId = $income->id;
            }

            $booking->update([
                'expense_transaction_id' => $expenseId,
                'income_transaction_id' => $incomeId,
            ]);

            if (! empty($data['initial_payment']) && (float) ($data['initial_payment']['amount'] ?? 0) > 0) {
                $this->addPayment($booking, $data['initial_payment']);
            }

            Log::info('Visa booking created', [
                'booking_id' => $booking->id,
                'customer_id' => $customer->id,
                'detail_id' => $detail->id,
                'profit' => $profit,
            ]);

            return $this->find($booking->id);
        });
    }

    /**
     * @deprecated INCIDENT-2026-08-17: Tourism No-Edit Contract. Always throws.
     *   Cancellation is the supported correction path.
     */
    public function update(VisaBooking $booking, array $data): VisaBooking
    {
        throw new \LogicException(
            'VisaBookingService::update is disabled by Tourism no-edit contract (2026-08-17). '
            .'Cancellation is the supported correction path.'
        );
    }

    /**
     * Record a debt payment from the Filament VisaAgentDebtStatement page.
     *
     * Replaces the inline `Transaction::create()` + missing-AccountEntry
     * pattern that lived in `VisaAgentDebtStatement::payDebt()`. That
     * original wiring dropped `'account_id'` silently (it was not in the
     * Transaction model's $fillable), created a Transaction row with
     * NO AccountEntry lines, and ran without `lockForUpdate` or
     * `LedgerBalanceMutationGuard::run()`. The result: the customer's
     * remaining_amount decreased in the UI, but no cashbook nor
     * customer account balance moved, and no entry appeared in
     * customerStatement().
     *
     * This method mirrors the contract of `addPayment()` exactly:
     *   - locks the booking with `lockForUpdate()`
     *   - resolves the customer ledger account
     *   - posts `recordIncome` against the user-selected cashbox
     *   - writes the matching VisaPayment in the same transaction
     *
     * @throws \RuntimeException on insufficient remaining_amount or missing booking
     */
    public function addDebtPayment(VisaBooking $booking, array $data): VisaPayment
    {
        return DB::transaction(function () use ($booking, $data) {
            $amount = (float) $data['amount'];
            $cashboxAccountId = (int) $data['account_id'];
            $createdBy = (int) (Auth::id() ?? ($data['created_by'] ?? 1));

            if ($amount <= 0) {
                throw new \InvalidArgumentException('مبلغ السداد يجب أن يكون أكبر من صفر.');
            }

            // Idempotency / over-payment guard
            $booking->refresh();
            if ($booking->status === VisaStatus::Cancelled) {
                throw new \RuntimeException('لا يمكن السداد على حجز ملغى.');
            }
            if ($amount > ((float) $booking->remaining_amount + 0.01)) {
                throw new \RuntimeException(
                    'مبلغ السداد يتجاوز المبلغ المتبقي على الحجز (' . round((float) $booking->remaining_amount, 2) . ').'
                );
            }

            // Use the public resolver (raised to public for this call site).
            $customerAccount = $this->ensureCustomerAccount($booking->customer_id);

            // recordIncome creates balanced debit + credit AccountEntry rows
            // and updates both account balances inside the LedgerBalanceMutationGuard.
            $income = $this->transactions->recordIncome([
                'amount' => $amount,
                'to_account_id' => $cashboxAccountId,    // the cashbox the customer paid into
                'contra_account_id' => $customerAccount->id,
                'currency' => $booking->currency,           // Phase 7: per-currency clearing routing
                'module' => TransactionModule::Visa->value,
                'related_type' => VisaBooking::class,
                'related_id' => $booking->id,
                'notes' => 'سداد تأشيرة #' . $booking->id . ': ' . ($data['notes'] ?? ''),
                'created_by' => $createdBy,
                'allow_from_negative' => true,  // cashbox may go negative if not pre-funded
            ]);

            $payment = $booking->payments()->create([
                'payment_method' => $data['payment_method'] ?? 'cash',
                'amount' => $amount,
                'currency' => $data['currency'] ?? $booking->currency ?? 'EGP',
                'treasury_account' => $data['treasury_account'] ?? 'office_drawer',
                'account_id' => $cashboxAccountId,
                'transaction_id' => $income->id,
                'transaction_reference' => $data['reference'] ?? $data['transaction_reference'] ?? null,
                'payment_date' => $data['payment_date'] ?? now(),
                'paid_by' => $data['paid_by'] ?? $booking->customer?->full_name ?? '',
                'created_by' => $createdBy,
            ]);

            Log::info('Visa booking debt payment recorded (additive path)', [
                'booking_id' => $booking->id,
                'payment_id' => $payment->id,
                'transaction_id' => $income->id,
                'amount' => $amount,
                'cashbox_account_id' => $cashboxAccountId,
            ]);

            return $payment;
        });
    }

    public function addPayment(VisaBooking $booking, array $data): VisaPayment
    {
        // ─────────────────────────────────────────────────────────────────
        // Lifecycle guard: reject payments on cancelled / refunded / deleted
        // bookings. Same invariant enforced by addDebtPayment() and the
        // HajjUmra counterpart.
        // ─────────────────────────────────────────────────────────────────
        $status = $booking->status instanceof \BackedEnum ? $booking->status->value : (string) $booking->status;
        if ($status === VisaStatus::Cancelled->value) {
            throw new \RuntimeException(
                'لا يمكن إضافة دفعة على حجز تأشيرة مُلغى (status=cancelled). '
                . 'يجب استخدام VisaRefundService::refund() لاسترداد المبالغ أو VisaRefundService::deleteWithReversal() للعكس الإداري.'
            );
        }
        if ($status === VisaStatus::Refunded->value) {
            throw new \RuntimeException(
                'لا يمكن إضافة دفعة على حجز تأشيرة تم استرداده بالكامل (status=refunded).'
            );
        }
        if ($booking->trashed()) {
            throw new \RuntimeException(
                'لا يمكن إضافة دفعة على حجز تأشيرة محذوف (soft-deleted). '
                . 'يجب استخدام VisaRefundService::deleteWithReversal() للعكس الإداري.'
            );
        }

        // ─────────────────────────────────────────────────────────────────
        // IDEMPOTENCY — 2026-08-15:
        //   If the caller supplies an idempotency_key we apply three-layer
        //   replay protection (pre-check → DB unique index → outer catch).
        //   Legacy callers without a key keep their existing behaviour.
        //
        //   Key semantics:
        //     - Same booking + same key  → idempotent return (existing row)
        //     - Same booking + diff key  → new legitimate payment
        //     - Diff booking + same key  → new legitimate payment
        //     - No key supplied          → no replay protection (legacy)
        // ─────────────────────────────────────────────────────────────────
        $idempotencyKey = isset($data['idempotency_key']) && $data['idempotency_key'] !== ''
            ? (string) $data['idempotency_key']
            : null;

        try {
            return DB::transaction(function () use ($booking, $data, $idempotencyKey) {
                // Serialize concurrent calls on the same booking with a
                // row-level lock acquired BEFORE reading paid_amount.
                // (BUG-FIX 2026-08-14: prevents two concurrent calls reading
                // the same paid_amount snapshot and both passing the
                // overpayment guard.)
                $locked = VisaBooking::lockForUpdate()->findOrFail($booking->id);

                // ─── Layer 1 — pre-check ────────────────────────────────
                // If a payment already exists for this (booking, key), return
                // it without any financial mutation (idempotent replay).
                if ($idempotencyKey !== null) {
                    $existing = VisaPayment::query()
                        ->where('visa_booking_id', $locked->id)
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();
                    if ($existing) {
                        $existing->idempotent_replay = true;
                        return $existing;
                    }
                }

                $amount    = (float) $data['amount'];
                $accountId = (int) ($data['account_id'] ?? $locked->account_id);
                $createdBy = Auth::id() ?? ($data['created_by'] ?? null);

                // Overpayment guard: reject if amount > remaining.
                $totalDue    = (float) $locked->selling_price + (float) ($locked->service_fee ?? 0);
                $paidAlready = (float) $locked->paid_amount;
                $remaining   = max(0.0, $totalDue - $paidAlready);
                if ($amount > ($remaining + 0.01)) {
                    throw new \RuntimeException(
                        'مبلغ الدفعة (' . round($amount, 2) . ') يتجاوز المبلغ المتبقي على الحجز (' . round($remaining, 2) . ').'
                    );
                }

                $customerAccount = $this->ensureCustomerAccount($locked->customer_id);

                // ─── FIX VISA-D01 (Class-A, 2026-08-15) ─────────────────
                //
                // ROOT CAUSE:
                //   The original code called recordIncome() with related_type=
                //   VisaBooking and related_id=booking->id. After the FC-AUDIT
                //   D1 fix (2026-08-14), recordIncome() routes through
                //   recordJournalTransfer() with type='Income'. The duplicate-
                //   income guard in recordJournalTransfer (lines 650–675) then
                //   blocks every payment because the booking's initial SALE
                //   income transaction is already active.
                //
                // CORRECT SEMANTICS:
                //   A customer payment is a COLLECTION against an existing
                //   receivable (AR), NOT a new income/sale event. The accounting
                //   entry is:
                //
                //     Dr  Customer AR account    (customer owes less)
                //     Cr  Treasury/Cash account  (office receives cash)
                //
                //   i.e. a transfer FROM the customer AR account TO the
                //   receiving treasury account. type=Transfer (NOT Income).
                //
                //   This is identical to the pattern used by:
                //     - HajjUmraBookingService::addPayment() (lines 778–788)
                //
                //   The initial booking sale (recordIncome on create()) stays
                //   untouched — it remains the ONE active Income transaction.
                // ─────────────────────────────────────────────────────────
                $transfer = $this->transactions->recordJournalTransfer([
                    'amount'          => $amount,
                    'from_account_id' => $customerAccount->id,   // customer AR ↓
                    'to_account_id'   => $accountId,             // treasury ↑
                    'type'            => \App\Enums\TransactionType::Transfer->value,
                    'module'          => TransactionModule::Visa->value,
                    'related_type'    => VisaBooking::class,
                    'related_id'      => $locked->id,
                    'notes'           => "دفعة على تأشيرة #{$locked->id}",
                    'created_by'      => $createdBy,
                    'currency'        => $locked->currency,
                    // allow_from_negative: customer AR can go negative if the
                    // booking was partially pre-paid or the customer has a
                    // credit balance. Same flag used by addDebtPayment().
                    'allow_from_negative' => true,
                ]);

                // ─── Layer 2 backstop: DB unique constraint ──────────────
                // The pre-check + lockForUpdate should prevent duplicates in
                // normal operation. The try/catch below is the final backstop
                // for pathological race conditions.
                try {
                    return $locked->payments()->create([
                        'payment_method'       => $data['payment_method'] ?? 'cash',
                        'amount'               => $amount,
                        'currency'             => $data['currency'] ?? $locked->currency ?? 'EGP',
                        'treasury_account'     => $data['treasury_account'] ?? 'office_drawer',
                        'account_id'           => $accountId,
                        'transaction_id'       => $transfer->id,
                        'transaction_reference'=> $data['reference'] ?? $data['transaction_reference'] ?? null,
                        'idempotency_key'      => $idempotencyKey,
                        'payment_date'         => $data['payment_date'] ?? now(),
                        'paid_by'              => $data['paid_by'] ?? $locked->customer?->full_name ?? '',
                        'created_by'           => $createdBy,
                    ]);
                } catch (\Illuminate\Database\QueryException $qe) {
                    // Layer 2: if a concurrent request raced past the pre-check
                    // and hit the DB unique index, return the winning row.
                    if ($this->isDuplicateKeyError($qe) && $idempotencyKey !== null) {
                        $existing = VisaPayment::query()
                            ->where('visa_booking_id', $locked->id)
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
            // Outer catch: DB::transaction re-raised a duplicate-key exception
            // after the inner transaction committed the winning row. Return the
            // existing payment as an idempotent result.
            if ($this->isDuplicateKeyError($qe) && $idempotencyKey !== null) {
                $existing = VisaPayment::query()
                    ->where('visa_booking_id', $booking->id)
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
     * Identify a "duplicate entry on unique index" QueryException.
     * MySQL: SQLSTATE 23000, error code 1062.
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
                'full_name', 'national_id', 'passport_number', 'passport_expiry',
                'date_of_birth', 'city', 'affiliation', 'notes',
            ])->all()
        );
    }

    /**
     * Public so external callers (Filament custom Actions, controllers)
     * can resolve the customer's ledger Account the same way the booking-flow
     * internals do — without duplicating the Account::create wrapping logic.
     */
    public function ensureCustomerAccount(int $customerId): Account
    {
        $customer = Customer::findOrFail($customerId);

        if ($customer->account_id) {
            $account = Account::find($customer->account_id);
            if ($account) {
                // Phase 1.Bend3 fix: CustomerLedgerObserver creates a generic
                // 'office'-tagged account the moment a Customer row is
                // inserted. When that customer is later used in a Visa
                // booking flow we re-tag the account to 'visas' so it
                // surfaces in the strict module_type='visas' queries
                // (TreasuryService line 529). Wrapped in
                // LedgerBalanceMutationGuard because touching `balance`
                // — even to confirm 0.00 — would otherwise trip the
                // Account::updating boot guard.
                if ($account->module_type !== 'visas') {
                    LedgerBalanceMutationGuard::run(function () use ($account) {
                        $account->module_type = 'visas';
                        $account->save();
                    });
                }

                return $account;
            }
        }

        // Create new account for customer
        return LedgerBalanceMutationGuard::run(fn () => DB::transaction(function () use ($customer) {
            $account = Account::create([
                'name' => 'حساب العميل: '.$customer->full_name,
                'type' => AccountType::Customer,
                'balance' => 0,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OWNER,
                'module_type' => 'visas',
                'is_module_vault' => false,
                'notes' => 'حساب تلقائي للعميل #'.$customer->id,
                'created_by' => Auth::id() ?? 1,
            ]);

            $customer->update(['account_id' => $account->id]);

            Log::info('Customer ledger account created automatically', [
                'customer_id' => $customer->id,
                'account_id' => $account->id,
            ]);

            return $account;
        }));
    }
}
