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

                $fawryTransaction = FawryTransaction::create([
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
        // 1) Expense: تكلفة Fawry (من prepaid إذا ماكينة، أو من settlement account إذا بدون)
        $expenseTransactionId = null;
        if ($fawryPrice > 0) {
            $expenseAccountId = $hasMachine
                ? app(LedgerClearingAccounts::class)->prepaidAccountId('fawry')
                : $accountId;

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
            // Walk-in client: البيع مباشرة على الخزينة (لا يوجد settlement منفصل)
            $walkInIncome = $this->transactionService->recordIncome([
                'amount' => $sellingPrice,
                'to_account_id' => $accountId,
                'module' => TransactionModule::Fawry->value,
                'related_type' => FawryTransaction::class,
                'related_id' => $fawryTransaction->id,
                'notes' => "تحصيل فوري - {$operationLabel}: {$clientName}",
                'created_by' => $createdBy,
            ]);
            $incomeTransactionId = $walkInIncome->id;
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
                // re-posting with the new values.
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

                // Recompute profit if selling/fawry price changed.
                if ($sellingChanged || $fawryPriceChanged) {
                    $fawryPrice = (float) ($data['fawry_price'] ?? $transaction->fawry_price);
                    $sellingPrice = (float) ($data['selling_price'] ?? $transaction->selling_price);
                    $data['profit'] = $sellingPrice - $fawryPrice;
                }

                $transaction->update($data);

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
        try {
            return DB::transaction(function () use ($transaction) {
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

                $transaction->delete();

                Log::info('Fawry transaction deleted', [
                    'fawry_transaction_id' => $transaction->id,
                    'deleted_by' => Auth::id(),
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
