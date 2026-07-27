<?php

namespace App\Services\Fawry;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Fawry\FawryMachine;
use App\Models\Fawry\FawryOperationType;
use App\Models\Fawry\FawryTransaction;
use App\Models\Transaction;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Finance\TransactionService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FawryTransactionService
{
    protected TransactionService $transactionService;

    public function __construct(TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    public function getAllTransactions(array $filters): LengthAwarePaginator
    {
        $query = FawryTransaction::with([
            'client',
            'employee',
            'account',
            'currency',
            'expenseTransaction',
            'incomeTransaction',
            'operationTypeRow',
            'paymentMethodRow',
            'machine',
        ]);

        if (isset($filters['operation_type']) && $filters['operation_type']) {
            $query->where('operation_type', $filters['operation_type']);
        }

        if (isset($filters['payment_method']) && $filters['payment_method']) {
            $query->where('payment_method', $filters['payment_method']);
        }

        if (isset($filters['employee_id']) && $filters['employee_id']) {
            $query->where('employee_id', $filters['employee_id']);
        }

        if (isset($filters['from_date']) && $filters['from_date']) {
            $query->where('created_at', '>=', $filters['from_date'].' 00:00:00');
        }

        if (isset($filters['to_date']) && $filters['to_date']) {
            $query->where('created_at', '<=', $filters['to_date'].' 23:59:59');
        }

        if (isset($filters['search']) && $filters['search']) {
            $query->where('client_name', 'like', '%'.$filters['search'].'%')
                ->orWhere('reference_number', 'like', '%'.$filters['search'].'%');
        }

        $perPage = min($filters['per_page'] ?? 20, 100);

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

public function createTransaction(array $data): FawryTransaction
    {
        // 4. SETTLEMENT ACCOUNT VALIDATION
        if (empty($data['account_id']) || ! ($accountToCheck = Account::find($data['account_id'])) || ! $accountToCheck->is_active) {
            throw new \InvalidArgumentException('يجب اختيار حساب تحصيل صالح ونشط');
        }

        try {
            return DB::transaction(function () use ($data) {
                // 3. BALANCE GUARD with pessimistic locking
                $account = Account::lockForUpdate()->findOrFail($data['account_id']);

                $machine = null;
                if (! empty($data['fawry_machine_id'])) {
                    $machine = FawryMachine::lockForUpdate()->findOrFail($data['fawry_machine_id']);
                    if (! $machine->is_active) {
                        throw new \InvalidArgumentException('ماكينة الشحن المختارة غير نشطة');
                    }
                    $fawryCost = (float) $data['fawry_price'];
                    if ((float) $machine->balance < $fawryCost) {
                        throw new InsufficientBalanceException('رصيد الماكينة غير كافٍ');
                    }
                }

                $profit = ($data['selling_price'] - $data['fawry_price']);

                // Get client name if client_id is provided
                $clientName = $data['client_name'] ?? '';
                if (isset($data['client_id']) && $data['client_id']) {
                    $client = Customer::find($data['client_id']);
                    if ($client) {
                        $clientName = $client->full_name;
                    }
                }

                $createdBy = Auth::id() ?: ($data['created_by'] ?? $data['employee_id'] ?? 1);
                $clientIp = request()->ip() ?? $data['client_ip'] ?? null;

                // Wrap the create in runProfitMutation() so the saving observer
                // guard (which fires before creating) lets the profit write through.
                // Mirrors the BusBookingService pattern.
                $fawryTransaction = FawryTransaction::runProfitMutation(function () use ($data, $clientName, $profit, $createdBy, $clientIp) {
                    return FawryTransaction::create([
                        'client_id' => $data['client_id'] ?? null,
                        'client_name' => $clientName,
                        'operation_type' => $data['operation_type'],
                        'client_amount' => $data['client_amount'],
                        'fawry_price' => $data['fawry_price'],
                        'selling_price' => $data['selling_price'],
                        'profit' => $profit,
                        'employee_id' => $data['employee_id'],
                        'account_id' => $data['account_id'],
                        'fawry_machine_id' => $data['fawry_machine_id'] ?? null,
                        'payment_method' => $data['payment_method'],
                        'amount' => $data['amount'],
                        'reference_number' => $data['reference_number'] ?? null,
                        'notes' => $data['notes'] ?? null,
                        'currency_id' => $data['currency_id'] ?? null,
                        'payment_details' => $data['payment_details'] ?? null,
                        'created_by_user_id' => $createdBy,
                        'client_ip' => $clientIp,
                    ]);
                });

                // Get operation type label from database
                $operationType = FawryOperationType::where('code', $data['operation_type'])->first();
                $operationLabel = $operationType ? $operationType->name_ar : $data['operation_type'];

                if ($machine) {
                    $machine->debit(
                        (float) $data['fawry_price'],
                        "عملية فوري - {$operationLabel}: {$clientName}",
                        $createdBy,
                        $fawryTransaction->id
                    );
                }

                [$incomeTransactionId, $expenseTransactionId] = $this->postLedgerEntries(
                    fawryTransaction: $fawryTransaction,
                    clientId: $data['client_id'] ?? null,
                    accountId: $data['account_id'],
                    fawryPrice: (float) $data['fawry_price'],
                    sellingPrice: (float) $data['selling_price'],
                    amountPaid: (float) $data['amount'],
                    hasMachine: $machine !== null,
                    createdBy: $createdBy,
                    operationLabel: $operationLabel,
                    clientName: $clientName,
                );

                $updates = [];
                if ($incomeTransactionId) {
                    $updates['income_transaction_id'] = $incomeTransactionId;
                }
                if ($expenseTransactionId) {
                    $updates['expense_transaction_id'] = $expenseTransactionId;
                }
                if (! empty($updates)) {
                    $fawryTransaction->update($updates);
                }

                Log::info('Fawry transaction created', [
                    'fawry_transaction_id' => $fawryTransaction->id,
                    'operation_type' => $data['operation_type'],
                    'client_name' => $clientName,
                    'amount' => $data['selling_price'],
                    'profit' => $profit,
                    'created_by' => $createdBy,
                ]);

                return $fawryTransaction->fresh([
                    'client',
                    'employee',
                    'account',
                    'currency',
                    'expenseTransaction',
                    'incomeTransaction',
                    'operationTypeRow',
                    'paymentMethodRow',
                    'machine',
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('FawryTransactionService::createTransaction failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'input' => $data,
            ]);
            throw $e;
        }
    }

    /**
     * Post the GL ledger entries (expense + sale income + optional settlement)
     * for a Fawry transaction. Used by both createTransaction and the
     * repost flow in updateTransaction.
     *
     * The optional settlement is the 3rd row created when:
     *   - client_id is set (registered customer)
     *   - amount > 0 (partial on-the-spot payment)
     * It is identified by account pair (customer_account ↔ settlement_account)
     * — there is NO `settlement_transaction_id` column on the model.
     *
     * Returns [int|null $incomeId, int|null $expenseId] for caller to write
     * back to $fawryTransaction pointers.
     *
     * @return array{0: int|null, 1: int|null}
     */
    protected function postLedgerEntries(
        FawryTransaction $fawryTransaction,
        ?int $clientId,
        int $accountId,
        float $fawryPrice,
        float $sellingPrice,
        float $amountPaid,
        bool $hasMachine,
        int $createdBy,
        string $operationLabel,
        string $clientName,
    ): array {
        // 1) Expense: تكلفة Fawry
        //    - مع ماكينة → من حساب الرصيد المسبق (prepaid)
        //    - بدون ماكينة (walk-in):
        //        * لو العميل دفع الآن → من حساب التحصيل (cashbox): صافي
        //          الخزينة = المبلغ - التكلفة = الربح
        //        * لو آجل (لم يدفع) → من حساب إقفال الإيرادات (income contra)
        //          لأن الخزينة لم تستلم شيئاً، فلا يجوز خصم التكلفة منها.
        //          هذا يحافظ على توازن الحسابات عند العملاء غير المسددين.
        $expenseTransactionId = null;
        if ($fawryPrice > 0) {
            if ($hasMachine) {
                $expenseAccountId = app(LedgerClearingAccounts::class)->prepaidAccountId('fawry');
            } elseif ($amountPaid > 0) {
                $expenseAccountId = $accountId; // من خزينة التحصيل (العميل دفع)
            } else {
                // walk-in آجل: التكلفة من حساب الإيرادات (وليس الخزينة)
                $expenseAccountId = app(LedgerClearingAccounts::class)->incomeContraIdForModule('fawry');
            }

            if ($expenseAccountId) {
                $expenseTransaction = $this->transactionService->recordExpense([
                    'amount' => $fawryPrice,
                    'from_account_id' => $expenseAccountId,
                    'module' => TransactionModule::Fawry->value,
                    'related_type' => FawryTransaction::class,
                    'related_id' => $fawryTransaction->id,
                    'notes' => "تكلفة عملية فوري - {$operationLabel}: {$clientName}",
                    'created_by' => $createdBy,
                ]);
                $expenseTransactionId = $expenseTransaction->id;
            }
        }

        // 2) Sale income + optional settlement
        $incomeTransactionId = null;
        if (! empty($clientId)) {
            $customerAccount = $this->ensureCustomerAccount($clientId);

            $saleIncomeTransaction = $this->transactionService->recordIncome([
                'amount' => $sellingPrice,
                'to_account_id' => $customerAccount->id,
                'module' => TransactionModule::Fawry->value,
                'related_type' => FawryTransaction::class,
                'related_id' => $fawryTransaction->id,
                'notes' => "تحصيل فوري (مديونية) - {$operationLabel}: {$clientName}",
                'created_by' => $createdBy,
            ]);
            $incomeTransactionId = $saleIncomeTransaction->id;

            // Settlement: تحصيل جزئي من العميل → الخزينة
            if ($amountPaid > 0) {
                $this->transactionService->recordIncome([
                    'amount' => $amountPaid,
                    'to_account_id' => $accountId,
                    'contra_account_id' => $customerAccount->id,
                    'module' => TransactionModule::Fawry->value,
                    'related_type' => FawryTransaction::class,
                    'related_id' => $fawryTransaction->id,
                    'notes' => "سداد جزء من عملية فوري - {$operationLabel}: {$clientName}",
                    'created_by' => $createdBy,
                ]);
            }
        } else {
            // Walk-in client (no Customer record). Route the receivable
            // through the unified walk-in AR account ("ذمم عملاء فوري غير
            // مسجلين") so the debt:
            //   1) lives in the GL (visible in trial balance / office
            //      receivables report)
            //   2) is pay-able via POST /api/v1/fawry/walk-in/pay-debt
            //      by FIFO allocation against fawry_transactions.amount
            //
            // Two journal transfers:
            //   [a] credit AR account for $sellingPrice (full debt)
            //   [b] if $amountPaid > 0: debit AR account →
            //       credit settlement account for the cash received
            // Net effect on AR account balance = $sellingPrice − $amountPaid.
            //
            // Legacy walk-in transactions (created before this block was
            // introduced) credited the settlement account directly with no
            // AR entry. Their debt is sourced from the columns instead
            // (see FinancialReportService::getDebtsReport walk-in branch).
            $walkInArAccountId = app(LedgerClearingAccounts::class)->fawryWalkInArAccountId();
            $contraAccountId = app(LedgerClearingAccounts::class)->incomeContraIdForModule('fawry');

            $saleIncome = $this->transactionService->recordJournalTransfer([
                'amount' => $sellingPrice,
                'from_account_id' => $contraAccountId,
                'to_account_id' => $walkInArAccountId,
                'module' => TransactionModule::Fawry->value,
                'related_type' => FawryTransaction::class,
                'related_id' => $fawryTransaction->id,
                'notes' => "مديونية فوري (عميل غير مسجل - {$clientName}) - {$operationLabel}",
                'created_by' => $createdBy,
                'allow_from_negative' => true,
            ]);
            $incomeTransactionId = $saleIncome->id;

            // Cash received at creation time → transfer from AR to settlement
            if ($amountPaid > 0) {
                $this->transactionService->recordJournalTransfer([
                    'amount' => $amountPaid,
                    'from_account_id' => $walkInArAccountId,
                    'to_account_id' => $accountId,
                    'module' => TransactionModule::Fawry->value,
                    'related_type' => FawryTransaction::class,
                    'related_id' => $fawryTransaction->id,
                    'notes' => "سداد جزء من فوري (عميل غير مسجل - {$clientName}) - {$operationLabel}",
                    'created_by' => $createdBy,
                ]);
            }
        }

        return [$incomeTransactionId, $expenseTransactionId];
    }

    public function updateTransaction(FawryTransaction $transaction, array $data): FawryTransaction
    {
        try {
            return DB::transaction(function () use ($transaction, $data) {
                // Detect ACTUAL changes (vs same value) — used to gate the
                // ledger repost so we don't waste DB writes on no-op edits.
                // Mirrors OnlineTransactionService Phase 9 / HajjUmra Phase 8
                // pattern. The 4 fields below all have a GL impact; any
                // change requires reversing the old entries (additive) and
                // re-posting with the corrected values.
                $sellingChanged = array_key_exists('selling_price', $data)
                    && (float) $data['selling_price'] !== (float) $transaction->selling_price;
                $fawryPriceChanged = array_key_exists('fawry_price', $data)
                    && (float) $data['fawry_price'] !== (float) $transaction->fawry_price;
                $amountChanged = array_key_exists('amount', $data)
                    && (float) $data['amount'] !== (float) $transaction->amount;
                $accountChanged = array_key_exists('account_id', $data)
                    && (int) $data['account_id'] !== (int) $transaction->account_id;

                $priceOrAccountChanged = $sellingChanged || $fawryPriceChanged || $accountChanged;
                $anyLedgerAffectingChange = $priceOrAccountChanged || $amountChanged;

                // Capture old fawry_price BEFORE we mutate the model, so the
                // machine-balance re-adjustment below has both values to
                // compute the diff. Without this, update-then-delete would
                // leave the machine at the wrong balance (Bug A fix).
                $oldFawryPrice = (float) $transaction->fawry_price;
                $oldMachineId = $transaction->fawry_machine_id;

                // Recompute profit if selling/fawry price changed.
                if ($sellingChanged || $fawryPriceChanged) {
                    $fawryPrice = (float) ($data['fawry_price'] ?? $transaction->fawry_price);
                    $sellingPrice = (float) ($data['selling_price'] ?? $transaction->selling_price);
                    $data['profit'] = $sellingPrice - $fawryPrice;
                }

                // Wrapped in FawryTransaction::runProfitMutation() so the
                // ModelProfitMutationGuard lets the canonical `profit` write
                // through (only matters when $data['profit'] was just set
                // above, but wrapping unconditionally keeps the gate simple).
                FawryTransaction::runProfitMutation(function () use ($transaction, $data) {
                    $transaction->update($data);
                });

                // 🛡️ ACCOUNTING INTEGRITY (Phase A fix — same pattern as
                // OnlineTransactionService Phase 9 / HajjUmraBookingService
                // Phase 8): when selling_price / fawry_price / amount /
                // account_id change, the OLD ledger entries must be reversed
                // (additive — never destructive) and NEW entries posted
                // with the corrected values. Skipping this would leave the
                // model and the GL desynced silently.
                if ($anyLedgerAffectingChange) {
                    // Reverse all linked GL transactions (including the
                    // optional settlement that is NOT stored on the model —
                    // identified by account pair).
                    $linked = Transaction::where('related_type', FawryTransaction::class)
                        ->where('related_id', $transaction->id)
                        ->get();

                    foreach ($linked as $linkedTx) {
                        $this->transactionService->reverseTransaction($linkedTx);
                    }

                    // Resolve the operation label (re-query — model may have
                    // changed operation_type, though we don't yet repost on
                    // that field; cheap to recompute anyway).
                    $operationType = $transaction->operation_type
                        ? FawryOperationType::where('code', $transaction->operation_type)->first()
                        : null;
                    $operationLabel = $operationType?->name_ar ?? (string) $transaction->operation_type;
                    $clientName = (string) $transaction->client_name;
                    $createdBy = Auth::id() ?? (int) ($transaction->created_by_user_id ?? 1);

                    [$newIncomeId, $newExpenseId] = $this->postLedgerEntries(
                        fawryTransaction: $transaction->fresh(),
                        clientId: $transaction->client_id ? (int) $transaction->client_id : null,
                        accountId: (int) $transaction->account_id,
                        fawryPrice: (float) $transaction->fawry_price,
                        sellingPrice: (float) $transaction->selling_price,
                        amountPaid: (float) $transaction->amount,
                        hasMachine: ! empty($transaction->fawry_machine_id),
                        createdBy: $createdBy,
                        operationLabel: $operationLabel,
                        clientName: $clientName,
                    );

                    $updates = [];
                    if ($newIncomeId) {
                        $updates['income_transaction_id'] = $newIncomeId;
                    }
                    if ($newExpenseId) {
                        $updates['expense_transaction_id'] = $newExpenseId;
                    }
                    if (! empty($updates)) {
                        $transaction->update($updates);
                    }
                }

                // 🛡️ MACHINE-BALANCE RECONCILIATION (Bug A fix):
                // The reverse + repost above restores the GL accounts
                // (prepaid asset etc.) but does NOT touch the FawryMachine
                // row, which tracks the vendor-side balance. If fawry_price
                // changes, the machine was debited the old price on create
                // and would be credited the new price on delete — leaving
                // a net mismatch on the order of (new − old). Re-adjust the
                // machine now so a subsequent delete returns it to the
                // pre-create balance.
                if ($fawryPriceChanged && $oldMachineId && $oldMachineId === $transaction->fawry_machine_id) {
                    $newFawryPrice = (float) $transaction->fawry_price;
                    $diff = round($newFawryPrice - $oldFawryPrice, 2);
                    if (abs($diff) >= 0.01) {
                        $machine = FawryMachine::lockForUpdate()->find($oldMachineId);
                        if ($machine) {
                            $createdBy = Auth::id() ?? (int) ($transaction->created_by_user_id ?? 1);
                            if ($diff > 0) {
                                // New cost is higher → additional debit
                                $machine->debit(
                                    $diff,
                                    "تعديل تكلفة فوري #{$transaction->id}: {$oldFawryPrice} → {$newFawryPrice}",
                                    $createdBy,
                                    $transaction->id
                                );
                            } else {
                                // New cost is lower → partial credit back
                                $machine->credit(
                                    -$diff,
                                    "تعديل تكلفة فوري #{$transaction->id}: {$oldFawryPrice} → {$newFawryPrice}",
                                    $createdBy,
                                    $transaction->id
                                );
                            }
                        }
                    }
                }

                Log::info('Fawry transaction updated', [
                    'fawry_transaction_id' => $transaction->id,
                    'selling_changed' => $sellingChanged,
                    'fawry_price_changed' => $fawryPriceChanged,
                    'amount_changed' => $amountChanged,
                    'account_changed' => $accountChanged,
                    'updated_by' => Auth::id(),
                ]);

                return $transaction->fresh([
                    'client',
                    'employee',
                    'account',
                    'currency',
                    'expenseTransaction',
                    'incomeTransaction',
                    'operationTypeRow',
                    'paymentMethodRow',
                    'machine',
                ]);
            });
        } catch (\Exception $e) {
            Log::error('FawryTransactionService::updateTransaction failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'fawry_transaction_id' => $transaction->id,
                'input' => $data,
            ]);
            throw $e;
        }
    }

    public function deleteTransaction(FawryTransaction $transaction): bool
    {
        // 🛡️ Idempotency guard (Bug D fix): if the transaction is already
        // soft-deleted, do nothing. Without this, a second call on the
        // same in-memory model would re-credit the machine and re-post the
        // walk-in AR reclamation, leaving the books out of balance.
        //
        // Important: we query the DB directly instead of trusting
        // $transaction->trashed(), because the in-memory model passed in by
        // the caller is often stale (the caller may have obtained it
        // BEFORE the first delete ran, so its deleted_at is still null).
        // Checking the DB guarantees we see the authoritative state.
        $alreadyDeleted = DB::table('fawry_transactions')
            ->where('id', $transaction->id)
            ->whereNotNull('deleted_at')
            ->exists();
        if ($alreadyDeleted) {
            Log::info('Fawry transaction delete skipped — already soft-deleted', [
                'fawry_transaction_id' => $transaction->id,
                'user_id' => Auth::id(),
            ]);
            return true;
        }

        try {
            return DB::transaction(function () use ($transaction) {
                // 🛡️ Bug B refresh: re-read the row from the DB so the
                // `amount` column reflects any later pay-debt FIFO updates
                // (which the walk-in pay-debt controller writes directly
                // to the column without touching the in-memory model).
                // Without this, $paidAmount would be the stale at-create
                // value and $excessToReclaim would compute to 0, skipping
                // the reclaim step that keeps the walk-in AR balanced.
                $transaction = $transaction->fresh();
                $paidAmount = (float) ($transaction->amount ?? 0.0);
                $isWalkIn = empty($transaction->client_id);
                $clientName = (string) $transaction->client_name;
                $settlementAccountId = (int) $transaction->account_id;

                // 🛡️ Bug B refinement: only the EXCESS of (current amount)
                // over the original settlement needs reclaiming. The original
                // settlement (amount set at creation) is already linked to
                // this fawry_transaction via related_id, so it gets reversed
                // in step 2. Without this guard we'd be double-counting the
                // original payment and pushing the walk-in AR balance into
                // a +200 phantom credit.
                $originalSettlementAmount = 0.0;
                if ($isWalkIn && $paidAmount > 0.005) {
                    $originalSettlementAmount = (float) DB::table('account_entries as ae')
                        ->join('transactions as t', 'ae.transaction_id', '=', 't.id')
                        ->where('t.related_type', FawryTransaction::class)
                        ->where('t.related_id', $transaction->id)
                        ->where('ae.account_id', $settlementAccountId)
                        ->where('ae.credit', '>', 0)
                        ->whereRaw("(ae.notes IS NULL OR (ae.notes NOT LIKE ? AND ae.notes NOT LIKE ?))", ['عكس:%', 'عكس %'])
                        ->sum('ae.credit');
                }
                $excessToReclaim = max(0.0, round($paidAmount - $originalSettlementAmount, 2));

                // ── 1. Reverse the machine balance (restore fawry_price to machine)
                if ($transaction->fawry_machine_id && $transaction->fawry_price > 0) {
                    $machine = FawryMachine::lockForUpdate()->find($transaction->fawry_machine_id);
                    if ($machine) {
                        $createdBy = Auth::id() ?? $transaction->created_by_user_id ?? 1;
                        $machine->credit(
                            (float) $transaction->fawry_price,
                            'عكس عملية فوري #'.$transaction->id,
                            $createdBy,
                            $transaction->id
                        );
                    }
                }

                // ── 2. Reverse ALL transactions linked to this fawry transaction
                //       (covers: expense TX, income/debt TX, and payment TX)
                $linkedTransactions = Transaction::where('related_type', FawryTransaction::class)
                    ->where('related_id', $transaction->id)
                    ->orderByDesc('id') // reverse in reverse chronological order
                    ->get();

                foreach ($linkedTransactions as $linkedTx) {
                    $this->transactionService->reverseTransaction($linkedTx);
                }

                // ── 3. Walk-in AR reclamation (Bug B fix) — excess only:
                //       Walk-in pay-debt flows apply FIFO to fawry_transactions.amount
                //       but post ONE aggregate journal entry (walkInAR → cashbox)
                //       NOT linked to a specific fawry_transaction. So step 2
                //       didn't see those entries. The original-settlement
                //       amount IS reversed in step 2 (it carries related_id),
                //       so we only need to reclaim what was paid beyond the
                //       original settlement — i.e. the FIFO pay-debt portion.
                if ($isWalkIn && $excessToReclaim > 0.005) {
                    $clearing = app(LedgerClearingAccounts::class);
                    $walkInArId = $clearing->fawryWalkInArAccountId();
                    $createdBy = Auth::id() ?? (int) ($transaction->created_by_user_id ?? 1);

                    // 3a) First, try to re-allocate the excess to OTHER
                    //     unpaid walk-in transactions for the same client_name
                    //     via FIFO — keeps the per-transaction debt report honest.
                    $remaining = $excessToReclaim;
                    $otherTxs = DB::table('fawry_transactions')
                        ->whereNull('client_id')
                        ->where('client_name', $clientName)
                        ->where('id', '!=', $transaction->id)
                        ->whereRaw('selling_price > amount')
                        ->orderBy('created_at', 'asc')
                        ->orderBy('id', 'asc')
                        ->lockForUpdate()
                        ->get();

                    foreach ($otherTxs as $otherTx) {
                        if ($remaining <= 0.005) {
                            break;
                        }
                        $otherDebt = (float) $otherTx->selling_price - (float) $otherTx->amount;
                        if ($otherDebt <= 0) {
                            continue;
                        }
                        $allocate = min($remaining, $otherDebt);
                        DB::table('fawry_transactions')
                            ->where('id', $otherTx->id)
                            ->update([
                                'amount' => DB::raw('amount + '.(float) $allocate),
                                'updated_at' => now(),
                            ]);
                        $remaining = round($remaining - $allocate, 2);
                    }

                    // 3b) Whatever wasn't absorbed by other walk-in transactions
                    //     gets returned to the walk-in AR as a credit memo
                    //     (debit cashbox, credit walkInAR). This balances the
                    //     AR and acknowledges the client's residual credit.
                    if ($remaining > 0.005) {
                        $this->transactionService->recordJournalTransfer([
                            'amount' => $remaining,
                            'from_account_id' => $settlementAccountId,
                            'to_account_id' => $walkInArId,
                            'module' => TransactionModule::Fawry->value,
                            'related_type' => FawryTransaction::class,
                            'related_id' => $transaction->id,
                            'notes' => "إعادة مديونية walk-in محذوفة ({$clientName}) — عملية #{$transaction->id}",
                            'created_by' => $createdBy,
                            'allow_from_negative' => false,
                        ]);
                    }

                    // 3c) Zero out the deleted transaction's amount column so
                    //     the per-transaction debt is consistent. (We don't
                    //     touch the OTHER walk-in transactions that received
                    //     the re-allocated FIFO portion — their `amount` is
                    //     already correct from step 3a.)
                    DB::table('fawry_transactions')
                        ->where('id', $transaction->id)
                        ->update([
                            'amount' => 0,
                            'updated_at' => now(),
                        ]);
                }

                $transaction->delete();

                Log::info('Fawry transaction deleted', [
                    'fawry_transaction_id' => $transaction->id,
                    'deleted_by' => Auth::id(),
                    'paid_amount' => $paidAmount,
                    'original_settlement' => $originalSettlementAmount,
                    'excess_reclaimed' => $excessToReclaim,
                ]);

                return true;
            });
        } catch (\Exception $e) {
            Log::error('FawryTransactionService::deleteTransaction failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'fawry_transaction_id' => $transaction->id,
            ]);
            throw $e;
        }
    }

    public function getTransactionById(int $id): FawryTransaction
    {
        return FawryTransaction::with([
            'client',
            'employee',
            'account',
            'currency',
            'expenseTransaction',
            'incomeTransaction',
            'operationTypeRow',
            'paymentMethodRow',
            'machine',
        ])->findOrFail($id);
    }

    public function getDailySummary(string $date): array
    {
        $startDate = $date.' 00:00:00';
        $endDate = $date.' 23:59:59';

        $results = FawryTransaction::whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                COUNT(*) as total_transactions,
                SUM(client_amount) as total_client_amount,
                SUM(fawry_price) as total_fawry_price,
                SUM(selling_price) as total_selling_price,
                SUM(profit) as total_profit
            ')
            ->first();

        return [
            'total_transactions' => (int) ($results->total_transactions ?? 0),
            'total_client_amount' => (float) ($results->total_client_amount ?? 0.00),
            'total_fawry_price' => (float) ($results->total_fawry_price ?? 0.00),
            'total_selling_price' => (float) ($results->total_selling_price ?? 0.00),
            'total_profit' => (float) ($results->total_profit ?? 0.00),
        ];
    }

    /**
     * Ensures the customer has a ledger account. Creates one if missing.
     */
    protected function ensureCustomerAccount(int $customerId): Account
    {
        $customer = Customer::findOrFail($customerId);

        if ($customer->account_id) {
            $account = Account::find($customer->account_id);
            if ($account) {
                // Phase C.1 fix: CustomerLedgerObserver creates a generic
                // 'office'-tagged account the moment a Customer row is
                // inserted. When that customer is later used in a Fawry
                // flow we re-tag the account to 'fawry' so it surfaces
                // in the Fawry dashboard stats and in the
                // FinancialReportService fawry receivables query (which
                // filters strictly by module_type='fawry'). Wrapped in
                // LedgerBalanceMutationGuard because touching `balance`
                // — even to confirm 0.00 — would otherwise trip the
                // Account::updating boot guard.
                if ($account->module_type !== 'fawry') {
                    LedgerBalanceMutationGuard::run(function () use ($account) {
                        $account->module_type = 'fawry';
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
                'module_type' => 'fawry',
                'is_module_vault' => false,
                'notes' => 'حساب تلقائي للعميل #'.$customer->id,
                'created_by' => Auth::id() ?? 1,
            ]);

            $customer->update(['account_id' => $account->id]);

            return $account;
        }));
    }
}
