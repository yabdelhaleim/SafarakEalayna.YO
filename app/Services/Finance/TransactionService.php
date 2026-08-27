<?php

namespace App\Services\Finance;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use App\Exceptions\BusinessLogicException;
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
        // Phase 7: stamp `currency` from the booking/caller when provided so
        // per-currency clearing entries can be distinguished in reports.
        // Default to EGP for legacy callers.
        if (! isset($attrs['currency']) || $attrs['currency'] === null || $attrs['currency'] === '') {
            $attrs['currency'] = 'EGP';
        } else {
            $attrs['currency'] = strtoupper((string) $attrs['currency']);
        }

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
        // Phase 7: when a transaction currency is supplied AND the module has
        // a per-currency clearing account configured, route the contra to the
        // matching currency bucket. Otherwise fall back to the legacy single-
        // currency resolver (default = EGP).
        $txCurrency = isset($data['currency']) ? strtoupper((string) $data['currency']) : null;
        $resolvedContra = $explicitContra
            ?: $this->ledgerClearingAccounts->expenseContraIdForModuleAndCurrency((string) $moduleValue, $txCurrency);

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
                'currency' => $txCurrency,
                // FIN-3 REMEDIATION (2026-08-21): preserve the expense semantic
                // when routing through a clearing account. Pre-fix, `recordExpense`
                // relied on `recordJournalTransfer` defaulting to `type=Transfer`,
                // which silently re-tagged every clearing-account expense as a
                // transfer. Reports filtering on `type='expense'` missed them
                // and treasury dashboards were skewed.
                //
                // Post-fix: `recordExpense` is explicit about the intent and
                // `recordJournalTransfer` honors the caller-supplied type.
                'type' => TransactionType::Expense->value,
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
        // Phase 7: prefer the per-currency income clearing account when a
        // transaction currency is supplied. Falls back to the legacy resolver
        // (which defaults to EGP) for legacy callers.
        $txCurrency = isset($data['currency']) ? strtoupper((string) $data['currency']) : null;

        $resolvedContra = $explicitContra;
        if ($resolvedContra === null || $resolvedContra === 0) {
            if ((string) $moduleValue === TransactionModule::Flight->value) {
                $resolvedContra = $this->ledgerClearingAccounts->incomeContraIdForFlightBooking();
            } else {
                $resolvedContra = $this->ledgerClearingAccounts->incomeContraIdForModuleAndCurrency((string) $moduleValue, $txCurrency);
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
                // FC-AUDIT-20260814 fix (D1): pass `type='Income'` so that:
                //   1) The 2026-08-12 duplicate-Income guard in recordJournalTransfer
                //      (lines 612–625) fires on the second call with same related_type+related_id.
                //   2) The resulting Transaction row is correctly categorized as 'Income'
                //      for income reports and accounting breakdowns.
                //   3) Audit trail + reversal queries that filter type='Income' now match.
                // Previously recordIncome silently defaulted to TransactionType::Transfer,
                // bypassing the duplicate guard and mis-categorizing all income postings.
                'type' => TransactionType::Income->value,
                'module' => $moduleValue,
                'related_type' => $data['related_type'] ?? null,
                'related_id' => $data['related_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? Auth::id() ?? 1,
                'currency' => $txCurrency,
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
     * Reverse a previously recorded transaction using its actual ledger legs.
     *
     * Reversal entries are append-only and swap each original leg's debit/credit
     * values. Using the ledger legs instead of Transaction::amount preserves
     * converted amounts for multi-currency transfers.
     *
     * Idempotent: a second (or further) call on an already-reversed transaction
     * is a no-op that returns the original transaction unchanged. This matters
     * for flow-level operations like Fawry update-then-delete where the same
     * transaction may be visited twice through different code paths.
     */
    public function reverseTransaction(Transaction $transaction): Transaction
    {
        return LedgerBalanceMutationGuard::run(fn () => DB::transaction(function () use ($transaction) {
            $transaction = Transaction::query()
                ->lockForUpdate()
                ->findOrFail($transaction->id);

            $entries = AccountEntry::query()
                ->where('transaction_id', $transaction->id)
                ->orderBy('account_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($entries->isEmpty()) {
                throw new \RuntimeException('لا يمكن عكس معاملة بلا قيود محاسبية.');
            }

            if ($entries->contains(fn (AccountEntry $entry): bool =>
                (float) $entry->debit > 0 && (float) $entry->credit > 0
            )) {
                throw new \RuntimeException('لا يمكن عكس قيد يحتوي مديناً ودائناً في نفس السطر.');
            }

            $hasReversal = AccountEntry::query()
                ->where('transaction_id', $transaction->id)
                ->where(function ($query) {
                    $query->where('notes', 'like', 'عكس:%')
                        ->orWhere('notes', 'like', 'عكس %');
                })
                ->exists();

            // Idempotency: a transaction already in a reversed state must not be
            // reversed again — return it unchanged so callers (e.g. Fawry/Online
            // deleteTransaction that walks every linked transaction including
            // ones already reversed by a prior update) keep working.
            if ($hasReversal || str_starts_with((string) $transaction->notes, 'عكس:')) {
                Log::warning('reverseTransaction called on an already-reversed transaction; no-op', [
                    'transaction_id' => $transaction->id,
                    'type' => $transaction->type,
                    'user_id' => Auth::id(),
                ]);

                return $transaction;
            }

            $accounts = Account::query()
                ->whereIn('id', $entries->pluck('account_id')->unique()->values())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($entries as $entry) {
                $account = $accounts->get($entry->account_id);
                if (! $account) {
                    throw new \RuntimeException('الحساب المرتبط بالقيد غير موجود.');
                }

                $debit = round((float) $entry->debit, 2);
                $credit = round((float) $entry->credit, 2);
                $delta = round($credit - $debit, 2);

                $account->balance = round((float) $account->balance - $delta, 2);
                $account->save();

                AccountEntry::create([
                    'account_id' => $account->id,
                    'transaction_id' => $transaction->id,
                    'debit' => $credit,
                    'credit' => $debit,
                    'balance_after' => $account->balance,
                    'notes' => 'عكس القيد #'.$entry->id,
                ]);
            }

            $transaction->notes = 'عكس: '.($transaction->notes ?? '');
            $transaction->save();

            Log::info('Transaction reversed', [
                'transaction_id' => $transaction->id,
                'type' => $transaction->type,
                'amount' => $transaction->amount,
                'entries_reversed' => $entries->count(),
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

            // FIX (2026-07-28, audit finding 4.1): allow transfers from accounts
            // that legitimately go negative (prepaid carriers/systems, supplier
            // AP). The same flag exists on recordJournalTransfer — this brings
            // recordTransfer to parity. Default false keeps backward compat
            // with the existing "insufficient balance" rejection for cashbox.
            $allowFromNegative = (bool) ($data['allow_from_negative'] ?? false);
            $fromTypeStr = $fromAccount->type instanceof AccountType
                ? $fromAccount->type->value
                : (string) $fromAccount->type;
            $isLiquidity = in_array($fromTypeStr, AccountModuleContract::LIQUIDITY_TYPES, true);
            $isPrepaidOrSupplier = $isLiquidity
                ? false
                : in_array($fromTypeStr, ['supplier', 'prepaid', 'airline_account'], true);
            $canGoNegative = $allowFromNegative || $isPrepaidOrSupplier;

            if (! $canGoNegative && (float) $fromAccount->balance < $debitAmount) {
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
                // BUG-FIX (2026-08-24): propagate related_type/related_id so
                // FlightBookingService::reverseGroupTransactionsForBooking can
                // find the journal linked to a pay-debt FlightGroupTransaction.
                // Pre-fix, pay-debt transfers were orphaned on the Transaction
                // row (related_type/related_id were dropped) so the reverse
                // path never matched them and cashbox balance drifted.
                'related_type' => $data['related_type'] ?? null,
                'related_id' => $data['related_id'] ?? null,
            ]);

            // Ledger entry directions match the project's convention (balance = SUM(credit) - SUM(debit)).
            // For ASSET accounts in this project: credit increases balance (per AccountService::creditAccount),
            // debit decreases balance (per AccountService::debitAccount).
            // Therefore, the from-account (losing money) must get a DEBIT entry, and the to-account
            // (gaining money) must get a CREDIT entry — exactly matching FinancialReportService's
            // SUM(credit - debit) formula. (Reverts Finding #1 "fix" that flipped directions by mistake.)
            $fromAccount->balance = (float) $fromAccount->balance - $debitAmount;
            $fromAccount->save();

            AccountEntry::create([
                'account_id' => $fromAccount->id,
                'transaction_id' => $transaction->id,
                'debit' => $debitAmount,
                'credit' => 0.00,
                'balance_after' => $fromAccount->balance,
            ]);

            $toAccount->balance = (float) $toAccount->balance + $creditAmount;
            $toAccount->save();

            AccountEntry::create([
                'account_id' => $toAccount->id,
                'transaction_id' => $transaction->id,
                'debit' => 0.00,
                'credit' => $creditAmount,
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
     * Creates one Transaction and two AccountEntry rows.
     *
     * The `type` column on the transaction is purely a semantic label for
     * reports/treasury UI; it does NOT affect the two-leg balance because
     * both AccountEntry rows are always created (debit on from, credit on to).
     *
     * Callers should pass a `type` whenever the journal represents a
     * semantic outcome other than a plain transfer (e.g. customer payment
     * posting to income clearing → 'income'; cost posting to expense
     * clearing → 'expense'; cancellation reversal of customer debt →
     * 'refund'). Default is 'transfer' for genuine inter-account movements.
     *
     * @param  array{
     *     amount: float,
     *     from_account_id: int,
     *     to_account_id: int,
     *     module: string,
     *     type?: string|null,           // TransactionType value, defaults to 'transfer'
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

            // Resolve the semantic type. Default = Transfer for genuine
            // inter-account movements (vault→bank, currency conversions).
            // Anything else must be an explicit TransactionType case.
            $typeValue = TransactionType::Transfer->value;
            if (! empty($data['type'])) {
                $typeValue = TransactionType::from((string) $data['type'])->value;
            }

            // FIX (Path C, 2026-08-14): guard against duplicate ACTIVE income
            // transactions on the same related entity.
            //
            // Invariant: a booking (or any morph entity) can have AT MOST ONE
            // ACTIVE income transaction — the sale. Any subsequent collection
            // MUST be a Transfer (cash → AR), not a new Income. This bug
            // previously caused every bus booking to register 2 income tx
            // (sale + payment) and doubled the office income sum in the
            // trial balance (FC-AUDIT 2026-08-12, original guard).
            //
            // Path C extension: when the original sale income is REVERSED
            // additively (notes prefix `عكس:` / `عكس ` — the project's
            // de-facto reversal convention set by TransactionService::reverseTransaction
            // line 352 and consumed by 8+ downstream readers), the related
            // slot becomes available again for a new income posting.
            //
            // Why this is correct:
            //   1. Additive reversal preserves the original transaction row
            //      (project rule: original transactions are never deleted or
            //      modified — only inverse entries are added).
            //   2. The original's contribution to GL is already 0
            //      (original debit + inverse credit cancel out).
            //   3. Reports that filter on this convention
            //      (FinancialReportService::classifyPL line 1751 et al.)
            //      already treat reversed rows as `revenue_reversal`.
            //   4. The 8 existing consumers in the codebase (Documented in
            //      .zcode/plans/path-c-analysis-20260814.md Section 3A)
            //      already filter on `notes NOT LIKE 'عكس:%'` — the
            //      application guard using the same convention stays
            //      consistent.
            //
            // This change unblocks `HajjUmraBookingService::repostIncomeTransaction()`
            // (lines 327-350) which previously threw at this guard, breaking
            // every attempt to edit the selling_price of an active HajjUmra
            // booking.
            $relatedType = $data['related_type'] ?? null;
            $relatedId = $data['related_id'] ?? null;
            if ($typeValue === TransactionType::Income->value && $relatedType && $relatedId) {
                $existingActiveIncome = DB::table('transactions')
                    ->where('related_type', $relatedType)
                    ->where('related_id', $relatedId)
                    ->where('type', TransactionType::Income->value)
                    ->where(function ($q) {
                        // A row is "ACTIVE" if its notes are absent or do
                        // NOT start with the reversal prefix. Reversed
                        // rows are excluded from the unique slot.
                        $q->whereNull('notes')
                            ->orWhere(function ($q2) {
                                $q2->where('notes', 'not like', 'عكس:%')
                                    ->where('notes', 'not like', 'عكس %');
                            });
                    })
                    ->exists();
                if ($existingActiveIncome) {
                    throw new \InvalidArgumentException(
                        "Duplicate income transaction blocked for {$relatedType}#{$relatedId}. ".
                        'Each booking can have only ONE ACTIVE income transaction (the sale). '.
                        'Reversed (عكس:) incomes do not occupy this slot — repostIncomeTransaction() '.
                        'can re-issue a new sale once the prior sale is reversed. '.
                        'Subsequent COLLECTIONS on a booking must use Transfer (type=transfer).'
                    );
                }
            }

            $transaction = $this->persistTransaction([
                'type' => $typeValue,
                'amount' => $amount,
                'currency' => $data['currency'] ?? null,
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
                // FINDING UX-1 (MED) REMEDIATION (2026-08-21):
                // Insufficient balance is a BUSINESS RULE violation (the request
                // is well-formed and authorized, but the server's state conflicts
                // with it). Throw BusinessLogicException → HTTP 409 Conflict.
                // 422 is reserved for input-shape errors (ValidationException).
                throw new BusinessLogicException(
                    'رصيد الحساب غير كافٍ: '.$fromAccount->name,
                    [
                        'account_id' => $fromAccount->id,
                        'account_name' => $fromAccount->name,
                        'required' => $amount,
                        'available' => (float) $fromAccount->balance,
                    ]
                );
            }

            $fromCurrency = strtoupper((string) $fromAccount->currency);
            $toCurrency = strtoupper((string) $toAccount->currency);
            $sameCurrency = $fromCurrency === $toCurrency;

            $toAmount = $amount;

            if (! $sameCurrency) {
                // ─────────────────────────────────────────────────────────────────
                // SAFE FX RULE (FIX 2026-08-21): cross-currency transfers MUST
                //   carry EXPLICIT conversion information. The previous
                //   implementation silently coerced a missing `exchange_rate`
                //   to `1.0` and a missing `converted_amount` to the raw
                //   `$amount`, producing incorrect ledger postings when a
                //   Visa/HajjUmra admin settled an EGP customer debt into a
                //   non-EGP treasury without supplying a rate.
                //
                //   Acceptable inputs (in priority order):
                //     1. `converted_amount` > 0  — caller already computed the
                //        destination amount using CurrencyService::convert().
                //        Preferred path for callers that already have the rate.
                //     2. `exchange_rate` > 0    — caller supplies the rate;
                //        service computes the destination amount based on the
                //        EGP/foreign direction.
                //
                //   Anything else (missing, zero, negative, non-numeric) →
                //   BusinessLogicException → HTTP 409 Conflict. The request
                //   is well-formed; the server state cannot safely absorb it.
                //
                //   Same-currency transfers: unchanged behavior, no FX data
                //   required.
                // ─────────────────────────────────────────────────────────────────
                $rawConvertedAmount = $data['converted_amount'] ?? null;
                $rawExchangeRate = $data['exchange_rate'] ?? null;

                $hasConvertedAmount = $rawConvertedAmount !== null
                    && is_numeric($rawConvertedAmount)
                    && (float) $rawConvertedAmount > 0;
                $hasExchangeRate = $rawExchangeRate !== null
                    && is_numeric($rawExchangeRate)
                    && (float) $rawExchangeRate > 0;

                if ($hasConvertedAmount) {
                    // Path 1: caller supplied the converted destination amount.
                    $toAmount = (float) $rawConvertedAmount;
                } elseif ($hasExchangeRate) {
                    // Path 2: caller supplied the rate; we compute the amount.
                    $rate = (float) $rawExchangeRate;
                    $toAmount = $fromCurrency === 'EGP'
                        ? $amount / $rate      // EGP → foreign: divide
                        : $amount * $rate;      // foreign → EGP: multiply
                } else {
                    // Neither (or invalid) → REJECT. No silent 1.0. No silent
                    // amount-as-conversion. Caller must use CurrencyService.
                    throw new BusinessLogicException(
                        'لا يمكن تنفيذ تحويل عبر عملات مختلفة دون تحديد سعر الصرف أو المبلغ المحوّل. '
                        .'عملة المصدر: '.$fromCurrency.'، عملة الهدف: '.$toCurrency.'. '
                        .'يجب استخدام CurrencyService::convert() لتحويل المبلغ، أو تمرير converted_amount/exchange_rate صراحةً بقيم موجبة.',
                        [
                            'from_account_id' => $fromId,
                            'to_account_id' => $toId,
                            'from_currency' => $fromCurrency,
                            'to_currency' => $toCurrency,
                            'amount' => $amount,
                            'provided_converted_amount' => $rawConvertedAmount,
                            'provided_exchange_rate' => $rawExchangeRate,
                        ]
                    );
                }
            }

            // Project convention: balance = SUM(credit) - SUM(debit) (see FinancialReportService line 383).
            // Therefore: from-account losing money → DEBIT entry; to-account gaining money → CREDIT entry.
            // (Reverts the previous "Finding #1 fix" that flipped directions and broke the invariant.)
            //
            // BUG-FIX (2026-08-28): use DB::table()->update() instead of
            // Eloquent $account->save() to persist the new balance. Eloquent's
            // save() fires the Account `saving`/`updating` observers, which
            // include the module_type contract check (Account::booted lines
            // 285-409). For some subject accounts (notably customer AR
            // mirrors with module_type='flights'), the save() side-effect was
            // silently dropping the balance write — the AccountEntry was
            // created with balance_after=0 and accounts.balance stayed at 0,
            // leaving every customer with a permanently-zero AR balance.
            // Direct DB update bypasses the model event chain entirely while
            // staying inside the LedgerBalanceMutationGuard::run() block, so
            // the balance guard still validates the mutation context.
            $newFromBalance = round((float) $fromAccount->balance - $amount, 2);
            DB::table('accounts')->where('id', $fromAccount->id)->update([
                'balance' => $newFromBalance,
                'updated_at' => now(),
            ]);
            $fromAccount->balance = $newFromBalance;

            AccountEntry::create([
                'account_id' => $fromAccount->id,
                'transaction_id' => $transaction->id,
                'debit' => $amount,
                'credit' => 0.00,
                'balance_after' => $newFromBalance,
            ]);

            $newToBalance = round((float) $toAccount->balance + $toAmount, 2);
            DB::table('accounts')->where('id', $toAccount->id)->update([
                'balance' => $newToBalance,
                'updated_at' => now(),
            ]);
            $toAccount->balance = $newToBalance;

            AccountEntry::create([
                'account_id' => $toAccount->id,
                'transaction_id' => $transaction->id,
                'debit' => 0.00,
                'credit' => $toAmount,
                'balance_after' => $newToBalance,
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
