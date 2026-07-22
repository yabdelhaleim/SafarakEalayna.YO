<?php

namespace App\Services\Wallet;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Enums\WalletTransactionType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\Wallet\WalletTransaction;
use App\Models\Wallet\WalletType;
use App\Services\Finance\TransactionService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WalletTransactionService
{
    public function __construct(
        protected TransactionService $transactionService
    ) {}

    public function getAllTransactions(array $filters): LengthAwarePaginator
    {
        $query = WalletTransaction::with([
            'walletType',
            'customer',
            'walletAccount',
            'cashAccount',
            'employee',
            'createdBy',
        ]);

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['wallet_type_id'])) {
            $query->where('wallet_type_id', $filters['wallet_type_id']);
        }

        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if (! empty($filters['employee_id'])) {
            $query->where('employee_id', $filters['employee_id']);
        }

        if (! empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('customer_name', 'like', '%'.$filters['search'].'%')
                    ->orWhere('wallet_number', 'like', '%'.$filters['search'].'%');
            });
        }

        if (! empty($filters['from_date'])) {
            $query->where('created_at', '>=', $filters['from_date'].' 00:00:00');
        }

        if (! empty($filters['to_date'])) {
            $query->where('created_at', '<=', $filters['to_date'].' 23:59:59');
        }

        $perPage = min($filters['per_page'] ?? 20, 100);

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    public function createTransaction(array $data): WalletTransaction
    {
        try {
            return DB::transaction(function () use ($data) {
                $rawType = $data['type'];
                $type = $rawType instanceof WalletTransactionType
                    ? $rawType
                    : WalletTransactionType::from((string) $rawType);
                $amount = (float) $data['amount'];
                $fee = (float) ($data['service_fee'] ?? 0);

                // total_amount: للИслаرسال العميل يدفع amount+fee، للاستقبال يأخذ amount-fee
                $totalAmount = match ($type) {
                    WalletTransactionType::Send => $amount + $fee,
                    WalletTransactionType::Receive => $amount - $fee,
                };
                // customer_name من العميل المرتبط أو من النص الحر
                $customerName = $data['customer_name'] ?? '';
                if (! empty($data['customer_id'])) {
                    $customer = Customer::find($data['customer_id']);
                    if ($customer) {
                        $customerName = $customer->full_name ?? $customer->name ?? $customerName;
                    }
                }
                $walletTypeName = WalletType::find($data['wallet_type_id'])?->name ?? '';
                $createdBy = Auth::id() ?? ($data['created_by'] ?? 1);
                $amountPaid = isset($data['amount_paid']) ? (float) $data['amount_paid'] : $totalAmount;
                $record = WalletTransaction::create([
                    'wallet_type_id' => $data['wallet_type_id'],
                    'customer_id' => $data['customer_id'] ?? null,
                    'customer_name' => $customerName,
                    'wallet_number' => $data['wallet_number'],
                    'type' => $type->value,
                    'amount' => $amount,
                    'service_fee' => $fee,
                    'total_amount' => $totalAmount,
                    'amount_paid' => $amountPaid,
                    'wallet_account_id' => $data['wallet_account_id'],
                    'cash_account_id' => $data['cash_account_id'],
                    'employee_id' => $data['employee_id'] ?? null,
                    'created_by' => $createdBy,
                    'notes' => $data['notes'] ?? null,
                ]);
                // Wrap in try/catch(Throwable) to surface inner exceptions clearly.
                // Outer try only catches \Exception, but accountForSend/accountForReceive
                // may throw \TypeError or \Error which silently bypass the catch.
                try {
                    [$incomeTransaction, $expenseTransaction] = match ($type) {
                        WalletTransactionType::Send => $this->accountForSend(
                            $record, $amount, $fee, $walletTypeName, $customerName, $createdBy
                        ),
                        WalletTransactionType::Receive => $this->accountForReceive(
                            $record, $amount, $fee, $walletTypeName, $customerName, $createdBy
                        ),
                    };
                } catch (\Throwable $inner) {
                    throw $inner;
                }
                $record->update([
                    'income_transaction_id' => $incomeTransaction->id,
                    'expense_transaction_id' => $expenseTransaction->id,
                ]);
                Log::info('WalletTransaction created', [
                    'id' => $record->id,
                    'type' => $type->value,
                    'amount' => $amount,
                    'service_fee' => $fee,
                    'customer_name' => $customerName,
                    'created_by' => $createdBy,
                ]);
                return $record->fresh([
                    'walletType', 'customer', 'walletAccount', 'cashAccount',
                    'employee', 'createdBy', 'incomeTransaction', 'expenseTransaction',
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('WalletTransactionService::createTransaction failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'input' => $data,
            ]);
            throw $e;
        }
    }

    public function updateTransaction(WalletTransaction $transaction, array $data): WalletTransaction
    {
        try {
            return DB::transaction(function () use ($transaction, $data) {
                // Detect ACTUAL changes (vs same value) — used to gate the
                // ledger repost so we don't waste DB writes on no-op edits.
                // Mirrors OnlineTransactionService Phase 9 / HajjUmra Phase 8.
                $amountChanged = array_key_exists('amount', $data)
                    && (float) $data['amount'] !== (float) $transaction->amount;
                $serviceFeeChanged = array_key_exists('service_fee', $data)
                    && (float) $data['service_fee'] !== (float) $transaction->service_fee;
                $amountPaidChanged = array_key_exists('amount_paid', $data)
                    && (float) $data['amount_paid'] !== (float) $transaction->amount_paid;
                $walletAccountChanged = array_key_exists('wallet_account_id', $data)
                    && (int) $data['wallet_account_id'] !== (int) $transaction->wallet_account_id;
                $cashAccountChanged = array_key_exists('cash_account_id', $data)
                    && (int) $data['cash_account_id'] !== (int) $transaction->cash_account_id;

                $amountOrFeeChanged = $amountChanged || $serviceFeeChanged;
                $anyLedgerAffectingChange = $amountOrFeeChanged || $amountPaidChanged
                    || $walletAccountChanged || $cashAccountChanged;

                // Compute the new totals BEFORE the model update so we can
                // re-derive total_amount (Send: amount+fee, Receive: amount-fee).
                if ($amountOrFeeChanged) {
                    $newAmount = (float) ($data['amount'] ?? $transaction->amount);
                    $newFee = (float) ($data['service_fee'] ?? $transaction->service_fee);
                    $type = $transaction->type instanceof WalletTransactionType
                        ? $transaction->type
                        : WalletTransactionType::from((string) $transaction->type);
                    $data['total_amount'] = match ($type) {
                        WalletTransactionType::Send => $newAmount + $newFee,
                        WalletTransactionType::Receive => $newAmount - $newFee,
                    };
                }
                $transaction->update($data);
                // ACCOUNTING INTEGRITY (Phase 9 fix — same pattern as
                // OnlineTransactionService / HajjUmraBookingService /
                // VisaBookingService): when amount/service_fee/accounts/
                // amount_paid change, the OLD ledger entries must be
                // reversed (additive — never destructive) and NEW entries
                // posted with the corrected values.
                if ($anyLedgerAffectingChange) {
                    $newMain = $this->repostMainTransactions($transaction);
                    if ($newMain !== null) {
                        [$newIncome, $newExpense] = $newMain;
                        $transaction->update([
                            'income_transaction_id' => $newIncome->id,
                            'expense_transaction_id' => $newExpense->id,
                        ]);
                    }
                    $this->repostSettlementTransaction($transaction);
                }
                return $transaction->fresh([
                    'walletType', 'customer', 'walletAccount', 'cashAccount',
                    'employee', 'createdBy', 'incomeTransaction', 'expenseTransaction',
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('WalletTransactionService::updateTransaction failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'id' => $transaction->id,
                'input' => $data,
            ]);
            throw $e;
        }
    }

    /**
     * Repost the main income + expense transactions when amount, fee, or
     * the wallet/cash account IDs change.
     *
     * Mirrors `OnlineTransactionService::repostIncomeTransaction` /
     * `repostExpenseTransaction` (Phase 9): reverse the old transactions
     * (additive — never destructive), then post a fresh pair using the
     * new amounts and the new account IDs. The 3rd optional settlement
     * transaction (amount_paid) is handled separately by
     * `repostSettlementTransaction()`.
     *
     * IMPORTANT: only the MAIN pair is reposted here — calling
     * `accountForSend` / `accountForReceive` (which both post the
     * settlement too) would cause `repostSettlementTransaction` to
     * double-post a settlement. Instead, we use the lean helpers
     * `postMainSendPair` / `postMainReceivePair` and let the settlement
     * helper own the settlement lifecycle.
     *
     * Returns the new [income, expense] transaction pair, or null if the
     * source transaction is missing both ledger links.
     */
    protected function repostMainTransactions(WalletTransaction $transaction): ?array
    {
        if (! $transaction->income_transaction_id || ! $transaction->expense_transaction_id) {
            return null;
        }

        $oldIncome = Transaction::find($transaction->income_transaction_id);
        $oldExpense = Transaction::find($transaction->expense_transaction_id);
        if (! $oldIncome || ! $oldExpense) {
            return null;
        }

        $type = $transaction->type instanceof WalletTransactionType
            ? $transaction->type
            : WalletTransactionType::from((string) $transaction->type);

        $amount = (float) $transaction->amount;
        $fee = (float) $transaction->service_fee;

        $walletTypeName = $transaction->walletType?->name ?? '';
        $customerName = $transaction->customer_name ?: '—';
        $createdBy = $transaction->created_by ?? Auth::id() ?? 1;

        // Reverse old pair BEFORE posting new — guarantees the ledger has
        // a stable audit trail even if something fails mid-flight (the
        // outer DB::transaction wraps the whole thing so a failure rolls
        // back cleanly).
        $this->transactionService->reverseTransaction($oldIncome);
        $this->transactionService->reverseTransaction($oldExpense);

        // Recreate ONLY the main pair — settlement is the settlement
        // helper's responsibility.
        return match ($type) {
            WalletTransactionType::Send => $this->postMainSendPair(
                $transaction, $amount, $fee, $walletTypeName, $customerName, $createdBy
            ),
            WalletTransactionType::Receive => $this->postMainReceivePair(
                $transaction, $amount, $fee, $walletTypeName, $customerName, $createdBy
            ),
        };
    }

    /**
     * Repost the optional settlement transaction (3rd ledger row created
     * when customer_id is set AND amount_paid > 0).
     *
     * The settlement transaction is NOT stored on the model — it is
     * identified by the account pair:
     *   - Send:    cash_account_id (TO) ← customer_account (CONTRA)
     *   - Receive: cash_account_id (FROM) ← customer_account (CONTRA)
     *
     * Handles all 4 transitions (X→Y where X and Y can be 0):
     *   X>0, Y>0: reverse old + create new
     *   X>0, Y=0: reverse old only
     *   X=0, Y>0: create new only
     *   X=0, Y=0: no-op
     *
     * (Note: TransactionService internally stores ALL double-entry
     * transactions as `type=transfer` — the income/expense semantic
     * lives in the from/to direction, NOT in the `type` column. So we
     * must filter by the account pair, not by `type`.)
     */
    protected function repostSettlementTransaction(WalletTransaction $transaction): void
    {
        if (! $transaction->customer_id) {
            return; // settlement only exists for customer-based transactions
        }

        $customerAccount = $this->ensureCustomerAccount((int) $transaction->customer_id);
        $type = $transaction->type instanceof WalletTransactionType
            ? $transaction->type
            : WalletTransactionType::from((string) $transaction->type);

        // For both Send and Receive, the settlement involves the cash
        // account and the customer account. The pair uniquely identifies
        // the settlement row (the main income/expense use wallet_account
        // or customer_account alone, never cash+customer together).
        $settlement = Transaction::where('related_type', WalletTransaction::class)
            ->where('related_id', $transaction->id)
            ->where(function ($q) use ($transaction, $customerAccount) {
                $q->where(function ($sub) use ($transaction, $customerAccount) {
                    $sub->where('from_account_id', $transaction->cash_account_id)
                        ->where('to_account_id', $customerAccount->id);
                })->orWhere(function ($sub) use ($transaction, $customerAccount) {
                    $sub->where('from_account_id', $customerAccount->id)
                        ->where('to_account_id', $transaction->cash_account_id);
                });
            })
            ->first();

        if ($settlement) {
            $this->transactionService->reverseTransaction($settlement);
        }

        $amountPaid = (float) $transaction->amount_paid;
        if ($amountPaid < 0.001) {
            return;
        }

        $walletTypeName = $transaction->walletType?->name ?? '';
        $customerName = $transaction->customer_name ?: '—';
        $createdBy = $transaction->created_by ?? Auth::id() ?? 1;

        // Re-emit the same settlement entry that the original
        // accountForSend / accountForReceive would have posted.
        if ($type === WalletTransactionType::Send) {
            $this->transactionService->recordIncome([
                'amount' => $amountPaid,
                'to_account_id' => $transaction->cash_account_id,
                'contra_account_id' => $customerAccount->id,
                'module' => TransactionModule::Wallet->value,
                'related_type' => WalletTransaction::class,
                'related_id' => $transaction->id,
                'notes' => "إعادة تسجيل دفعة نقدية مسددة من العميل بقيمة {$amountPaid} — {$walletTypeName} - {$customerName}",
                'created_by' => $createdBy,
            ]);
        } else {
            // Receive
            $this->transactionService->recordExpense([
                'amount' => $amountPaid,
                'from_account_id' => $transaction->cash_account_id,
                'contra_account_id' => $customerAccount->id,
                'module' => TransactionModule::Wallet->value,
                'related_type' => WalletTransaction::class,
                'related_id' => $transaction->id,
                'notes' => "إعادة تسجيل دفعة نقدية مسددة للعميل بقيمة {$amountPaid} — {$walletTypeName} - {$customerName}",
                'created_by' => $createdBy,
            ]);
        }
    }

    /**
     * إرسال رصيد للعميل:
     *   مع تفعيل نظام الأجل:
     *   أ) في حال اختيار عميل مسجل:
     *      1. نسجل مديونية بقيمة total_amount كاملة على حساب العميل (Income للعميل).
     *      2. نسجل خصم الرصيد من المحفظة بقيمة amount (Expense للمحفظة).
     *      3. لو سدد العميل دفعة (amount_paid > 0)، نسجل تحصيل الدفعة للخزينة مع contra_account_id هو حساب العميل (سداد).
     *   ب) عميل غير مسجل:
     *      مباشرة استلام نقدي بالخزينة بقيمة total_amount كاملة، وخصم الرصيد من المحفظة بقيمة amount.
     */
    private function accountForSend(
        WalletTransaction $record,
        float $amount,
        float $fee,
        string $walletTypeName,
        string $customerName,
        int $createdBy
    ): array {
        [$income, $expense] = $this->postMainSendPair(
            $record, $amount, $fee, $walletTypeName, $customerName, $createdBy
        );

        $this->postSettlementSend(
            $record, (float) $record->amount_paid, $walletTypeName, $customerName, $createdBy
        );

        return [$income, $expense];
    }

    /**
     * Post only the main income + expense pair for a Send transaction.
     * Settlement is intentionally NOT posted here — that is handled by
     * postSettlementSend() so that repost flows can update the main pair
     * independently of the settlement (and vice-versa).
     */
    protected function postMainSendPair(
        WalletTransaction $record,
        float $amount,
        float $fee,
        string $walletTypeName,
        string $customerName,
        int $createdBy
    ): array {
        $totalAmount = $amount + $fee;

        if ($record->customer_id) {
            $customerAccount = $this->ensureCustomerAccount((int) $record->customer_id);

            $income = $this->transactionService->recordIncome([
                'amount' => $totalAmount,
                'to_account_id' => $customerAccount->id,
                'module' => TransactionModule::Wallet->value,
                'related_type' => WalletTransaction::class,
                'related_id' => $record->id,
                'notes' => "إرسال {$walletTypeName} - {$customerName}: مديونية إرسال رصيد بقيمة {$amount} + رسوم {$fee}",
                'created_by' => $createdBy,
            ]);

            $expense = $this->transactionService->recordExpense([
                'amount' => $amount,
                'from_account_id' => $record->wallet_account_id,
                'module' => TransactionModule::Wallet->value,
                'related_type' => WalletTransaction::class,
                'related_id' => $record->id,
                'notes' => "إرسال {$walletTypeName} - {$customerName}: خصم رصيد بقيمة {$amount} من المحفظة",
                'created_by' => $createdBy,
            ]);

            return [$income, $expense];
        }

        // Anonymous customer (نقدي فوري): no settlement, no customer account
        $income = $this->transactionService->recordIncome([
            'amount' => $totalAmount,
            'to_account_id' => $record->cash_account_id,
            'module' => TransactionModule::Wallet->value,
            'related_type' => WalletTransaction::class,
            'related_id' => $record->id,
            'notes' => "إرسال {$walletTypeName} - {$customerName}: استلام نقدي {$amount} + خدمة {$fee}",
            'created_by' => $createdBy,
        ]);

        $expense = $this->transactionService->recordExpense([
            'amount' => $amount,
            'from_account_id' => $record->wallet_account_id,
            'module' => TransactionModule::Wallet->value,
            'related_type' => WalletTransaction::class,
            'related_id' => $record->id,
            'notes' => "إرسال {$walletTypeName} - {$customerName}: خصم من المحفظة {$amount}",
            'created_by' => $createdBy,
        ]);

        return [$income, $expense];
    }

    /**
     * Post only the optional settlement transaction for a Send with a
     * registered customer when amount_paid > 0. Idempotent — if amount_paid
     * is 0 or the customer has no registered account, this is a no-op.
     */
    protected function postSettlementSend(
        WalletTransaction $record,
        float $amountPaid,
        string $walletTypeName,
        string $customerName,
        int $createdBy
    ): void {
        if (! $record->customer_id) {
            return;
        }

        if ($amountPaid < 0.001) {
            return;
        }

        $customerAccount = $this->ensureCustomerAccount((int) $record->customer_id);

        $this->transactionService->recordIncome([
            'amount' => $amountPaid,
            'to_account_id' => $record->cash_account_id,
            'contra_account_id' => $customerAccount->id,
            'module' => TransactionModule::Wallet->value,
            'related_type' => WalletTransaction::class,
            'related_id' => $record->id,
            'notes' => "إرسال {$walletTypeName} - {$customerName}: دفعة نقدية مسددة من العميل بقيمة {$amountPaid}",
            'created_by' => $createdBy,
        ]);
    }

/**
     * استقبال رصيد من العميل:
     *   أ) في حال اختيار عميل مسجل:
     *      1. نسجل زيادة الرصيد بمحفظتنا بقيمة amount (Income للمحفظة).
     *      2. نسجل استحقاق العميل بقيمة total_amount كاملة (صافي الاستقبال) على حساب العميل (Expense للعميل / دائن).
     *      3. لو دفعنا للعميل جزء نقدياً (amount_paid > 0)، نسجل خروج المبلغ من الخزينة مع contra_account_id هو حساب العميل (خصم).
     *   ب) عميل غير مسجل:
     *      مباشرة زيادة الرصيد بالمحفظة بقيمة amount، وصرف المبلغ نقدي للعميل بقيمة total_amount من الخزينة.
     */
    private function accountForReceive(
        WalletTransaction $record,
        float $amount,
        float $fee,
        string $walletTypeName,
        string $customerName,
        int $createdBy
    ): array {
        [$income, $expense] = $this->postMainReceivePair(
            $record, $amount, $fee, $walletTypeName, $customerName, $createdBy
        );

        $this->postSettlementReceive(
            $record, (float) $record->amount_paid, $walletTypeName, $customerName, $createdBy
        );

        return [$income, $expense];
    }

    /**
     * Post only the main income + expense pair for a Receive transaction.
     * Settlement is intentionally NOT posted here — handled by
     * postSettlementReceive() so the repost flow can update main pair and
     * settlement independently.
     */
    protected function postMainReceivePair(
        WalletTransaction $record,
        float $amount,
        float $fee,
        string $walletTypeName,
        string $customerName,
        int $createdBy
    ): array {
        $totalAmount = $amount - $fee;

        if ($record->customer_id) {
            $customerAccount = $this->ensureCustomerAccount((int) $record->customer_id);

            $income = $this->transactionService->recordIncome([
                'amount' => $amount,
                'to_account_id' => $record->wallet_account_id,
                'module' => TransactionModule::Wallet->value,
                'related_type' => WalletTransaction::class,
                'related_id' => $record->id,
                'notes' => "استقبال {$walletTypeName} - {$customerName}: استلام رصيد بقيمة {$amount} في المحفظة",
                'created_by' => $createdBy,
            ]);

            $expense = $this->transactionService->recordExpense([
                'amount' => $totalAmount,
                'from_account_id' => $customerAccount->id,
                'module' => TransactionModule::Wallet->value,
                'related_type' => WalletTransaction::class,
                'related_id' => $record->id,
                'notes' => "استقبال {$walletTypeName} - {$customerName}: مستحق للعميل بقيمة {$totalAmount} (صافي بعد رسوم {$fee})",
                'created_by' => $createdBy,
            ]);

            return [$income, $expense];
        }

        // Anonymous customer
        $income = $this->transactionService->recordIncome([
            'amount' => $amount,
            'to_account_id' => $record->wallet_account_id,
            'module' => TransactionModule::Wallet->value,
            'related_type' => WalletTransaction::class,
            'related_id' => $record->id,
            'notes' => "استقبال {$walletTypeName} - {$customerName}: استلام محفظة {$amount}",
            'created_by' => $createdBy,
        ]);

        $expense = $this->transactionService->recordExpense([
            'amount' => $totalAmount,
            'from_account_id' => $record->cash_account_id,
            'module' => TransactionModule::Wallet->value,
            'related_type' => WalletTransaction::class,
            'related_id' => $record->id,
            'notes' => "استقبال {$walletTypeName} - {$customerName}: دفع نقدي {$totalAmount}",
            'created_by' => $createdBy,
        ]);

        return [$income, $expense];
    }

    /**
     * Post only the optional settlement transaction for a Receive with a
     * registered customer when amount_paid > 0. Idempotent — if amount_paid
     * is 0 or the customer has no registered account, this is a no-op.
     */
    protected function postSettlementReceive(
        WalletTransaction $record,
        float $amountPaid,
        string $walletTypeName,
        string $customerName,
        int $createdBy
    ): void {
        if (! $record->customer_id) {
            return;
        }

        if ($amountPaid < 0.001) {
            return;
        }

        $customerAccount = $this->ensureCustomerAccount((int) $record->customer_id);

        $this->transactionService->recordExpense([
            'amount' => $amountPaid,
            'from_account_id' => $record->cash_account_id,
            'contra_account_id' => $customerAccount->id,
            'module' => TransactionModule::Wallet->value,
            'related_type' => WalletTransaction::class,
            'related_id' => $record->id,
            'notes' => "استقبال {$walletTypeName} - {$customerName}: دفعة نقدية مسددة للعميل بقيمة {$amountPaid}",
            'created_by' => $createdBy,
        ]);
    }

    public function deleteTransaction(WalletTransaction $transaction): bool
    {
        try {
            return DB::transaction(function () use ($transaction) {
                // عكس كل القيود المحاسبية التابعة لهذه العملية بما فيها السداد/الصرف التابع
                $relatedTransactions = Transaction::where('related_type', WalletTransaction::class)
                    ->where('related_id', $transaction->id)
                    ->get();

                foreach ($relatedTransactions as $rt) {
                    $this->transactionService->reverseTransaction($rt);
                }

                $transaction->delete();

                Log::info('WalletTransaction deleted and ledger reversed', [
                    'id' => $transaction->id,
                    'deleted_by' => Auth::id(),
                ]);

                return true;
            });
        } catch (\Exception $e) {
            Log::error('WalletTransactionService::deleteTransaction failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'id' => $transaction->id,
            ]);
            throw $e;
        }
    }

    protected function ensureCustomerAccount(int $customerId): Account
    {
        $customer = Customer::findOrFail($customerId);

        if ($customer->account_id) {
            $account = Account::find($customer->account_id);
            if ($account) {
                // Phase 8 fix: CustomerLedgerObserver creates a generic
                // 'office'-tagged account the moment a Customer row is
                // inserted. When that customer is later used by a wallet
                // transaction, we re-tag the account to 'wallet_transfer'
                // so it surfaces in the TransferDashboardController stats
                // and TransferAccounts/* resources (which filter strictly
                // by module_type='wallet_transfer'). The re-tag is wrapped
                // in LedgerBalanceMutationGuard because touching `balance`
                // — even to confirm 0.00 — would otherwise trip the
                // `Account::updating` boot guard.
                if ($account->module_type !== 'wallet_transfer') {
                    LedgerBalanceMutationGuard::run(function () use ($account) {
                        $account->module_type = 'wallet_transfer';
                        $account->save();
                    });
                }

                return $account;
            }
        }

        return LedgerBalanceMutationGuard::run(fn () => DB::transaction(function () use ($customer) {
            $account = Account::create([
                'name' => 'حساب العميل: '.$customer->full_name,
                'type' => AccountType::Customer,
                'balance' => 0,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OWNER,
                'module_type' => 'wallet_transfer',
                'is_module_vault' => false,
                'notes' => 'حساب تلقائي للعميل #'.$customer->id,
                'created_by' => Auth::id() ?? 1,
            ]);

            $customer->update(['account_id' => $account->id]);

            return $account;
        }));
    }

    public function getTransactionById(int $id): WalletTransaction
    {
        return WalletTransaction::with([
            'walletType', 'customer', 'walletAccount', 'cashAccount',
            'employee', 'createdBy', 'incomeTransaction', 'expenseTransaction',
        ])->findOrFail($id);
    }

    public function getDailySummary(string $date): array
    {
        $result = WalletTransaction::whereDate('created_at', $date)
            ->selectRaw('
                COUNT(*)                                       as total_transactions,
                SUM(CASE WHEN type = "send"    THEN 1 ELSE 0 END) as send_count,
                SUM(CASE WHEN type = "receive" THEN 1 ELSE 0 END) as receive_count,
                SUM(CASE WHEN type = "send"    THEN amount ELSE 0 END) as total_sent,
                SUM(CASE WHEN type = "receive" THEN amount ELSE 0 END) as total_received,
                SUM(service_fee) as total_fees
            ')
            ->first();

        return [
            'total_transactions' => (int) ($result->total_transactions ?? 0),
            'send_count' => (int) ($result->send_count ?? 0),
            'receive_count' => (int) ($result->receive_count ?? 0),
            'total_sent' => (float) ($result->total_sent ?? 0),
            'total_received' => (float) ($result->total_received ?? 0),
            'total_fees' => (float) ($result->total_fees ?? 0),
        ];
    }
}
