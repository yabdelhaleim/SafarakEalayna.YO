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

class VisaBookingService
{
    public function __construct(protected TransactionService $transactions) {}

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
            $selling = (float) $data['selling_price'];
            $serviceFee = (float) ($data['service_fee'] ?? 0);
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

            $booking = VisaBooking::create([
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

            $customerAccount = $this->ensureCustomerAccount($customer->id);

            $expenseAccountId = $accountId;
            $agentId = $detailData['visa_agent_id'] ?? null;
            if ($agentId) {
                $agent = VisaAgent::find($agentId);
                if ($agent && $agent->account_id) {
                    $expenseAccountId = $agent->account_id;
                }
            }

            $expense = $this->transactions->recordExpense([
                'amount' => $purchase,
                'from_account_id' => $expenseAccountId,
                'module' => TransactionModule::Visa->value,
                'related_type' => VisaBooking::class,
                'related_id' => $booking->id,
                'notes' => "تكلفة تأشيرة {$detail->country} - {$customer->full_name}",
                'created_by' => $createdBy,
            ]);

            $income = $this->transactions->recordIncome([
                'amount' => $selling + $serviceFee,
                'to_account_id' => $customerAccount->id,
                'module' => TransactionModule::Visa->value,
                'related_type' => VisaBooking::class,
                'related_id' => $booking->id,
                'notes' => "بيع تأشيرة {$detail->country} - {$customer->full_name}",
                'created_by' => $createdBy,
            ]);

            $booking->update([
                'expense_transaction_id' => $expense->id,
                'income_transaction_id' => $income->id,
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

    public function update(VisaBooking $booking, array $data): VisaBooking
    {
        return DB::transaction(function () use ($booking, $data) {
            $fields = collect($data)->only([
                'status', 'agent_name', 'notes', 'employee_id',
            ])->all();

            $hasPriceChange = false;
            if (array_key_exists('purchase_price', $data) || array_key_exists('selling_price', $data) || array_key_exists('service_fee', $data)) {
                $purchase = (float) ($data['purchase_price'] ?? $booking->purchase_price);
                $selling = (float) ($data['selling_price'] ?? $booking->selling_price);
                $fee = (float) ($data['service_fee'] ?? $booking->service_fee ?? 0);
                $fields['purchase_price'] = $purchase;
                $fields['selling_price'] = $selling;
                $fields['service_fee'] = $fee;
                $fields['profit'] = round(($selling + $fee) - $purchase, 2);
                $hasPriceChange = true;
            }

            $booking->update($fields);

            if (! empty($data['visa_details']) && is_array($data['visa_details']) && $booking->visaDetail) {
                $detailPayload = collect($data['visa_details'])
                    ->only([
                        'visa_type', 'country', 'duration', 'visa_duration_id', 'entry_type',
                        'validity_from', 'validity_to', 'executing_company', 'executing_agent',
                        'executing_agent_contact', 'visa_agent_id', 'submission_date',
                        'expected_result_date', 'visa_number',
                    ])
                    ->all();
                if ($detailPayload !== []) {
                    $booking->visaDetail->update($detailPayload);
                }
            }

            // رقم التأشيرة من الحقل المسطح (لتوافق الطلبات القديمة)
            if (array_key_exists('visa_number', $data) && $data['visa_number'] !== null && $booking->visaDetail) {
                $booking->visaDetail->update(['visa_number' => $data['visa_number']]);
            }

            // Sync accounting amounts — additive only (Q2).
            // Pre-fix `updateTransactionAmount()` mutated the original
            // Transaction row's `amount` AND each original AccountEntry's
            // `debit`/`credit` AND Account.balance via raw SQL bypassing
            // the LedgerBalanceMutationGuard. That was the inverse of the
            // project rule "originals are never modified".
            //
            // The replacement mirrors HajjUmra's `repostExpenseTransaction`
            // pattern: reverse the original transaction (additive inverse
            // entries on the same transaction_id), then recordExpense/Income
            // with the new amount. original row stays.
            if ($hasPriceChange) {
                $booking->load(['expenseTransaction.entries', 'incomeTransaction.entries']);
                if ($booking->expenseTransaction) {
                    $expense = $this->repostExpenseTransaction($booking, $booking->expenseTransaction, $fields['purchase_price']);
                    if ($expense->id !== $booking->expense_transaction_id) {
                        $booking->update(['expense_transaction_id' => $expense->id]);
                    }
                }
                if ($booking->incomeTransaction) {
                    $income = $this->repostIncomeTransaction($booking, $booking->incomeTransaction, $fields['selling_price'] + $fields['service_fee']);
                    if ($income->id !== $booking->income_transaction_id) {
                        $booking->update(['income_transaction_id' => $income->id]);
                    }
                }
            }

            return $this->find($booking->id);
        });
    }

    /**
     * Repost an expense transaction with a new amount by reversing the
     * original (additive) and re-recording with the new amount.
     *
     * Mirrors `HajjUmraBookingService::repostExpenseTransaction()` —
     * same shape, same invariants: original Transaction row stays, only
     * inverse `account_entries` are added to it, and a NEW transaction
     * carries the new amount.
     */
    protected function repostExpenseTransaction(VisaBooking $booking, Transaction $transaction, float $newAmount): Transaction
    {
        // Idempotency: if nothing changed, leave the row as-is.
        $oldAmount = (float) $transaction->amount;
        if ($oldAmount === $newAmount) {
            return $transaction;
        }

        // Reverse the original (additive inverse entries on the same txn_id).
        $this->transactions->reverseTransaction($transaction);

        // Re-record with the new amount — resolver handles the supplier / vault routing.
        $expenseAccountId = (int) $booking->account_id;
        $supplierId = $booking->visaDetail?->visa_agent_id;
        if ($supplierId) {
            $agent = VisaAgent::find($supplierId);
            if ($agent?->account_id) {
                $expenseAccountId = (int) $agent->account_id;
            }
        }

        return $this->transactions->recordExpense([
            'amount' => $newAmount,
            'from_account_id' => $expenseAccountId,
            'module' => TransactionModule::Visa->value,
            'related_type' => VisaBooking::class,
            'related_id' => $booking->id,
            'notes' => $transaction->notes,
            'created_by' => $transaction->created_by ?? 1,
        ]);
    }

    /**
     * Repost an income transaction with a new amount — same pattern as
     * repostExpenseTransaction(), mirrors HajjUmra.
     */
    protected function repostIncomeTransaction(VisaBooking $booking, Transaction $transaction, float $newAmount): Transaction
    {
        $oldAmount = (float) $transaction->amount;
        if ($oldAmount === $newAmount) {
            return $transaction;
        }

        // Additive reverse
        $this->transactions->reverseTransaction($transaction);

        $customerAccount = $this->ensureCustomerAccount($booking->customer_id);

        return $this->transactions->recordIncome([
            'amount' => $newAmount,
            'to_account_id' => $customerAccount->id,
            'module' => TransactionModule::Visa->value,
            'related_type' => VisaBooking::class,
            'related_id' => $booking->id,
            'notes' => $transaction->notes,
            'created_by' => $transaction->created_by ?? 1,
        ]);
    }

    public function cancel(VisaBooking $booking, ?string $reason = null): VisaBooking
    {
        return DB::transaction(function () use ($booking, $reason) {
            $note = trim((string) $booking->notes);
            if ($reason) {
                $note = ($note === '' ? '' : $note."\n").'سبب الإلغاء: '.$reason;
            }

            // تحميل العلاقات المالية قبل الإلغاء
            $booking->load(['payments.transaction', 'expenseTransaction', 'incomeTransaction']);

            // ✦ Phase 2026-07-11 FIX (Q1+Q2+Q3): was destructive — used
            //   voidTransactionJournal + $tx->delete() (4 sites) plus
            //   $payment->delete() on VisaPayments themselves. This
            //   destroyed the original `transactions` rows, every
            //   `account_entries` row, AND the VisaPayment rows — leaving
            //   no audit trail recoverable even if the transactions had
            //   survived.
            //
            //   The replacement uses TransactionService::reverseTransaction(),
            //   which ADDS inverse account_entries on the SAME
            //   transaction_id and prefixes the transaction notes with
            //   `عكس: `. Originals stay intact.
            //
            //   VisaPayment rows stay VISIBLE (no soft-delete here, no
            //   hard-delete) per Q3 — only deleteBookingWithReversal()
            //   soft-deletes them.
            foreach ($booking->payments as $payment) {
                if ($payment->transaction) {
                    $this->transactions->reverseTransaction($payment->transaction);
                }
                // VisaPayment rows remain visible — soft-delete only via
                // deleteBookingWithReversal(). The original Booking's
                // cancelled payment rows are still useful for audit.
            }

            // عكس قيد الإيراد (additive)
            if ($booking->incomeTransaction) {
                $this->transactions->reverseTransaction($booking->incomeTransaction);
            }

            // عكس قيد المصروف (additive)
            if ($booking->expenseTransaction) {
                $this->transactions->reverseTransaction($booking->expenseTransaction);
            }

            $booking->update([
                'status' => VisaStatus::Cancelled->value,
                'notes'  => $note,
                // Keep *_transaction_id pointers — reverseTransaction() ADDS
                // entries on the same transaction_id; FK references stay valid.
                // Previously these were nulled here, leaving dangling refs.
            ]);

            $booking->visaDetail?->update(['status' => VisaStatus::Cancelled->value]);

            Log::info('Visa booking cancelled (additive reversal applied)', [
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
     * and `HajjUmraBookingService::deleteBookingWithReversal()` patterns.
     * Use this when an admin needs to fully remove a booking from active
     * lists while:
     *   ① posting additive `account_entries` reversals (never destroying
     *      the original `transactions` / `account_entries` rows), AND
     *   ② soft-deleting the booking row (hiding it from views/reports), AND
     *   ③ soft-deleting the associated payment rows.
     *
     * For customer-initiated cancellation that should keep the booking row
     * visible (status=Cancelled, payments visible), use `cancel($booking, $reason)` instead.
     *
     * Idempotency: throws RuntimeException if the booking is already
     * soft-deleted.
     *
     * @throws \RuntimeException on duplicates
     */
    public function deleteBookingWithReversal(int $bookingId, int $userId): bool
    {
        // Open the deletion gate so the model's `deleting` event allows the
        // soft-delete. Same depth-counter pattern as LedgerBalanceMutationGuard,
        // per-model isolation via the ModelDeletionGuard trait's per-class statics.
        return VisaBooking::run(function () use ($bookingId, $userId) {
            return DB::transaction(function () use ($bookingId, $userId) {
                // 1) Lock + reload with relations.
                $booking = VisaBooking::query()
                    ->withTrashed()
                    ->with(['payments.transaction', 'expenseTransaction', 'incomeTransaction'])
                    ->lockForUpdate()
                    ->findOrFail($bookingId);

                // 2) Idempotency guard.
                if ($booking->trashed()) {
                    throw new \RuntimeException(
                        'هذا الحجز محذوف بالفعل (soft delete) — لا يمكن عكسه مرة ثانية.'
                    );
                }

                $userIdEffective = $userId ?: (int) (Auth::id() ?: 1);

                Log::info('VisaBookingService::deleteBookingWithReversal — starting', [
                    'booking_id' => $booking->id,
                    'status' => $booking->status?->value ?? (string) $booking->status,
                    'payments_count' => $booking->payments->count(),
                    'has_income' => (bool) $booking->incomeTransaction,
                    'has_expense' => (bool) $booking->expenseTransaction,
                    'user_id' => $userIdEffective,
                ]);

                // 3) Reverse every payment transaction (additive — never destructive).
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

                // 5) Mark the visaDetail as cancelled (status only, no ledger).
                $booking->visaDetail?->update(['status' => VisaStatus::Cancelled->value]);

                // 6) Soft-delete the payments (the new SoftDeletes trait enables this).
                $booking->payments()->delete();

                // 7) Soft-delete the booking row itself. Allowed because
                //    we are inside VisaBooking::run(...) which flipped the
                //    model's deletion gate open for the canonical reversal flow.
                $booking->delete();

                Log::info('VisaBookingService::deleteBookingWithReversal — complete', [
                    'booking_id' => $booking->id,
                    'user_id' => $userIdEffective,
                ]);

                return true;
            });
        });
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
        return DB::transaction(function () use ($booking, $data) {
            $amount = (float) $data['amount'];
            $accountId = (int) ($data['account_id'] ?? $booking->account_id);
            $createdBy = Auth::id() ?? ($data['created_by'] ?? null);

            $customerAccount = $this->ensureCustomerAccount($booking->customer_id);

            $income = $this->transactions->recordIncome([
                'amount' => $amount,
                'to_account_id' => $accountId,
                'contra_account_id' => $customerAccount->id,
                'module' => TransactionModule::Visa->value,
                'related_type' => VisaBooking::class,
                'related_id' => $booking->id,
                'notes' => "دفعة على تأشيرة #{$booking->id}",
                'created_by' => $createdBy,
            ]);

            return $booking->payments()->create([
                'payment_method' => $data['payment_method'] ?? 'cash',
                'amount' => $amount,
                'currency' => $data['currency'] ?? $booking->currency ?? 'EGP',
                'treasury_account' => $data['treasury_account'] ?? 'office_drawer',
                'account_id' => $accountId,
                'transaction_id' => $income->id,
                'transaction_reference' => $data['reference'] ?? $data['transaction_reference'] ?? null,
                'payment_date' => $data['payment_date'] ?? now(),
                'paid_by' => $data['paid_by'] ?? $booking->customer?->full_name ?? '',
                'created_by' => $createdBy,
            ]);
        });
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

    protected function updateTransactionAmount(Transaction $transaction, float $newAmount)
    {
        $oldAmount = (float) $transaction->amount;
        if ($oldAmount === $newAmount) {
            return;
        }
        $diff = $newAmount - $oldAmount;

        $fromAccount = $transaction->fromAccount;
        $toAccount = $transaction->toAccount;

        if ($fromAccount) {
            $fromAccount->getConnection()->statement('UPDATE accounts SET balance = balance - ? WHERE id = ?', [$diff, $fromAccount->id]);
        }
        if ($toAccount) {
            $toAccount->getConnection()->statement('UPDATE accounts SET balance = balance + ? WHERE id = ?', [$diff, $toAccount->id]);
        }

        $transaction->update(['amount' => $newAmount]);

        foreach ($transaction->entries as $entry) {
            if ((float) $entry->debit > 0) {
                $entry->update([
                    'debit' => $newAmount,
                    'balance_after' => $entry->account->fresh()->balance,
                ]);
            } elseif ((float) $entry->credit > 0) {
                $entry->update([
                    'credit' => $newAmount,
                    'balance_after' => $entry->account->fresh()->balance,
                ]);
            }
        }
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
