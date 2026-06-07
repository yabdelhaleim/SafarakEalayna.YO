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

                $expenseTransactionId = null;
                if (! $machine && (float) $data['fawry_price'] > 0) {
                    $expenseTransaction = $this->transactionService->recordExpense([
                        'amount' => $data['fawry_price'],
                        'from_account_id' => $data['account_id'],
                        'module' => TransactionModule::Fawry->value,
                        'related_type' => FawryTransaction::class,
                        'related_id' => $fawryTransaction->id,
                        'notes' => "تكلفة عملية فوري - {$operationLabel}: {$clientName}",
                        'created_by' => $createdBy,
                    ]);
                    $expenseTransactionId = $expenseTransaction->id;
                }

                $incomeTransactionId = null;

                if (! empty($data['client_id'])) {
                    // Record sale to customer (Receivables / Debt)
                    $customerAccount = $this->ensureCustomerAccount((int) $data['client_id']);

                    // The sale income goes into customer account
                    $saleIncomeTransaction = $this->transactionService->recordIncome([
                        'amount' => $data['selling_price'],
                        'to_account_id' => $customerAccount->id,
                        'module' => TransactionModule::Fawry->value,
                        'related_type' => FawryTransaction::class,
                        'related_id' => $fawryTransaction->id,
                        'notes' => "تحصيل فوري (مديونية) - {$operationLabel}: {$clientName}",
                        'created_by' => $createdBy,
                    ]);
                    $incomeTransactionId = $saleIncomeTransaction->id;

                    // If they paid anything now:
                    if ((float) $data['amount'] > 0) {
                        $this->transactionService->recordIncome([
                            'amount' => $data['amount'],
                            'to_account_id' => $data['account_id'],
                            'contra_account_id' => $customerAccount->id,
                            'module' => TransactionModule::Fawry->value,
                            'related_type' => FawryTransaction::class,
                            'related_id' => $fawryTransaction->id,
                            'notes' => "سداد جزء من عملية فوري - {$operationLabel}: {$clientName}",
                            'created_by' => $createdBy,
                        ]);
                    }
                } else {
                    // Walk-in client: directly pays treasury account
                    $incomeTransaction = $this->transactionService->recordIncome([
                        'amount' => $data['selling_price'],
                        'to_account_id' => $data['account_id'],
                        'module' => TransactionModule::Fawry->value,
                        'related_type' => FawryTransaction::class,
                        'related_id' => $fawryTransaction->id,
                        'notes' => "تحصيل فوري - {$operationLabel}: {$clientName}",
                        'created_by' => $createdBy,
                    ]);
                    $incomeTransactionId = $incomeTransaction->id;
                }

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

    public function updateTransaction(FawryTransaction $transaction, array $data): FawryTransaction
    {
        try {
            return DB::transaction(function () use ($transaction, $data) {
                if (isset($data['selling_price']) || isset($data['fawry_price'])) {
                    $fawryPrice = $data['fawry_price'] ?? $transaction->fawry_price;
                    $sellingPrice = $data['selling_price'] ?? $transaction->selling_price;
                    $data['profit'] = $sellingPrice - $fawryPrice;
                }

                $transaction->update($data);

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
        } catch (\Throwable $e) {
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
                if ($transaction->expense_transaction_id) {
                    $this->transactionService->reverseTransaction($transaction->expenseTransaction);
                }

                if ($transaction->income_transaction_id) {
                    $this->transactionService->reverseTransaction($transaction->incomeTransaction);
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
                'module_type' => 'tourism',
                'is_module_vault' => false,
                'notes' => 'حساب تلقائي للعميل #'.$customer->id,
                'created_by' => Auth::id() ?? 1,
            ]);

            $customer->update(['account_id' => $account->id]);

            return $account;
        }));
    }
}
