<?php

namespace App\Services\Finance;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Support\Finance\AccountModuleContract;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TransactionService
{
    public function __construct(
        protected LedgerClearingAccounts $ledgerClearingAccounts,
        protected TransactionAuditStamper $auditStamper,
    ) {}

    protected function persistTransaction(array $attrs): Transaction
    {
        $transaction = Transaction::create($attrs);
        $this->auditStamper->stamp($transaction);

        return $transaction;
    }

    /**
     * Record an expense transaction.
     * When حساب الإقفال (التكلفة) can be resolved, posts a balanced two-leg journal
     * (cash / خزينة ↓ ، حساب إقفال ↑). Otherwise respects legacy single-leg or fails if strict.
     *
     * @param  array  $data  Keys: amount, from_account_id, module, contra_account_id?,
     *                       related_type?, related_id?, notes?, created_by?
     *
     * @throws \Exception|\Throwable
     */
    public function recordExpense(array $data): Transaction
    {
        $strict = (bool) config('accounting.strict_double_entry', true);
        $allowLegacy = (bool) config('accounting.allow_legacy_single_leg_fallback', false);
        $fromId = (int) $data['from_account_id'];
        $amount = (float) $data['amount'];
        $moduleValue = $data['module'] ?? TransactionModule::General->value;

        $explicitContra = isset($data['contra_account_id']) ? (int) $data['contra_account_id'] : null;
        $resolvedContra = $explicitContra ?: $this->ledgerClearingAccounts->expenseContraIdForModule((string) $moduleValue);

        if ($resolvedContra !== null && $resolvedContra !== $fromId) {
            return $this->recordJournalTransfer([
                'amount' => $amount,
                'converted_amount' => $data['converted_amount'] ?? null,
                'exchange_rate' => $data['exchange_rate'] ?? null,
                'from_account_id' => $fromId,
                'to_account_id' => $resolvedContra,
                'allow_from_negative' => $data['allow_from_negative'] ?? $this->ledgerClearingAccounts->isPrepaidAccountId($fromId),
                'module' => $moduleValue,
                'related_type' => $data['related_type'] ?? null,
                'related_id' => $data['related_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? Auth::id() ?? 1,
            ]);
        }

        if ($strict && ! $allowLegacy) {
            throw new \RuntimeException(
                'قيد المصروف يتطلب حساب إقفال تكاليف للموديول «'.$moduleValue.'». شغّل ترحيل الحسابات أو حدّد contra_account_id يدوياً.'
            );
        }

        return LedgerBalanceMutationGuard::run(fn () => DB::transaction(function () use ($data) {
            $transaction = $this->persistTransaction([
                'type' => TransactionType::Expense->value,
                'amount' => $data['amount'],
                'module' => $data['module'],
                'related_type' => $data['related_type'] ?? null,
                'related_id' => $data['related_id'] ?? null,
                'from_account_id' => $data['from_account_id'],
                'to_account_id' => null,
                'created_by' => $data['created_by'] ?? Auth::id(),
                'notes' => $data['notes'] ?? null,
            ]);

            $account = Account::where('id', $data['from_account_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($account->balance < $data['amount']) {
                throw new \Exception('Insufficient balance in account: '.$account->name);
            }

            $account->balance -= $data['amount'];
            $account->save();

            AccountEntry::create([
                'account_id' => $account->id,
                'transaction_id' => $transaction->id,
                'debit' => $data['amount'],
                'credit' => 0.00,
                'balance_after' => $account->balance,
            ]);

            Log::info('Expense recorded (legacy single-leg)', [
                'transaction_id' => $transaction->id,
                'account_id' => $account->id,
                'amount' => $data['amount'],
                'user_id' => Auth::id(),
            ]);

            return $transaction;
        }));
    }

    /**
     * Record an income / cash receipt.
     * عند وجود حساب إقفال إيرادات للموديول: مدين الإقفال ← دائن الخزينة/الحساب النقدي (قيد متوازن).
     *
     * ⚠️ Contract: this method always uses the income clearing account as the
     *    "from" leg of the journal. Callers cannot override this — if a
     *    custom source account is needed (e.g. for refunds, reversals,
     *    inter-treasury moves), use {@see self::recordJournalTransfer()}
     *    directly. Passing `from_account_id` here throws a RuntimeException
     *    rather than silently ignoring it (Bug #TX-001 fix).
     *
     * @param  array  $data  Keys: amount, to_account_id, module, contra_account_id?,
     *                       related_type?, related_id?, notes?, created_by?,
     *                       allow_contra_negative? (legacy journal flag, default true for income contra)
     *
     * @throws \RuntimeException if `from_account_id` is supplied (use recordJournalTransfer instead)
     * @throws \Exception|\Throwable
     */
    public function recordIncome(array $data): Transaction
    {
        // ✅ Bug #TX-001 fix: reject `from_account_id` explicitly. The income
        //    clearing account is *always* the from leg of an income record —
        //    silently ignoring a caller-supplied from_account_id masked bugs
        //    (e.g. refund flows thought they were pulling cash back from the
        //    treasury when they were actually pushing more income through
        //    the clearing account, double-counting revenue).
        if (isset($data['from_account_id']) && $data['from_account_id'] !== null) {
            throw new \RuntimeException(
                'recordIncome() لا يقبل from_account_id — حساب الإيراد دائماً ما يكون حساب إقفال الإيرادات. '.
                'للحركات العكسية (refund) أو التحويلات الخاصة استخدم recordJournalTransfer().'
            );
        }

        $strict = (bool) config('accounting.strict_double_entry', true);
        $allowLegacy = (bool) config('accounting.allow_legacy_single_leg_fallback', false);
        $toId = (int) $data['to_account_id'];
        $amount = (float) $data['amount'];
        $moduleValue = $data['module'] ?? TransactionModule::General->value;

        $explicitContra = isset($data['contra_account_id']) ? (int) $data['contra_account_id'] : null;

        $resolvedContra = $explicitContra;
        if ($resolvedContra === null || $resolvedContra === 0) {
            if ((string) $moduleValue === TransactionModule::Flight->value) {
                $resolvedContra = $this->ledgerClearingAccounts->incomeContraIdForFlightBooking();
            } else {
                $resolvedContra = $this->ledgerClearingAccounts->incomeContraIdForModule((string) $moduleValue);
            }
        }

        if ($resolvedContra !== null && $resolvedContra !== $toId) {
            return $this->recordJournalTransfer([
                'amount' => $amount,
                'converted_amount' => $data['converted_amount'] ?? null,
                'exchange_rate' => $data['exchange_rate'] ?? null,
                'from_account_id' => $resolvedContra,
                'to_account_id' => $toId,
                'allow_from_negative' => (bool) ($data['allow_contra_negative'] ?? true),
                'module' => $moduleValue,
                'related_type' => $data['related_type'] ?? null,
                'related_id' => $data['related_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? Auth::id() ?? 1,
            ]);
        }

        if ($strict && ! $allowLegacy) {
            throw new \RuntimeException(
                'قيد الإيراد يتطلب حساب إقفال إيرادات للموديول «'.$moduleValue.'». شغّل ترحيل الحسابات أو حدّد contra_account_id يدوياً.'
            );
        }

        return LedgerBalanceMutationGuard::run(fn () => DB::transaction(function () use ($data) {
            $transaction = $this->persistTransaction([
                'type' => TransactionType::Income->value,
                'amount' => $data['amount'],
                'module' => $data['module'],
                'related_type' => $data['related_type'] ?? null,
                'related_id' => $data['related_id'] ?? null,
                'from_account_id' => null,
                'to_account_id' => $data['to_account_id'],
                'created_by' => $data['created_by'] ?? Auth::id(),
                'notes' => $data['notes'] ?? null,
            ]);

            $account = Account::where('id', $data['to_account_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $account->balance += $data['amount'];
            $account->save();

            AccountEntry::create([
                'account_id' => $account->id,
                'transaction_id' => $transaction->id,
                'debit' => 0.00,
                'credit' => $data['amount'],
                'balance_after' => $account->balance,
            ]);

            Log::info('Income recorded (legacy single-leg)', [
                'transaction_id' => $transaction->id,
                'account_id' => $account->id,
                'amount' => $data['amount'],
                'user_id' => Auth::id(),
            ]);

            return $transaction;
        }));
    }

    /**
     * Reverse a previously recorded income or expense transaction.
     * Income reversal: debits (reduces) the to_account.
     * Expense reversal: credits (increases) the from_account.
     */
    public function reverseTransaction(Transaction $transaction): Transaction
    {
        return LedgerBalanceMutationGuard::run(fn () => DB::transaction(function () use ($transaction) {
            $reversalNote = 'عكس: '.($transaction->notes ?? '');

            // Finding #1 fix: reversal entry directions flipped to be opposite of the (now flipped) original.
//   - Income reversal (original was: to gets DEBIT entry, balance += amount):
//       to.balance -= amount, adds CREDIT entry (was DEBIT).
//   - Expense reversal (original was: from gets CREDIT entry, balance -= amount):
//       from.balance += amount, adds DEBIT entry (was CREDIT).
//   - Transfer reversal (original was: from CREDIT + to DEBIT):
//       from gets DEBIT (was CREDIT), to gets CREDIT (was DEBIT). Balance updates unchanged.
if ($transaction->type === TransactionType::Income->value || $transaction->type === TransactionType::Income) {
                $account = Account::where('id', $transaction->to_account_id)->lockForUpdate()->firstOrFail();
                $account->balance -= (float) $transaction->amount;
                $account->save();

                AccountEntry::create([
                    'account_id' => $account->id,
                    'transaction_id' => $transaction->id,
                    'debit' => 0.00,                       // flipped: was $amount
                    'credit' => $transaction->amount,       // flipped: was 0.00
                    'balance_after' => $account->balance,
                ]);
            } elseif ($transaction->type === TransactionType::Expense->value || $transaction->type === TransactionType::Expense) {
                $account = Account::where('id', $transaction->from_account_id)->lockForUpdate()->firstOrFail();
                $account->balance += (float) $transaction->amount;
                $account->save();

                AccountEntry::create([
                    'account_id' => $account->id,
                    'transaction_id' => $transaction->id,
                    'debit' => $transaction->amount,        // flipped: was 0.00
                    'credit' => 0.00,                       // flipped: was $transaction->amount
                    'balance_after' => $account->balance,
                ]);
            } elseif ($transaction->type === TransactionType::Transfer->value || $transaction->type === TransactionType::Transfer) {
                $fromAccount = Account::where('id', $transaction->from_account_id)->lockForUpdate()->firstOrFail();
                $toAccount = Account::where('id', $transaction->to_account_id)->lockForUpdate()->firstOrFail();

                $fromAccount->balance = (float) $fromAccount->balance + $transaction->amount;
                $fromAccount->save();

                AccountEntry::create([
                    'account_id' => $fromAccount->id,
                    'transaction_id' => $transaction->id,
                    'debit' => $transaction->amount,        // flipped: was 0.00
                    'credit' => 0.00,                       // flipped: was $transaction->amount
                    'balance_after' => $fromAccount->balance,
                ]);

                $toAccount->balance = (float) $toAccount->balance - $transaction->amount;
                $toAccount->save();

                AccountEntry::create([
                    'account_id' => $toAccount->id,
                    'transaction_id' => $transaction->id,
                    'debit' => 0.00,                        // flipped: was $transaction->amount
                    'credit' => $transaction->amount,       // flipped: was 0.00
                    'balance_after' => $toAccount->balance,
                ]);
            }

            $transaction->notes = $reversalNote;
            $transaction->save();

            Log::info('Transaction reversed', [
                'transaction_id' => $transaction->id,
                'type' => $transaction->type,
                'amount' => $transaction->amount,
                'user_id' => Auth::id(),
            ]);

            return $transaction;
        }));
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function recordTransfer(array $data): Transfer
    {
        return LedgerBalanceMutationGuard::run(fn () => DB::transaction(function () use ($data) {
            $fromId = (int) $data['from_account_id'];
            $toId = (int) $data['to_account_id'];
            $debitAmount = (float) $data['amount'];

            if ($debitAmount <= 0) {
                throw new \InvalidArgumentException('Transfer amount must be greater than zero.');
            }

            if ($fromId === $toId) {
                throw new \InvalidArgumentException('from_account_id and to_account_id must differ.');
            }

            $reuseId = isset($data['reuse_transfer_id']) ? (int) $data['reuse_transfer_id'] : 0;
            $existingTransfer = null;
            if ($reuseId > 0) {
                $existingTransfer = Transfer::query()->lockForUpdate()->findOrFail($reuseId);
                if ($existingTransfer->transaction_id !== null && (int) $existingTransfer->transaction_id > 0) {
                    throw new \RuntimeException('التسجيل المحاسبي لهذا التحويل موجود مسبقًا.');
                }
                if ((int) $existingTransfer->from_account_id !== $fromId || (int) $existingTransfer->to_account_id !== $toId) {
                    throw new \InvalidArgumentException('سجل التحويل المعلق لا يطابق حسابي المصدر والجهة.');
                }
                if (abs((float) $existingTransfer->amount - $debitAmount) > 0.02) {
                    throw new \InvalidArgumentException('مبلغ التحويل لا يطابق طلب الموافقة.');
                }
            }

            if ($fromId < $toId) {
                $fromAccount = Account::where('id', $fromId)->lockForUpdate()->firstOrFail();
                $toAccount = Account::where('id', $toId)->lockForUpdate()->firstOrFail();
            } else {
                $toAccount = Account::where('id', $toId)->lockForUpdate()->firstOrFail();
                $fromAccount = Account::where('id', $fromId)->lockForUpdate()->firstOrFail();
            }

            $fromCurrency = strtoupper((string) $fromAccount->currency);
            $toCurrency = strtoupper((string) $toAccount->currency);
            $sameCurrency = $fromCurrency === $toCurrency;
            $creditAmount = $sameCurrency
                ? $debitAmount
                : (float) ((isset($data['converted_amount']) && is_numeric($data['converted_amount']) && (float) $data['converted_amount'] > 0)
                    ? $data['converted_amount']
                    : (($existingTransfer !== null && $existingTransfer->converted_amount !== null)
                        ? (float) $existingTransfer->converted_amount
                        : 0.0));

            if (! $sameCurrency && $creditAmount <= 0) {
                throw new \InvalidArgumentException(
                    'عند اختلاف عملة الحسابين يجب تحديد converted_amount (المبلغ المضاف لحساب الاستلام بعملته، مثل الدينار المُستلم في خزنة الدينار).'
                );
            }

            if ($sameCurrency && isset($data['converted_amount']) && abs((float) $data['converted_amount'] - $debitAmount) > 0.00001) {
                throw new \InvalidArgumentException('في نفس العملة يجب أن يطابق converted_amount قيمة amount أو يُترك فارغاً.');
            }

            if ((float) $fromAccount->balance < $debitAmount) {
                throw new \Exception('Insufficient balance in account: '.$fromAccount->name);
            }

            if ($sameCurrency) {
                $exchangeRate = 1.0;
            } else {
                $exchangeRate = isset($data['exchange_rate']) && (float) $data['exchange_rate'] > 0
                    ? (float) $data['exchange_rate']
                    : (($existingTransfer !== null && $existingTransfer->exchange_rate !== null && (float) $existingTransfer->exchange_rate > 0)
                        ? (float) $existingTransfer->exchange_rate
                        : round($debitAmount / $creditAmount, 6));
            }

            $createdBy = $data['created_by'] ?? ($existingTransfer?->created_by) ?? Auth::id();
            if ($createdBy === null) {
                throw new \RuntimeException('User context is required to record a transfer.');
            }

            $module = TransactionModule::tryFrom((string) ($data['module'] ?? TransactionModule::General->value))
                ?? TransactionModule::General;

            $transaction = $this->persistTransaction([
                'type' => $data['type'] ?? TransactionType::Transfer->value,
                'amount' => $debitAmount,
                'module' => $module->value,
                'from_account_id' => $fromId,
                'to_account_id' => $toId,
                'created_by' => (int) $createdBy,
                'notes' => $data['notes'] ?? null,
                'attachment_path' => $data['attachment_path'] ?? null,
            ]);

            // Finding #1 fix: ledger entry directions flipped to standard double-entry (see recordJournalTransfer).
            $fromAccount->balance = (float) $fromAccount->balance - $debitAmount;
            $fromAccount->save();

            AccountEntry::create([
                'account_id' => $fromAccount->id,
                'transaction_id' => $transaction->id,
                'debit' => 0.00,                  // flipped: was $debitAmount
                'credit' => $debitAmount,          // flipped: was 0.00
                'balance_after' => $fromAccount->balance,
            ]);

            $toAccount->balance = (float) $toAccount->balance + $creditAmount;
            $toAccount->save();

            AccountEntry::create([
                'account_id' => $toAccount->id,
                'transaction_id' => $transaction->id,
                'debit' => $creditAmount,          // flipped: was 0.00
                'credit' => 0.00,                  // flipped: was $creditAmount
                'balance_after' => $toAccount->balance,
            ]);

            if ($existingTransfer !== null) {
                $existingTransfer->fill([
                    'transaction_id' => $transaction->id,
                    'approval_workflow_id' => $data['approval_workflow_id'] ?? $existingTransfer->approval_workflow_id,
                    'notes' => $data['notes'] ?? $existingTransfer->notes,
                    'exchange_rate' => $exchangeRate,
                    'converted_amount' => $creditAmount,
                    'from_currency' => $fromCurrency,
                    'to_currency' => $toCurrency,
                ]);
                $existingTransfer->save();

                Log::info('Transfer recorded (reuse pending approval row)', [
                    'transaction_id' => $transaction->id,
                    'transfer_id' => $existingTransfer->id,
                    'from_account_id' => $fromAccount->id,
                    'to_account_id' => $toAccount->id,
                    'amount' => $debitAmount,
                    'converted_amount' => $creditAmount,
                    'from_currency' => $fromCurrency,
                    'to_currency' => $toCurrency,
                    'user_id' => (int) $createdBy,
                ]);

                return $existingTransfer->fresh();
            }

            $transfer = Transfer::create([
                'from_account_id' => $fromId,
                'to_account_id' => $toId,
                'amount' => $debitAmount,
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'exchange_rate' => $exchangeRate,
                'converted_amount' => $creditAmount,
                'transaction_id' => $transaction->id,
                'approval_workflow_id' => $data['approval_workflow_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => (int) $createdBy,
            ]);

            Log::info('Transfer recorded', [
                'transaction_id' => $transaction->id,
                'transfer_id' => $transfer->id,
                'from_account_id' => $fromAccount->id,
                'to_account_id' => $toAccount->id,
                'amount' => $debitAmount,
                'converted_amount' => $creditAmount,
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'user_id' => (int) $createdBy,
            ]);

            return $transfer;
        }));
    }

    /**
     * Balanced movement between two GL accounts (debit from, credit to).
     * Creates one Transaction (type transfer) and two AccountEntry rows.
     *
     * @param  array{
     *     amount: float,
     *     from_account_id: int,
     *     to_account_id: int,
     *     module: string,
     *     related_type?: class-string|null,
     *     related_id?: int|null,
     *     notes?: string|null,
     *     created_by?: int|null,
     *     allow_from_negative?: bool
     * }  $data
     */
    public function recordJournalTransfer(array $data): Transaction
    {
        return LedgerBalanceMutationGuard::run(fn () => DB::transaction(function () use ($data) {
            $amount = (float) $data['amount'];
            $fromId = (int) $data['from_account_id'];
            $toId = (int) $data['to_account_id'];
            $allowFromNegative = (bool) ($data['allow_from_negative'] ?? false);

            if ($amount <= 0) {
                throw new \InvalidArgumentException('Journal transfer amount must be positive.');
            }

            if ($fromId === $toId) {
                throw new \InvalidArgumentException('from_account_id and to_account_id must differ.');
            }

            $transaction = $this->persistTransaction([
                'type' => TransactionType::Transfer->value,
                'amount' => $amount,
                'module' => $data['module'],
                'related_type' => $data['related_type'] ?? null,
                'related_id' => $data['related_id'] ?? null,
                'from_account_id' => $fromId,
                'to_account_id' => $toId,
                'created_by' => $data['created_by'] ?? Auth::id() ?? 1,
                'notes' => $data['notes'] ?? null,
            ]);

            /** @var Collection<int, Account> $accounts */
            $accounts = Account::query()
                ->whereIn('id', [$fromId, $toId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $fromAccount = $accounts->get($fromId);
            $toAccount = $accounts->get($toId);

            if (! $fromAccount || ! $toAccount) {
                throw new \Exception('One or both accounts were not found.');
            }

            $typeStr = $fromAccount->type instanceof AccountType
                ? $fromAccount->type->value
                : (string) $fromAccount->type;
            $isFund = in_array($typeStr, AccountModuleContract::LIQUIDITY_TYPES, true);

            if ($isFund) {
                $isCustomerOrSupplier = Customer::where('account_id', $fromAccount->id)->exists()
                    || Supplier::where('account_id', $fromAccount->id)->exists();
                if ($isCustomerOrSupplier) {
                    $isFund = false;
                }
            }

            if (! $allowFromNegative && $isFund && (float) $fromAccount->balance < $amount) {
                throw ValidationException::withMessages([
                    'amount' => 'رصيد الحساب غير كافٍ: '.$fromAccount->name,
                ]);
            }

            $fromCurrency = strtoupper((string) $fromAccount->currency);
            $toCurrency = strtoupper((string) $toAccount->currency);
            $sameCurrency = $fromCurrency === $toCurrency;

            $toAmount = $sameCurrency
                ? $amount
                : (float) ($data['converted_amount'] ?? 0.0);

            if (! $sameCurrency && $toAmount <= 0) {
                $rate = (float) ($data['exchange_rate'] ?? 1.0);
                if ($rate > 0) {
                    if ($fromCurrency === 'EGP') {
                        $toAmount = $amount / $rate;
                    } else {
                        $toAmount = $amount * $rate;
                    }
                } else {
                    $toAmount = $amount;
                }
            }

            // Finding #1 fix: ledger entry directions flipped to standard double-entry.
            //   - from_account (source) gets CREDIT entry (was DEBIT).
            //     For ASSET (cashbox): credit decreases asset → balance -= amount ✓
            //     For LIABILITY (supplier): credit increases payable → balance -= amount ✓
            //   - to_account (destination) gets DEBIT entry (was CREDIT).
            //     For ASSET (cashbox/customer AR): debit increases asset → balance += amount ✓
            //   This makes Account::balance = SUM(debit) - SUM(credit) match the
            //   intuitive convention: customer AR positive = they owe us,
            //   supplier AP negative = we owe them.
            $fromAccount->balance = (float) $fromAccount->balance - $amount;
            $fromAccount->save();

            AccountEntry::create([
                'account_id' => $fromAccount->id,
                'transaction_id' => $transaction->id,
                'debit' => 0.00,                  // flipped: was $amount
                'credit' => $amount,              // flipped: was 0.00
                'balance_after' => $fromAccount->balance,
            ]);

            $toAccount->balance = (float) $toAccount->balance + $toAmount;
            $toAccount->save();

            AccountEntry::create([
                'account_id' => $toAccount->id,
                'transaction_id' => $transaction->id,
                'debit' => $toAmount,              // flipped: was 0.00
                'credit' => 0.00,                  // flipped: was $toAmount
                'balance_after' => $toAccount->balance,
            ]);

            Log::info('Journal transfer recorded', [
                'transaction_id' => $transaction->id,
                'from_account_id' => $fromId,
                'to_account_id' => $toId,
                'amount' => $amount,
                'user_id' => $data['created_by'] ?? Auth::id(),
            ]);

            return $transaction;
        }));
    }

    /**
     * Undo all ledger lines for a transaction (multi-leg safe) and remove entries.
     */
    public function voidTransactionJournal(Transaction $transaction): void
    {
        LedgerBalanceMutationGuard::run(function () use ($transaction): void {
            DB::transaction(function () use ($transaction): void {
                $entries = AccountEntry::query()
                    ->where('transaction_id', $transaction->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($entries as $entry) {
                    $account = Account::query()->lockForUpdate()->find($entry->account_id);
                    if (! $account) {
                        continue;
                    }

                    $delta = round((float) $entry->credit - (float) $entry->debit, 2);
                    $account->balance = round((float) $account->balance - $delta, 2);
                    $account->save();
                }

                AccountEntry::query()->where('transaction_id', $transaction->id)->delete();
            });
        });
    }
}
