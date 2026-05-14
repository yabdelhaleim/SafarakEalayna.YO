<?php

namespace App\Services\Wallet;

use App\Enums\TransactionModule;
use App\Enums\WalletTransactionType;
use App\Models\Wallet\WalletTransaction;
use App\Models\Wallet\WalletType;
use App\Services\Finance\TransactionService;
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
                $type    = $rawType instanceof WalletTransactionType
                    ? $rawType
                    : WalletTransactionType::from((string) $rawType);
                $amount     = (float) $data['amount'];
                $fee        = (float) ($data['service_fee'] ?? 0);

                // total_amount: للإرسال العميل يدفع amount+fee، للاستقبال يأخذ amount-fee
                $totalAmount = match ($type) {
                    WalletTransactionType::Send    => $amount + $fee,
                    WalletTransactionType::Receive => $amount - $fee,
                };

                // customer_name من العميل المرتبط أو من النص الحر
                $customerName = $data['customer_name'] ?? '';
                if (! empty($data['customer_id'])) {
                    $customer = \App\Models\Customer::find($data['customer_id']);
                    if ($customer) {
                        $customerName = $customer->full_name ?? $customer->name ?? $customerName;
                    }
                }

                $walletTypeName = WalletType::find($data['wallet_type_id'])?->name ?? '';
                $createdBy      = Auth::id() ?? ($data['created_by'] ?? 1);

                $record = WalletTransaction::create([
                    'wallet_type_id'  => $data['wallet_type_id'],
                    'customer_id'     => $data['customer_id'] ?? null,
                    'customer_name'   => $customerName,
                    'wallet_number'   => $data['wallet_number'],
                    'type'            => $type->value,
                    'amount'          => $amount,
                    'service_fee'     => $fee,
                    'total_amount'    => $totalAmount,
                    'wallet_account_id' => $data['wallet_account_id'],
                    'cash_account_id' => $data['cash_account_id'],
                    'employee_id'     => $data['employee_id'] ?? null,
                    'created_by'      => $createdBy,
                    'notes'           => $data['notes'] ?? null,
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
                    'income_transaction_id'  => $incomeTransaction->id,
                    'expense_transaction_id' => $expenseTransaction->id,
                ]);

                Log::info('WalletTransaction created', [
                    'id'            => $record->id,
                    'type'          => $type->value,
                    'amount'        => $amount,
                    'service_fee'   => $fee,
                    'customer_name' => $customerName,
                    'created_by'    => $createdBy,
                ]);

                return $record->fresh([
                    'walletType', 'customer', 'walletAccount', 'cashAccount',
                    'employee', 'createdBy', 'incomeTransaction', 'expenseTransaction',
                ]);
            });
        } catch (\Exception $e) {
            Log::error('WalletTransactionService::createTransaction failed', [
                'error'   => $e->getMessage(),
                'user_id' => Auth::id(),
                'input'   => $data,
            ]);
            throw $e;
        }
    }

    /**
     * إرسال رصيد للعميل:
     *   Income  → cash_account  (العميل دفع نقدي amount+fee)
     *   Expense → wallet_account (المحفظة قلت بالـ amount)
     */
    private function accountForSend(
        WalletTransaction $record,
        float $amount,
        float $fee,
        string $walletTypeName,
        string $customerName,
        int $createdBy
    ): array {
        $income = $this->transactionService->recordIncome([
            'amount'       => $amount + $fee,
            'to_account_id' => $record->cash_account_id,
            'module'       => TransactionModule::Wallet->value,
            'related_type' => WalletTransaction::class,
            'related_id'   => $record->id,
            'notes'        => "إرسال {$walletTypeName} - {$customerName}: استلام نقدي {$amount} + خدمة {$fee}",
            'created_by'   => $createdBy,
        ]);

        $expense = $this->transactionService->recordExpense([
            'amount'          => $amount,
            'from_account_id' => $record->wallet_account_id,
            'module'          => TransactionModule::Wallet->value,
            'related_type'    => WalletTransaction::class,
            'related_id'      => $record->id,
            'notes'           => "إرسال {$walletTypeName} - {$customerName}: خصم من المحفظة {$amount}",
            'created_by'      => $createdBy,
        ]);

        return [$income, $expense];
    }

    /**
     * استقبال رصيد من العميل:
     *   Income  → wallet_account (المحفظة زادت بالـ amount)
     *   Expense → cash_account   (دفعنا نقدي للعميل amount-fee)
     */
    private function accountForReceive(
        WalletTransaction $record,
        float $amount,
        float $fee,
        string $walletTypeName,
        string $customerName,
        int $createdBy
    ): array {
        $income = $this->transactionService->recordIncome([
            'amount'        => $amount,
            'to_account_id' => $record->wallet_account_id,
            'module'        => TransactionModule::Wallet->value,
            'related_type'  => WalletTransaction::class,
            'related_id'    => $record->id,
            'notes'         => "استقبال {$walletTypeName} - {$customerName}: استلام محفظة {$amount}",
            'created_by'    => $createdBy,
        ]);

        $cashOut = $amount - $fee;

        $expense = $this->transactionService->recordExpense([
            'amount'          => $cashOut,
            'from_account_id' => $record->cash_account_id,
            'module'          => TransactionModule::Wallet->value,
            'related_type'    => WalletTransaction::class,
            'related_id'      => $record->id,
            'notes'           => "استقبال {$walletTypeName} - {$customerName}: دفع نقدي {$cashOut}",
            'created_by'      => $createdBy,
        ]);

        return [$income, $expense];
    }

    public function updateTransaction(WalletTransaction $transaction, array $data): WalletTransaction
    {
        try {
            return DB::transaction(function () use ($transaction, $data) {
                $transaction->update([
                    'notes' => $data['notes'] ?? $transaction->notes,
                ]);

                Log::info('WalletTransaction updated', [
                    'id'         => $transaction->id,
                    'updated_by' => Auth::id(),
                ]);

                return $transaction->fresh([
                    'walletType', 'customer', 'walletAccount', 'cashAccount',
                    'employee', 'createdBy', 'incomeTransaction', 'expenseTransaction',
                ]);
            });
        } catch (\Exception $e) {
            Log::error('WalletTransactionService::updateTransaction failed', [
                'error'   => $e->getMessage(),
                'user_id' => Auth::id(),
                'id'      => $transaction->id,
            ]);
            throw $e;
        }
    }

    public function deleteTransaction(WalletTransaction $transaction): bool
    {
        try {
            return DB::transaction(function () use ($transaction) {
                if ($transaction->income_transaction_id && $transaction->incomeTransaction) {
                    $this->transactionService->reverseTransaction($transaction->incomeTransaction);
                }

                if ($transaction->expense_transaction_id && $transaction->expenseTransaction) {
                    $this->transactionService->reverseTransaction($transaction->expenseTransaction);
                }

                $transaction->delete();

                Log::info('WalletTransaction deleted', [
                    'id'         => $transaction->id,
                    'deleted_by' => Auth::id(),
                ]);

                return true;
            });
        } catch (\Exception $e) {
            Log::error('WalletTransactionService::deleteTransaction failed', [
                'error'   => $e->getMessage(),
                'user_id' => Auth::id(),
                'id'      => $transaction->id,
            ]);
            throw $e;
        }
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
            'total_transactions' => (int) ($result->total_transactions  ?? 0),
            'send_count'         => (int) ($result->send_count          ?? 0),
            'receive_count'      => (int) ($result->receive_count       ?? 0),
            'total_sent'         => (float) ($result->total_sent        ?? 0),
            'total_received'     => (float) ($result->total_received    ?? 0),
            'total_fees'         => (float) ($result->total_fees        ?? 0),
        ];
    }
}
