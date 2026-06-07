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

                // total_amount: للإرسال العميل يدفع amount+fee، للاستقبال يأخذ amount-fee
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

                // Determine amount_paid
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

                [$incomeTransaction, $expenseTransaction] = match ($type) {
                    WalletTransactionType::Send => $this->accountForSend(
                        $record, $amount, $fee, $walletTypeName, $customerName, $createdBy
                    ),
                    WalletTransactionType::Receive => $this->accountForReceive(
                        $record, $amount, $fee, $walletTypeName, $customerName, $createdBy
                    ),
                };

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
        } catch (\Exception $e) {
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
                $transaction->update($data);

                return $transaction->fresh([
                    'walletType', 'customer', 'walletAccount', 'cashAccount',
                    'employee', 'createdBy', 'incomeTransaction', 'expenseTransaction',
                ]);
            });
        } catch (\Exception $e) {
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
        $totalAmount = $amount + $fee;
        $amountPaid = (float) $record->amount_paid;

        if ($record->customer_id) {
            $customerAccount = $this->ensureCustomerAccount((int) $record->customer_id);

            // 1. مديونية العميل بالقيمة الإجمالية
            $income = $this->transactionService->recordIncome([
                'amount' => $totalAmount,
                'to_account_id' => $customerAccount->id,
                'module' => TransactionModule::Wallet->value,
                'related_type' => WalletTransaction::class,
                'related_id' => $record->id,
                'notes' => "إرسال {$walletTypeName} - {$customerName}: مديونية إرسال رصيد بقيمة {$amount} + رسوم {$fee}",
                'created_by' => $createdBy,
            ]);

            // 2. خصم الرصيد من المحفظة
            $expense = $this->transactionService->recordExpense([
                'amount' => $amount,
                'from_account_id' => $record->wallet_account_id,
                'module' => TransactionModule::Wallet->value,
                'related_type' => WalletTransaction::class,
                'related_id' => $record->id,
                'notes' => "إرسال {$walletTypeName} - {$customerName}: خصم رصيد بقيمة {$amount} من المحفظة",
                'created_by' => $createdBy,
            ]);

            // 3. سداد جزء من المبلغ نقدياً (دفعة اليوم)
            if ($amountPaid > 0) {
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
        } else {
            // عميل سفري (نقدي فوري)
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
        }

        return [$income, $expense];
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
        $totalAmount = $amount - $fee;
        $amountPaid = (float) $record->amount_paid;

        if ($record->customer_id) {
            $customerAccount = $this->ensureCustomerAccount((int) $record->customer_id);

            // 1. زيادة الرصيد بمحفظتنا
            $income = $this->transactionService->recordIncome([
                'amount' => $amount,
                'to_account_id' => $record->wallet_account_id,
                'module' => TransactionModule::Wallet->value,
                'related_type' => WalletTransaction::class,
                'related_id' => $record->id,
                'notes' => "استقبال {$walletTypeName} - {$customerName}: استلام رصيد بقيمة {$amount} في المحفظة",
                'created_by' => $createdBy,
            ]);

            // 2. مستحق للعميل
            $expense = $this->transactionService->recordExpense([
                'amount' => $totalAmount,
                'from_account_id' => $customerAccount->id,
                'module' => TransactionModule::Wallet->value,
                'related_type' => WalletTransaction::class,
                'related_id' => $record->id,
                'notes' => "استقبال {$walletTypeName} - {$customerName}: مستحق للعميل بقيمة {$totalAmount} (صافي بعد رسوم {$fee})",
                'created_by' => $createdBy,
            ]);

            // 3. لو تم تسليم العميل كاش الآن
            if ($amountPaid > 0) {
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
        } else {
            // عميل سفري (نقدي فوري)
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
        }

        return [$income, $expense];
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
